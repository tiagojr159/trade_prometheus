"""Build point-in-time price features and matured directional targets."""
from bisect import bisect_left
from collections import deque
import csv
from datetime import UTC, datetime, timedelta
from hashlib import sha256
from io import TextIOWrapper
import json
import math
from zipfile import ZipFile
from zoneinfo import ZoneInfo

from .archive import digest, month_range


HORIZONS = {"15m": 900, "1h": 3600, "4h": 14400, "24h": 86400}
NAMES = ["return_1_bps", "return_5_bps", "return_15_bps", "return_60_bps",
         "mean_range_15_bps", "relative_volume_15"]


def features(candles):
    price = candles[-1]["close_price"]
    output = [
        10000 * (price / candles[-1 - lag]["close_price"] - 1) if len(candles) > lag else None
        for lag in (1, 5, 15, 60)
    ]
    window = candles[-15:]
    volume = sum(row["volume"] for row in window)
    output.append(sum((row["high_price"] - row["low_price"]) / row["close_price"]
                      for row in window) * 10000 / 15 if len(window) == 15 else None)
    output.append(15 * window[-1]["volume"] / volume if len(window) == 15 and volume > 0 else None)
    return output


def time_at(value, timezone, expected_naive=True):
    instant = datetime.fromisoformat(value)
    if instant.tzinfo is None:
        if not expected_naive:
            raise ValueError("timestamp must include an offset")
        instant = instant.replace(tzinfo=timezone)
    return instant.astimezone(UTC)


def read_recorded(path):
    with path.open(encoding="utf-8-sig") as source:
        metadata = json.loads(next(source))
        if tuple(metadata[k] for k in ("source", "symbol", "market_type", "interval", "dataset_kind")) != (
            "binance", "BTCUSDT", "SPOT", "1m", "RECORDED_AS_OF"
        ):
            raise ValueError("invalid dataset provenance")
        timezone = ZoneInfo(metadata["legacy_timezone"]) if metadata["legacy_timezone"] != "America/Fortaleza" else (
            # Windows may lack IANA tzdata. This fallback only applies after the
            # last offset change in Fortaleza (2002).
            datetime.fromisoformat("2026-01-01T00:00:00-03:00").tzinfo
        )
        cutoff = time_at(metadata["exported_at_utc"], UTC, expected_naive=False)
        candles = {}
        for line in source:
            row = json.loads(line)
            quality = row["temporal_quality"]
            if quality not in {"EXACT", "INGESTION_ONLY"} or not row["ingested_at"]:
                continue
            if quality == "EXACT" and not row["available_at"]:
                continue
            opened = time_at(row["open_time"], timezone)
            closed = time_at(row["close_time"], timezone)
            received = time_at(row["ingested_at"], UTC)
            if metadata["legacy_timezone"] == "America/Fortaleza" and opened.year < 2003:
                raise ValueError("historical Fortaleza data requires explicit IANA conversion")
            if row["available_at"] and time_at(row["available_at"], UTC) > received:
                continue
            if closed != opened + timedelta(seconds=59) or not closed <= received <= cutoff:
                continue
            for key in ("open_price", "high_price", "low_price", "close_price", "volume"):
                row[key] = float(row[key])
                if not math.isfinite(row[key]):
                    raise ValueError("non-finite candle value")
            if (row["low_price"] <= 0 or row["volume"] < 0
                    or row["low_price"] > min(row["open_price"], row["close_price"])
                    or row["high_price"] < max(row["open_price"], row["close_price"])):
                continue
            if opened in candles:
                raise ValueError("duplicate v1 candle identity")
            candles[opened] = row | {"known": received, "opened": opened, "closed": closed}
    return candles, cutoff


def read_archive(path):
    """Read verified spot archives with one second of simulated publication lag."""
    sidecars = sorted(path.glob("BTCUSDT-1m-*.json"))
    if not sidecars:
        raise ValueError("no verified 1m spot archives found")
    metadata = [json.loads(file.read_text(encoding="utf-8")) for file in sidecars]
    months = [entry["month"] for entry in metadata]
    if months != list(month_range(months[0], months[-1])):
        raise ValueError("archive months must be contiguous")
    candles = {}
    manifest = sha256()
    for entry in metadata:
        month = entry["month"]
        filename = f"BTCUSDT-1m-{month}.zip"
        if (entry["market_type"], entry["symbol"], entry["interval"], entry["archive"], entry["dataset_kind"],
                entry["temporal_quality"]) != ("SPOT", "BTCUSDT", "1m", filename, "RECONSTRUCTED_RESEARCH",
                                               "SIMULATED_AVAILABILITY"):
            raise ValueError("research requires spot archives with simulated availability metadata")
        archive = path / filename
        if digest(archive) != entry["sha256"]:
            raise ValueError(f"invalid archive digest: {filename}")
        manifest.update(json.dumps(entry, sort_keys=True, separators=(",", ":")).encode())
        unit = 1_000_000 if month >= "2025-01" else 1000
        with ZipFile(archive) as zipped:
            internal = f"BTCUSDT-1m-{month}.csv"
            with zipped.open(internal) as raw:
                for row in csv.reader(TextIOWrapper(raw, encoding="utf-8")):
                    timestamp = int(row[0])
                    end = int(row[6])
                    if timestamp % (60 * unit) != 0 or end - timestamp != 60 * unit - 1:
                        raise ValueError(f"unexpected kline duration or timestamp unit: {filename}")
                    start = datetime.fromtimestamp(timestamp // unit, UTC)
                    if start.strftime("%Y-%m") != month or start in candles:
                        raise ValueError(f"out of month or duplicate candle: {filename}")
                    closed = start + timedelta(seconds=59)
                    opened_price, high, low, close, volume = map(float, row[1:6])
                    if (not all(math.isfinite(v) for v in (opened_price, high, low, close, volume)) or low <= 0
                            or low > min(opened_price, close) or high < max(opened_price, close) or volume < 0):
                        raise ValueError(f"invalid candle: {filename}")
                    candles[start] = {"opened": start, "closed": closed, "known": closed + timedelta(seconds=1),
                                      "high_price": high, "low_price": low, "close_price": close, "volume": volume}
    if not candles:
        raise ValueError("empty archive")
    return candles, candles[max(candles)]["known"], manifest.hexdigest()


def examples(candles, cutoff, horizon):
    if not candles:
        return []
    ordered = sorted(candles)
    arrivals = iter(sorted(candles.values(), key=lambda candle: (candle["known"], candle["opened"])))
    pending = next(arrivals, None)
    latest = None
    window = {}
    rows = []
    slot = ordered[0].replace(minute=ordered[0].minute // 5 * 5, second=0) + timedelta(seconds=55)
    horizon_delta = timedelta(seconds=HORIZONS[horizon])
    close_grace = timedelta(minutes=10)
    minute = timedelta(minutes=1)
    while slot + horizon_delta + close_grace <= cutoff and slot <= ordered[-1]:
        while pending is not None and pending["known"] <= slot:
            opening = pending["opened"]
            latest = opening if latest is None else max(opening, latest)
            window[opening] = pending
            pending = next(arrivals, None)
        if latest is not None:
            oldest = max(latest - 99 * minute, slot - 101 * minute)
            window = {opening: candle for opening, candle in window.items() if opening >= oldest}
        if latest in window and slot - window[latest]["closed"] <= 2 * minute:
            contiguous = deque()
            cursor = latest
            while cursor in window:
                contiguous.appendleft(window[cursor])
                cursor -= minute
            if len(contiguous) >= 16:
                target = slot + horizon_delta
                maturity = target + close_grace
                index = bisect_left(ordered, target)
                for opening in ordered[index:index + 3]:
                    candle = candles[opening]
                    if opening > target + 2 * minute:
                        break
                    if candle["known"] <= maturity:
                        close = contiguous[-1]["close_price"]
                        realized = candle["close_price"] / close - 1
                        if abs(realized) >= 1e-12:
                            rows.append({"as_of": slot, "mature": maturity, "label_start": contiguous[-1]["closed"],
                                         "label_end": candle["closed"],
                                         "x": features(list(contiguous)), "y": int(realized > 0),
                                         "return_bps": realized * 10000, "entry_reference": close,
                                         "exit_reference": candle["close_price"]})
                        break
        slot += timedelta(minutes=5)
    return rows
