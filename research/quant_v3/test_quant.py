import json
import os
from datetime import UTC, datetime, timedelta
from pathlib import Path
import shutil
import subprocess
import unittest

from .archive import month_range
from .dataset import NAMES, examples, features
from .train import magnitude, metrics, non_overlapping, partitions, sigmoid, transform, walk_forward_partitions


class QuantTests(unittest.TestCase):
    def test_partitions_purge_end_and_maturity(self):
        t0 = datetime(2026, 1, 1, tzinfo=UTC)
        rows = []
        for minute in range(200):
            t = t0 + timedelta(minutes=minute)
            rows.append({"as_of": t, "label_start": t - timedelta(minutes=1),
                         "label_end": t + timedelta(minutes=5), "mature": t + timedelta(minutes=10)})
        train_cutoff, calibration_cutoff, train, calibration, test = partitions(rows)
        self.assertTrue(all(row["mature"] < train_cutoff for row in train))
        self.assertTrue(all(row["as_of"] >= train_cutoff and row["mature"] < calibration_cutoff for row in calibration))
        self.assertTrue(all(row["as_of"] >= calibration_cutoff for row in test))
        separate = non_overlapping(test)
        self.assertTrue(all(before["label_end"] < after["label_start"]
                            for before, after in zip(separate, separate[1:])))
        folds = list(walk_forward_partitions(rows))
        self.assertEqual(len(folds), 3)
        self.assertTrue(all(row["mature"] < calibration_cutoff for fold in folds for row in fold[4]))
        self.assertTrue(all(left[4][-1]["mature"] < right[1] for left, right in zip(folds, folds[1:]) if left[4]))

    def test_future_ingestion_cannot_modify_previous_features(self):
        start = datetime(2026, 1, 1, tzinfo=UTC)
        candles = {}
        for i in range(120):
            opened = start + timedelta(minutes=i)
            closed = opened + timedelta(seconds=59)
            price = 100 + i
            candles[opened] = {"opened": opened, "closed": closed, "known": closed + timedelta(seconds=1),
                               "close_price": price, "high_price": price + 1, "low_price": price - 1, "volume": 1.0}
        cutoff = start + timedelta(hours=2)
        baseline = examples(candles, cutoff, "15m")
        self.assertTrue(baseline)
        late = start + timedelta(minutes=18)
        missing_until_later = candles[late] | {"known": cutoff + timedelta(minutes=1)}
        missing = {instant: row for instant, row in candles.items() if instant != late}
        self.assertEqual(examples(missing, cutoff, "15m"), examples(missing | {late: missing_until_later}, cutoff, "15m"))

    def test_metric_with_only_ties_and_empty_sample(self):
        self.assertIsNone(metrics([], []))
        result = metrics([0.5, 0.5], [0, 1])
        self.assertEqual(result["brier"], 0.25)
        self.assertEqual(result["directional_n"], 0)
        self.assertIsNone(result["accuracy"])

    def test_archive_months_and_wrap(self):
        self.assertEqual(list(month_range("2025-11", "2026-02")), ["2025-11", "2025-12", "2026-01", "2026-02"])
        with self.assertRaises(ValueError):
            list(month_range("2026-02", "2025-11"))

    def test_php_python_feature_and_inference_parity(self):
        php = os.environ.get("PROMETHEUS_TEST_PHP") or shutil.which("php")
        if not php:
            self.skipTest("set PROMETHEUS_TEST_PHP to the PHP CLI")
        artifact = {
            "feature_schema_version": 1, "market_type": "SPOT", "symbol": "BTCUSDT", "horizon": "15m",
            "dataset_kind": "RECORDED_AS_OF", "dataset_sha256": "a" * 64,
            "training_cutoff_utc": "2026-01-01T00:00:00Z", "calibration_cutoff_utc": "2026-02-01T00:00:00Z",
            "features": NAMES, "impute": [1.0] * 6, "mean": [2.0] * 6, "scale": [1, 10, 20, 30, 40, 50],
            "weights": [.02, -.03, .04, .01, .06, .1], "missing_weights": [.1, .2, 0, -.1, .01, .12],
            "intercept": -.1, "platt_a": .8, "platt_b": .2,
            "magnitude_baseline": {
                "up": {"mean_bps": 30, "deciles_bps": [i ** 2 for i in range(1, 12)]},
                "down": {"mean_bps": -30, "deciles_bps": [-i ** 2 for i in range(11, 0, -1)]},
            },
        }
        rows = [{"close_price": 100000 + i, "high_price": 100010 + i,
                 "low_price": 99990 + i, "volume": 0.0} for i in range(20)]
        datasets = [{"candles": rows}, {"features": dict(zip(NAMES, [None, 0, 100, -100, 0, 2]))},
                    {"features": dict(zip(NAMES, [1e20, 0, 0, 0, 0, 0]))}]
        runner = Path(__file__).with_name("inference_fixture.php")
        for fixture in datasets:
            values = features(fixture["candles"]) if "candles" in fixture else list(fixture["features"].values())
            run = subprocess.run([php, str(runner)], input=json.dumps(fixture | {"model": artifact}),
                                 capture_output=True, text=True, check=True)
            actual = json.loads(run.stdout)
            for expected, value in zip(values, actual["features"].values()):
                if expected is None:
                    self.assertIsNone(value)
                else:
                    self.assertAlmostEqual(expected, value, places=12)
            matrix = transform(values, artifact["impute"], artifact["mean"], artifact["scale"])
            logit = artifact["intercept"] + sum(
                value * weight for value, weight in zip(matrix, artifact["weights"] + artifact["missing_weights"]))
            self.assertAlmostEqual(sigmoid(artifact["platt_a"] * logit + artifact["platt_b"]),
                                   actual["probability"], places=12)
            expectancy, quantiles = magnitude(actual["probability"], artifact["magnitude_baseline"])
            self.assertAlmostEqual(expectancy, actual["magnitude"][0], places=12)
            for expected, value in zip(quantiles, actual["magnitude"][1].values()):
                self.assertAlmostEqual(expected, value, places=12)
