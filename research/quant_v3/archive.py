"""Cache official Binance monthly archives with verified checksums and provenance."""
import argparse
from datetime import UTC, datetime
from hashlib import file_digest
from http.client import IncompleteRead
import json
from pathlib import Path
import random
import re
import shutil
import time
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


BASE = "https://data.binance.vision/data"


def month_range(first, last):
    if any(not re.fullmatch(r"\d{4}-(0[1-9]|1[0-2])", value) for value in (first, last)) or first > last:
        raise ValueError("use a chronological YYYY-MM range")
    year, month = map(int, first.split("-"))
    while (key := f"{year:04d}-{month:02d}") <= last:
        yield key
        if month == 12:
            year += 1
            month = 0
        month += 1


def fetch(url, handler, headers=None):
    for attempt in range(4):
        try:
            with urlopen(Request(url, headers=headers or {}), timeout=20) as response:
                return handler(response)
        except (HTTPError, URLError, TimeoutError, ConnectionError, IncompleteRead) as error:
            if attempt == 3 or isinstance(error, HTTPError) and error.code not in {429, 500, 502, 503, 504}:
                raise
            retry = (error.headers.get("Retry-After") or "") if isinstance(error, HTTPError) else ""
            wait = min(30, int(retry)) if retry.isdigit() else 2 ** attempt
            time.sleep(wait + random.random())


def digest(path):
    with path.open("rb") as data:
        return file_digest(data, "sha256").hexdigest()


def cache_month(root, market, interval, month):
    segment = {"spot": "spot", "perpetual": "futures/um"}[market]
    name = f"BTCUSDT-{interval}-{month}.zip"
    url = f"{BASE}/{segment}/monthly/klines/BTCUSDT/{interval}/{name}"
    path = root / market / interval / name
    path.parent.mkdir(parents=True, exist_ok=True)
    receipt = path.with_suffix(".json")
    if path.is_file() and receipt.is_file():
        metadata = json.loads(receipt.read_text(encoding="utf-8"))
        if metadata.get("url") == url and metadata.get("sha256") == digest(path):
            return metadata
    record = fetch(url + ".CHECKSUM", lambda response: response.read(1024).decode("ascii"))
    parts = record.split()
    if len(parts) != 2 or parts[1] != name or not re.fullmatch(r"[a-fA-F0-9]{64}", parts[0]):
        raise ValueError(f"invalid upstream checksum for {name}")
    expected = parts[0].lower()
    partial = path.with_suffix(".zip.partial")
    offset = partial.stat().st_size if partial.is_file() else 0

    def save(response):
        resumed = response.status == 206
        if resumed and not (response.headers.get("Content-Range") or "").startswith(f"bytes {offset}-"):
            raise ValueError("unexpected range response")
        with partial.open("r+b" if resumed else "wb") as output:
            if resumed:
                output.seek(offset)
                output.truncate()
            shutil.copyfileobj(response, output, length=256 * 1024)

    if not partial.is_file() or digest(partial) != expected:
        try:
            fetch(url, save, {"Range": f"bytes={offset}-"} if offset else None)
        except HTTPError as error:
            if error.code != 416:
                raise
            offset = 0
            fetch(url, save)
    if digest(partial) != expected:
        # A truncated or mismatched local file must never become a cache hit.
        partial.unlink()
        raise ValueError(f"checksum mismatch for {name}")
    partial.replace(path)
    metadata = {
        "url": url, "sha256": expected, "archive": name, "symbol": "BTCUSDT",
        "market_type": {"spot": "SPOT", "perpetual": "USD_M_PERPETUAL"}[market],
        "interval": interval, "month": month,
        "dataset_kind": "RECONSTRUCTED_RESEARCH", "temporal_quality": "SIMULATED_AVAILABILITY",
        "downloaded_at_utc": datetime.now(UTC).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    receipt.write_text(json.dumps(metadata, indent=2) + "\n", encoding="utf-8")
    return metadata


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("root", type=Path)
    parser.add_argument("market", choices=["spot", "perpetual"])
    parser.add_argument("first_month")
    parser.add_argument("last_month")
    parser.add_argument("--interval", choices=["1m", "5m", "15m"], default="1m")
    args = parser.parse_args()
    for month in month_range(args.first_month, args.last_month):
        metadata = cache_month(args.root, args.market, args.interval, month)
        print(f'{month} {metadata["market_type"]} {metadata["sha256"]}', flush=True)
        time.sleep(.2)


if __name__ == "__main__":
    main()
