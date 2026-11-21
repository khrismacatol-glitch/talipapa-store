"""Stage 1: snapshot, blank labels, paraphrase-safe splits, eval skip."""
from __future__ import annotations

import csv
import json
import numpy as np
import pytest

from semantic.data import MODELS, ROOT, load_queries, normalize_query
from semantic.held_out import held_out_norms
from semantic.price_exclude import is_price_query, load_excluded_queries
from semantic.metrics import binary_rates, binary_topk_counts, ndcg_at_k, precision_at_k
from semantic.query_splits import assign_splits, cluster_queries, splits_leak
from semantic.ranker import LTR_WEIGHT, TEXT_WEIGHT

EXPERIMENT = ROOT / "experiments" / "cebu_ca_hyltr"


def test_binary_confusion_actual_vs_predicted():
    grades = {"a": 2, "b": 2, "c": 0, "d": 1, "e": 0}
    ranked = ["a", "c", "e", "b", "d"]
    counts = binary_topk_counts(ranked, grades, k=3)
    assert counts == {"TP": 1, "FP": 2, "FN": 1, "TN": 1}
    rates = binary_rates(counts)
    assert rates["precision"] == pytest.approx(1 / 3)
    assert rates["recall"] == pytest.approx(1 / 2)


def test_paraphrase_clusters_share_a_split():
    queries = [
        {"query": "gatas nga powder", "intent": "powder"},
        {"query": "palit kog gatas nga powder", "intent": "powder"},
        {"query": "presko nga isda", "intent": "other"},
        {"query": "gatas nga soya pero dili chocolate", "intent": "cow"},
        {"query": "gatas nga low fat pero dili powder", "intent": "low_fat"},
        {"query": "gatas nga organic ug walay sugar", "intent": "organic"},
        {"query": "gatas para sa kape pero dili tam-is", "intent": "cow"},
        {"query": "gatas nga presko", "intent": "cow"},
    ]
    emb = np.eye(len(queries), dtype=np.float64)
    labels = cluster_queries([q["query"] for q in queries], emb)
    assert labels[0] == labels[1]
    assert labels[0] != labels[2]
    splits = assign_splits(queries, labels)
    assert splits[0] == splits[1]
    assert not splits_leak(queries, labels, splits)
    assert splits[queries.index(next(q for q in queries if "soya" in q["query"]))] == "test"
    assert splits[queries.index(next(q for q in queries if "low fat" in q["query"]))] == "val"


def test_labeling_sheet_does_not_prefill_grades():
    path = EXPERIMENT / "labeling_sheet.csv"
    if not path.exists():
        pytest.skip("run prepare_cebu_ca_stage1.py first")
    with path.open(encoding="utf-8", newline="") as fh:
        rows = list(csv.DictReader(fh))
    assert rows
    assert all(row["grade"] in {"", "0", "1", "2"} for row in rows)
    assert "product_key" in rows[0]


def test_snapshot_does_not_replace_production_ranker():
    snap = EXPERIMENT / "baseline_snapshot"
    if not (snap / "formula.json").exists():
        pytest.skip("run prepare_cebu_ca_stage1.py first")
    formula = json.loads((snap / "formula.json").read_text(encoding="utf-8"))
    assert formula["ltr_weight"] == LTR_WEIGHT
    assert formula["text_weight"] == TEXT_WEIGHT
    assert formula["production_models_untouched"] is True
    prod = MODELS / "ranker.txt"
    frozen = snap / "ranker.txt"
    assert prod.exists() and frozen.exists()
    assert prod.read_bytes() == frozen.read_bytes()


def test_eval_skips_incomplete_labels(tmp_path, monkeypatch):
    import evaluate_cebu_ca as ev

    products = [{"key": "a", "name": "A", "description": "", "category": "", "specs": None}]
    (tmp_path / "products.json").write_text(json.dumps(products), encoding="utf-8")
    with (tmp_path / "splits.csv").open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=["query", "split"])
        w.writeheader()
        w.writerow({"query": "gatas", "split": "val"})
    with (tmp_path / "labeling_sheet.csv").open("w", encoding="utf-8", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=["query", "product_key", "grade"])
        w.writeheader()
        w.writerow({"query": "gatas", "product_key": "a", "grade": ""})

    monkeypatch.setattr(ev, "EXPERIMENT", tmp_path)
    monkeypatch.setattr("sys.argv", ["evaluate_cebu_ca.py", "--split", "val", "--sheet", str(tmp_path / "labeling_sheet.csv")])
    assert ev.main() == 0
    report = json.loads((tmp_path / "reports" / "baseline_val.json").read_text(encoding="utf-8"))
    assert report["status"] == "labels_incomplete"
    assert report["queries_fully_labeled"] == 0
    assert report["systems"] == {}


def test_held_out_test_requires_flag(monkeypatch):
    import evaluate_cebu_ca as ev

    monkeypatch.setattr("sys.argv", ["evaluate_cebu_ca.py", "--split", "test"])
    assert ev.main() == 2


def test_held_out_queries_are_excluded_from_production_train_split():
    blocked = held_out_norms()
    if not blocked:
        pytest.skip("run prepare_cebu_ca_stage1.py first")
    train_kept = {
        normalize_query(q["query"])
        for q in load_queries()
        if q["split"] == "train" and normalize_query(q["query"]) not in blocked
    }
    assert train_kept
    assert blocked.isdisjoint(train_kept)
    assert normalize_query("gatas nga soya pero dili chocolate") in blocked


def test_price_queries_are_excluded_from_experiment_splits():
    assert is_price_query("barato na bugas")
    assert is_price_query("Gatas nga dili molapas 100")
    assert not is_price_query("gatas nga low fat")
    blocked = load_excluded_queries()
    if not blocked:
        pytest.skip("excluded_price_queries.csv missing")
    path = EXPERIMENT / "splits.csv"
    if not path.exists():
        pytest.skip("splits missing")
    with path.open(encoding="utf-8", newline="") as fh:
        names = {row["query"] for row in csv.DictReader(fh)}
    assert blocked.isdisjoint(names)


def test_ranking_helpers_on_known_list():
    grades = {"a": 2, "b": 0, "c": 2}
    assert precision_at_k(["a", "b", "c"], grades, k=2) == pytest.approx(0.5)
    assert ndcg_at_k(["a", "c"], grades, k=2) == pytest.approx(1.0)
