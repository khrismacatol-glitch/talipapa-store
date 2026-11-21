"""One-shot held-out test eval for frozen experimental Cebu-CA-HyLTR models.

Does not retrain, tune, or write production files.
"""
from __future__ import annotations

import json

import lightgbm as lgb
import numpy as np

from evaluate_cebu_ca import rank_bm25, rank_hybrid, rank_labse
from semantic.cebu_ca_features import catalog_brands
from semantic.data import ROOT, is_milk, load_products, normalize_query, product_text
from semantic.price_exclude import load_excluded_queries
from semantic.ranker import SemanticRanker
from train_cebu_ca import (
    EXPERIMENT,
    OUT_MODELS,
    SNAPSHOT,
    build_xy,
    load_grades,
    load_splits,
    split_complete,
    system_block,
)

BEST_ITERATION = 39


def booster_rank(booster: lgb.Booster, X: np.ndarray, keys: list[str]) -> list[str]:
    scores = booster.predict(X, num_iteration=BEST_ITERATION)
    order = np.argsort(-scores, kind="stable")
    return [keys[i] for i in order]


def main() -> int:
    products = load_products(EXPERIMENT / "products.json")
    keys = [p["key"] for p in products]
    milk_keys = {p["key"] for p in products if is_milk(p)}
    split_of = load_splits()
    excluded = load_excluded_queries()
    test_grades = split_complete(
        load_grades(EXPERIMENT / "labeling_sheet.csv"),
        keys, split_of, "test", excluded,
    )
    if len(test_grades) != 100:
        raise SystemExit(f"expected 100 fully labeled test queries, got {len(test_grades)}")

    test_qs = sorted(test_grades, key=lambda q: normalize_query(q))
    ranker = SemanticRanker(models_dir=SNAPSHOT)
    p_emb = ranker.embedder.encode([product_text(p) for p in products])
    name_emb = ranker.embedder.encode([p.get("name") or "" for p in products])
    desc_emb = ranker.embedder.encode([p.get("description") or "" for p in products])
    cat_emb = ranker.embedder.encode([p.get("category") or "" for p in products])
    p_milk = ranker.milk_clf.predict_proba(p_emb)[:, 1]
    brands = catalog_brands(products)

    X_cebu, _, _ = build_xy(
        test_qs, test_grades, products, ranker, p_emb, name_emb, desc_emb, cat_emb, p_milk, brands, True,
    )
    X_ab, _, _ = build_xy(
        test_qs, test_grades, products, ranker, p_emb, name_emb, desc_emb, cat_emb, p_milk, brands, False,
    )
    cebu_booster = lgb.Booster(model_file=str(OUT_MODELS / "cebu_ca_hyltr" / "ranker.txt"))
    ab_booster = lgb.Booster(model_file=str(OUT_MODELS / "ltr_ablation" / "ranker.txt"))

    n = len(products)
    runs = {
        "bm25": {q: rank_bm25(q, products) for q in test_qs},
        "labse": {q: rank_labse(q, products, ranker) for q in test_qs},
        "hybrid_current": {q: rank_hybrid(q, products, ranker) for q in test_qs},
        "ltr_ablation": {},
        "cebu_ca_hyltr": {},
    }
    for i, query in enumerate(test_qs):
        sl = slice(i * n, (i + 1) * n)
        runs["cebu_ca_hyltr"][query] = booster_rank(cebu_booster, X_cebu[sl], keys)
        runs["ltr_ablation"][query] = booster_rank(ab_booster, X_ab[sl], keys)

    systems = {name: system_block(run, test_grades, milk_keys) for name, run in runs.items()}
    report = {
        "split": "test",
        "held_out_ok": True,
        "one_shot": True,
        "retrained": False,
        "hyperparameters_tuned_on_test": False,
        "model_selected_on_test": False,
        "best_iteration_frozen": BEST_ITERATION,
        "queries_fully_labeled": len(test_qs),
        "catalog_size": n,
        "production_untouched": True,
        "ndcg_reported": "Thesis DCG (2^grade-1) on raw grades 0/1/2; binary uses grade>=2",
        "systems": systems,
        "cebu_ca_hyltr_beats_hybrid_ndcg": (
            systems["cebu_ca_hyltr"]["ranking"]["NDCG@5"] > systems["hybrid_current"]["ranking"]["NDCG@5"]
        ),
        "cebu_ca_hyltr_beats_ablation_ndcg": (
            systems["cebu_ca_hyltr"]["ranking"]["NDCG@5"] > systems["ltr_ablation"]["ranking"]["NDCG@5"]
        ),
        "cebu_ca_hyltr_beats_labse_ndcg": (
            systems["cebu_ca_hyltr"]["ranking"]["NDCG@5"] > systems["labse"]["ranking"]["NDCG@5"]
        ),
        "cebu_ca_hyltr_beats_bm25_ndcg": (
            systems["cebu_ca_hyltr"]["ranking"]["NDCG@5"] > systems["bm25"]["ranking"]["NDCG@5"]
        ),
    }
    out = EXPERIMENT / "reports" / "ltr_test.json"
    out.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(json.dumps({
        "saved": str(out),
        "n": len(test_qs),
        "systems": {
            name: {
                **block["ranking"],
                **{k: block["binary_top5_micro"][k] for k in (
                    "accuracy", "precision", "recall", "f1", "TP", "FP", "FN", "TN",
                )},
            }
            for name, block in systems.items()
        },
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
