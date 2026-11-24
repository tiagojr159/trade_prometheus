"""Chronological linear baseline with an independent Platt calibration block."""
import argparse
from hashlib import sha256
import json
from math import ceil, exp, floor, log
from pathlib import Path
from statistics import mean, median, pstdev

from .dataset import HORIZONS, NAMES, examples, read_archive, read_recorded


def sigmoid(value):
    if value >= 0:
        return 1 / (1 + exp(-value))
    numerator = exp(value)
    return numerator / (1 + numerator)


def fit(matrix, labels, iterations=400, l2=0.01):
    weights = [0.0] * len(matrix[0])
    intercept = 0.0
    for iteration in range(iterations):
        gradients = [0.0] * len(weights)
        offset = 0.0
        for row, label in zip(matrix, labels):
            residual = sigmoid(intercept + sum(x * w for x, w in zip(row, weights))) - label
            offset += residual
            for i, value in enumerate(row):
                gradients[i] += residual * value
        rate = 0.25 / (1 + iteration / 100)
        intercept -= rate * offset / len(labels)
        weights = [
            w - rate * (g / len(labels) + l2 * w) for w, g in zip(weights, gradients)
        ]
    return intercept, weights


def transform(values, impute, center, scale):
    numeric = [
        ((fallback if value is None else value) - avg) / std
        for value, fallback, avg, std in zip(values, impute, center, scale)
    ]
    return numeric + [float(value is None) for value in values]


def partitions(rows):
    if len(rows) < 100:
        raise ValueError("insufficient observable labels")
    start = rows[0]["as_of"]
    duration = rows[-1]["as_of"] - start
    training_cutoff = (start + duration * .55).replace(microsecond=0)
    calibration_cutoff = (start + duration * .75).replace(microsecond=0)
    train = [row for row in rows if row["mature"] < training_cutoff]
    calibrate = [row for row in rows if row["as_of"] >= training_cutoff and row["mature"] < calibration_cutoff]
    test = [row for row in rows if row["as_of"] >= calibration_cutoff]
    return training_cutoff, calibration_cutoff, train, calibrate, test


def walk_forward_partitions(rows):
    """Anchored development folds contained strictly before the final holdout."""
    _, holdout_start, _, _, _ = partitions(rows)
    start = rows[0]["as_of"]
    duration = holdout_start - start
    ranges = ((.40, .55), (.55, .70), (.70, .90))
    for first, last in ranges:
        validation_start = (start + duration * first).replace(microsecond=0)
        validation_end = (start + duration * last).replace(microsecond=0)
        training_cutoff = (start + duration * first * .75).replace(microsecond=0)
        train = [row for row in rows if row["mature"] < training_cutoff]
        calibrate = [row for row in rows if row["as_of"] >= training_cutoff and row["mature"] < validation_start]
        test = [row for row in rows if row["as_of"] >= validation_start and row["mature"] < validation_end]
        yield training_cutoff, validation_start, train, calibrate, test


def non_overlapping(rows):
    output = []
    for row in rows:
        if not output or row["label_start"] > output[-1]["label_end"]:
            output.append(row)
    return output


def metrics(probabilities, labels):
    if not labels:
        return None
    classifications = [(score > 0.5, label) for score, label in zip(probabilities, labels)
                       if abs(2 * score - 1) >= 1e-12]
    clipped = [min(1 - 1e-9, max(1e-9, score)) for score in probabilities]
    return {
        "n": len(labels), "directional_n": len(classifications),
        "accuracy": mean(predicted == actual for predicted, actual in classifications) if classifications else None,
        "brier": mean((score - label) ** 2 for score, label in zip(probabilities, labels)),
        "log_loss": mean(-(label * log(score) + (1 - label) * log(1 - score))
                         for score, label in zip(clipped, labels)),
        "up_prevalence": mean(labels),
    }


def cost_scenarios(rows, probability):
    """Illustrative spot exposure on close references with 25% allocation."""
    rows = non_overlapping(rows)
    result = {}
    for multiple in (0, 1, 1.5, 2):
        cost = 24 * multiple
        equity = 100.0
        peak = equity
        max_drawdown = 0.0
        total_cost = 0.0
        turnover = 0.0
        trades = 0
        for row in rows:
            if probability(row) <= 0.5:
                continue
            trades += 1
            notional = 0.25 * equity
            turnover += 2 * notional
            total_cost += notional * cost / 10000
            equity += notional * (row["return_bps"] - cost) / 10000
            peak = max(peak, equity)
            max_drawdown = max(max_drawdown, 1 - equity / peak)
        benchmark = rows[-1]["exit_reference"] / rows[0]["entry_reference"] - 1
        result[str(multiple)] = {
            "trades": trades, "net_pnl_usdt": equity - 100, "estimated_cost_usdt": total_cost,
            "turnover_usdt": turnover, "max_drawdown_on_close": max_drawdown,
            "cash_pnl_usdt": 0.0, "buy_and_hold_pnl_usdt": 100 * benchmark - cost / 100,
        }
    return result


def magnitude_baseline(train):
    calibration = {}
    for label, key in ((0, "down"), (1, "up")):
        values = sorted(row["return_bps"] for row in train if row["y"] == label)
        calibration[key] = {
            "mean_bps": mean(values),
            "deciles_bps": [values[round(i * (len(values) - 1) / 10)] for i in range(11)],
        }
    return calibration


def magnitude(probability_up, distribution):
    probability_down = 1 - probability_up
    expectation = sum(probability * distribution[key]["mean_bps"]
                      for key, probability in (("up", probability_up), ("down", probability_down)))
    quantiles = []
    for q in (.1, .5, .9):
        is_down = q <= probability_down
        conditional = q / probability_down if is_down else (q - probability_down) / probability_up
        index = min(10, max(0, 10 * conditional))
        deciles = distribution["down" if is_down else "up"]["deciles_bps"]
        lower, upper = floor(index), ceil(index)
        quantiles.append(deciles[lower] + (index - lower) * (deciles[upper] - deciles[lower]))
    return expectation, quantiles


def magnitude_metrics(rows, scores, distribution):
    absolute = []
    interval = []
    losses = [[], [], []]
    for row, probability in zip(rows, scores):
        expected, quantiles = magnitude(probability, distribution)
        realized = row["return_bps"]
        absolute.append(abs(expected - realized))
        interval.append(quantiles[0] <= realized <= quantiles[2])
        for i, (q, value) in enumerate(zip((.1, .5, .9), quantiles)):
            error = realized - value
            losses[i].append(max(q * error, (q - 1) * error))
    return {"mae_bps": mean(absolute), "interval_10_90_coverage": mean(interval),
            "pinball_loss_bps": dict(zip(("q10", "q50", "q90"), map(mean, losses)))}


def train_horizon(rows, horizon, dataset_sha, dataset_kind="RECORDED_AS_OF", split=None):
    training_cutoff, calibration_cutoff, train, calibration, test = split or partitions(rows)
    support = [len(non_overlapping(block)) for block in (train, calibration, test)]
    if not test or len(train) < 30 or len(calibration) < 30 or min(support[:2]) < 10 or any(
        len({row["y"] for row in block}) != 2 for block in (train, calibration)
    ):
        raise ValueError(f"insufficient disjoint train or calibration support: {support}")
    fit_train, fit_calibration = non_overlapping(train), non_overlapping(calibration)
    if any(len({row["y"] for row in block}) != 2 for block in (fit_train, fit_calibration)):
        raise ValueError("the non-overlapping train or calibration block has a single class")
    columns = [[row["x"][i] for row in fit_train if row["x"][i] is not None] for i in range(len(NAMES))]
    impute = [median(column) if column else 0.0 for column in columns]
    imputed = [[fallback if x is None else x for x, fallback in zip(row["x"], impute)] for row in fit_train]
    center = [mean(column) for column in zip(*imputed)]
    scale = [pstdev(column) or 1.0 for column in zip(*imputed)]

    def inputs(row):
        return transform(row["x"], impute, center, scale)

    train_labels = [row["y"] for row in fit_train]
    intercept, weights = fit([inputs(row) for row in fit_train], train_labels)

    def logit(row):
        return intercept + sum(value * weight for value, weight in zip(inputs(row), weights))

    platt_b, (platt_a,) = fit([[logit(row)] for row in fit_calibration], [row["y"] for row in fit_calibration])
    test_labels = [row["y"] for row in test]
    prevalence = mean(train_labels)
    def calibrated(row):
        return sigmoid(platt_a * logit(row) + platt_b)

    scores = [calibrated(row) for row in test]
    distribution = magnitude_baseline(fit_train)
    report = {
        "status": "INCONCLUSIVE" if support[-1] < 30 else "DESCRIPTIVE",
        "train_calibration_test": [len(train), len(calibration), len(test)],
        "non_overlapping_counts": support,
        "calibrated": metrics(scores, test_labels),
        "uncalibrated": metrics([sigmoid(logit(row)) for row in test], test_labels),
        "constant_frequency": metrics([prevalence] * len(test), test_labels),
        "majority_class": metrics([float(prevalence > 0.5)] * len(test), test_labels),
        "coin": metrics([0.5] * len(test), test_labels),
        "magnitude_baseline": magnitude_metrics(test, scores, distribution),
        "test_start_utc": test[0]["as_of"].isoformat(),
        "test_end_utc": test[-1]["as_of"].isoformat(),
        "reference_price_scenarios": cost_scenarios(test, calibrated),
        "trend_reference_scenarios": cost_scenarios(test, lambda row: float((row["x"][3] or 0) > 0)),
    }
    model = {
        "feature_schema_version": 1, "market_type": "SPOT", "symbol": "BTCUSDT", "horizon": horizon,
        "dataset_kind": dataset_kind, "dataset_sha256": dataset_sha,
        "training_cutoff_utc": training_cutoff.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "calibration_cutoff_utc": calibration_cutoff.strftime("%Y-%m-%dT%H:%M:%SZ"),
        "features": NAMES, "impute": impute, "mean": center, "scale": scale,
        "intercept": intercept, "weights": weights[:len(NAMES)], "missing_weights": weights[len(NAMES):],
        "platt_a": platt_a, "platt_b": platt_b,
        "magnitude_baseline": distribution,
    }
    return model, report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("dataset", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("--archive", action="store_true")
    parser.add_argument("--walk-forward", action="store_true", help="score development folds without reusing the final holdout")
    args = parser.parse_args()
    if args.archive:
        candles, cutoff, dataset_sha = read_archive(args.dataset)
        dataset_kind = "RECONSTRUCTED_RESEARCH"
    else:
        with args.dataset.open("rb") as source:
            digest = sha256()
            for chunk in iter(lambda: source.read(1024 * 1024), b""):
                digest.update(chunk)
        candles, cutoff = read_recorded(args.dataset)
        dataset_sha = digest.hexdigest()
        dataset_kind = "RECORDED_AS_OF"
    args.output.mkdir(parents=True, exist_ok=False)
    report = {"dataset_sha256": dataset_sha, "dataset_kind": dataset_kind, "eligible_candles": len(candles), "horizons": {}}
    for horizon in HORIZONS:
        trial = {"kind": "L2_LINEAR_PLATT", "horizon": horizon, "dataset_sha256": dataset_sha,
                 "version": 1, "split_pct": [55, 75], "iterations": 400, "l2": 0.01, "walk_forward": args.walk_forward}
        trial_id = sha256(json.dumps(trial, sort_keys=True).encode()).hexdigest()
        with (args.output / "trials.ndjson").open("a", encoding="utf-8") as trials:
            trials.write(json.dumps({"trial_id": trial_id, "status": "REGISTERED", "policy": trial}) + "\n")
        development = []
        try:
            horizon_rows = examples(candles, cutoff, horizon)
            if args.walk_forward:
                for index, split in enumerate(walk_forward_partitions(horizon_rows), start=1):
                    try:
                        _, fold = train_horizon(horizon_rows, horizon, dataset_sha, dataset_kind, split=split)
                    except ValueError as error:
                        fold = {"status": "INCONCLUSIVE", "reason": str(error)}
                    development.append({"fold": index, "validation_start_utc": split[1].isoformat()} | fold)
            model, result = train_horizon(horizon_rows, horizon, dataset_sha, dataset_kind)
            (args.output / f"linear-{horizon}.json").write_text(json.dumps(model, allow_nan=False), encoding="utf-8")
        except ValueError as error:
            result = {"status": "INCONCLUSIVE", "reason": str(error)}
        if args.walk_forward:
            result["development_folds"] = development
        with (args.output / "trials.ndjson").open("a", encoding="utf-8") as trials:
            trials.write(json.dumps({"trial_id": trial_id, "status": result["status"]}) + "\n")
        report["horizons"][horizon] = result
    (args.output / "report.json").write_text(json.dumps(report, indent=2, allow_nan=False), encoding="utf-8")
    print(json.dumps(report, indent=2, allow_nan=False))


if __name__ == "__main__":
    main()
