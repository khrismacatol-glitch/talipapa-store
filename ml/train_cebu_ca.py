"""Train experimental Cebu-CA-HyLTR LambdaMART. Experiment folder only.

Does not write ml/models, does not score the held-out test split, does not
retrain frozen LaBSE/intent/milk snapshot classifiers.
"""
from __future__ import annotations

import csv
import json
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

import lightgbm as lgb
import numpy as np

from evaluate_cebu_ca import (
    complete_queries,
    confusion_block,
    rank_bm25,
    rank_hybrid,
    rank_labse,
)
from semantic.cebu_ca_features import catalog_brands, feature_row
from semantic.data import ROOT, is_milk, load_products, normalize_query, product_text
from semantic.metrics import add_confusion, binary_topk_counts, evaluate_run, macro
from semantic.price_exclude import load_excluded_queries
from semantic.ranker import (
    EXTENDED_FEATURES,
    SemanticRanker,
    build_extended_features,
)

EXPERIMENT = ROOT / "experiments" / "cebu_ca_hyltr"
SNAPSHOT = EXPERIMENT / "baseline_snapshot"
OUT_MODELS = EXPERIMENT / "models"
SEED = 13
CEBU_FEATURES = ["attr_match", "neg_violate", "brand_match", "form_match", "qty_match"]
MAX_TREES = 300
PATIENCE = 30


def load_splits() -> dict[str, str]:
    out = {}
    with (EXPERIMENT / "splits.csv").open(encoding="utf-8-sig", newline="") as fh:
        for row in csv.DictReader(fh):
            out[row["query"]] = row["split"]
    return out


def load_grades(path: Path) -> dict[str, dict[str, int | None]]:
    grades: dict[str, dict[str, int | None]] = defaultdict(dict)
    with path.open(encoding="utf-8-sig", newline="") as fh:
        for row in csv.DictReader(fh):
            raw = str(row.get("grade") or "").strip()
            key = (row.get("product_key") or "").strip()
            query = (row.get("query") or "").strip()
            if not query or not key:
                continue
            grades[query][key] = int(raw) if raw != "" else None
    return dict(grades)


def split_complete(
    grades: dict[str, dict[str, int | None]],
    keys: list[str],
    split_of: dict[str, str],
    split: str,
    excluded: set[str],
) -> dict[str, dict[str, int]]:
    done = complete_queries(grades, keys)
    return {
        q: g for q, g in done.items()
        if split_of.get(q) == split and q not in excluded
    }


def cebu_matrix(query: str, products: list[dict], brands: list[str]) -> np.ndarray:
    rows = [feature_row(query, p, brands) for p in products]
    return np.asarray([[r[name] for name in CEBU_FEATURES] for r in rows], dtype=np.float64)


def build_xy(
    queries: list[str],
    grades: dict[str, dict[str, int]],
    products: list[dict],
    ranker: SemanticRanker,
    p_emb: np.ndarray,
    name_emb: np.ndarray,
    desc_emb: np.ndarray,
    cat_emb: np.ndarray,
    p_milk: np.ndarray,
    brands: list[str],
    with_cebu: bool,
) -> tuple[np.ndarray, np.ndarray, list[int]]:
    keys = [p["key"] for p in products]
    blocks, labels, groups = [], [], []
    for query in queries:
        q_emb, q_probs = ranker.intent_probs(query)
        ext = build_extended_features(
            query, q_emb, q_probs, products, p_emb, name_emb, p_milk,
            ranker.prototypes, desc_emb, cat_emb,
        )
        if with_cebu:
            ext = np.hstack([ext, cebu_matrix(query, products, brands)])
        blocks.append(ext)
        labels.extend(grades[query][k] for k in keys)
        groups.append(len(products))
    return np.vstack(blocks), np.asarray(labels, dtype=np.int32), groups


def fit_ranker(X_tr, y_tr, g_tr, X_va, y_va, g_va) -> lgb.LGBMRanker:
    model = lgb.LGBMRanker(
        objective="lambdarank",
        n_estimators=MAX_TREES,
        learning_rate=0.05,
        num_leaves=15,
        min_child_samples=5,
        subsample=0.9,
        subsample_freq=1,
        colsample_bytree=0.9,
        label_gain=[0, 1, 3],
        random_state=SEED,
        verbose=-1,
    )
    model.fit(
        X_tr, y_tr,
        group=g_tr,
        eval_set=[(X_va, y_va)],
        eval_group=[g_va],
        eval_at=[5],
        eval_metric="ndcg",
        callbacks=[
            lgb.early_stopping(PATIENCE, first_metric_only=True),
            lgb.log_evaluation(20),
        ],
    )
    return model


def ltr_rank(model: lgb.LGBMRanker, X: np.ndarray, keys: list[str]) -> list[str]:
    scores = model.predict(X)
    order = np.argsort(-scores, kind="stable")
    return [keys[i] for i in order]


def system_block(runs: dict[str, list[str]], grades: dict[str, dict[str, int]], milk_keys: set[str]) -> dict:
    per_query = []
    conf = {"TP": 0, "FP": 0, "FN": 0, "TN": 0}
    for query, ranked in runs.items():
        g = grades[query]
        per_query.append(evaluate_run(ranked, g, milk_keys))
        conf = add_confusion(conf, binary_topk_counts(ranked, g))
    metrics = macro(per_query)
    return {
        "ranking": {k: metrics[k] for k in ("P@5", "R@5", "NDCG@5", "MRR")},
        "binary_top5_micro": confusion_block(conf),
        "n_queries": len(runs),
    }


def save_model(model: lgb.LGBMRanker, name: str, features: list[str], extra: dict) -> Path:
    folder = OUT_MODELS / name
    folder.mkdir(parents=True, exist_ok=True)
    model.booster_.save_model(str(folder / "ranker.txt"))
    meta = {
        "name": name,
        "experimental": True,
        "deployed": False,
        "production_untouched": True,
        "snapshot_dir": str(SNAPSHOT),
        "features": features,
        "label_gain": [0, 1, 3],
        "thesis_ndcg": "DCG uses 2^grade-1 on raw labels 0/1/2",
        "lambdamart_ndcg_early_stop": "LightGBM NDCG@5 with label_gain [0,1,3]",
        "best_iteration": int(getattr(model, "best_iteration_", 0) or 0),
        "best_score": _best_score(model),
        **extra,
    }
    (folder / "meta.json").write_text(json.dumps(meta, indent=2), encoding="utf-8")
    (folder / "feature_names.json").write_text(json.dumps(features, indent=2), encoding="utf-8")
    return folder


def _best_score(model: lgb.LGBMRanker):
    score = getattr(model, "best_score_", None)
    if not score:
        return None
    out = {}
    for k, v in dict(score).items():
        out[str(k)] = {str(m): float(x) for m, x in dict(v).items()}
    return out


def main() -> int:
    products = load_products(EXPERIMENT / "products.json")
    keys = [p["key"] for p in products]
    milk_keys = {p["key"] for p in products if is_milk(p)}
    split_of = load_splits()
    excluded = load_excluded_queries()

    train_grades = split_complete(
        load_grades(EXPERIMENT / "labeling_train" / "labeling_sheet_train.csv"),
        keys, split_of, "train", excluded,
    )
    val_grades = split_complete(
        load_grades(EXPERIMENT / "labeling_sheet.csv"),
        keys, split_of, "val", excluded,
    )
    test_in_train = [q for q in train_grades if split_of.get(q) == "test"]
    if test_in_train:
        raise SystemExit("test leak into train: " + ", ".join(test_in_train[:5]))
    if len(train_grades) != 171:
        raise SystemExit(f"expected 171 train queries, got {len(train_grades)}")
    if len(val_grades) != 51:
        raise SystemExit(f"expected 51 val queries, got {len(val_grades)}")

    train_qs = sorted(train_grades, key=lambda q: normalize_query(q))
    val_qs = sorted(val_grades, key=lambda q: normalize_query(q))

    ranker = SemanticRanker(models_dir=SNAPSHOT)
    p_emb = ranker.embedder.encode([product_text(p) for p in products])
    name_emb = ranker.embedder.encode([p.get("name") or "" for p in products])
    desc_emb = ranker.embedder.encode([p.get("description") or "" for p in products])
    cat_emb = ranker.embedder.encode([p.get("category") or "" for p in products])
    p_milk = ranker.milk_clf.predict_proba(p_emb)[:, 1]
    brands = catalog_brands(products)

    feats_cebu = list(EXTENDED_FEATURES) + CEBU_FEATURES
    feats_ab = list(EXTENDED_FEATURES)

    Xtr_c, ytr, gtr = build_xy(
        train_qs, train_grades, products, ranker, p_emb, name_emb, desc_emb, cat_emb, p_milk, brands, True,
    )
    Xva_c, yva, gva = build_xy(
        val_qs, val_grades, products, ranker, p_emb, name_emb, desc_emb, cat_emb, p_milk, brands, True,
    )
    Xtr_a, _, _ = build_xy(
        train_qs, train_grades, products, ranker, p_emb, name_emb, desc_emb, cat_emb, p_milk, brands, False,
    )
    Xva_a, _, _ = build_xy(
        val_qs, val_grades, products, ranker, p_emb, name_emb, desc_emb, cat_emb, p_milk, brands, False,
    )

    print(f"train pairs={len(ytr)} val pairs={len(yva)} groups_train={len(gtr)} groups_val={len(gva)}")
    print("fitting Cebu-CA-HyLTR (with Cebuano features)")
    model_cebu = fit_ranker(Xtr_c, ytr, gtr, Xva_c, yva, gva)
    print("fitting ablation (no Cebuano features)")
    model_ab = fit_ranker(Xtr_a, ytr, gtr, Xva_a, yva, gva)

    n = len(products)
    runs = {
        "bm25": {q: rank_bm25(q, products) for q in val_qs},
        "labse": {q: rank_labse(q, products, ranker) for q in val_qs},
        "hybrid_current": {q: rank_hybrid(q, products, ranker) for q in val_qs},
        "cebu_ca_hyltr": {},
        "ltr_ablation": {},
    }
    for i, query in enumerate(val_qs):
        sl = slice(i * n, (i + 1) * n)
        runs["cebu_ca_hyltr"][query] = ltr_rank(model_cebu, Xva_c[sl], keys)
        runs["ltr_ablation"][query] = ltr_rank(model_ab, Xva_a[sl], keys)

    systems = {name: system_block(run, val_grades, milk_keys) for name, run in runs.items()}
    version = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    extra = {
        "version": version,
        "train_queries": len(train_qs),
        "val_queries": len(val_qs),
        "catalog_size": n,
        "held_out_test_untouched": True,
    }
    cebu_dir = save_model(model_cebu, "cebu_ca_hyltr", feats_cebu, extra)
    ab_dir = save_model(model_ab, "ltr_ablation", feats_ab, extra)

    report = {
        "split": "val",
        "status": "ok",
        "training_succeeded": True,
        "catalog_size": n,
        "train_queries": len(train_qs),
        "val_queries": len(val_qs),
        "test_evaluated": False,
        "production_untouched": True,
        "frozen_classifiers": True,
        "ndcg_conventions": {
            "early_stopping": "LightGBM lambdarank NDCG@5 with label_gain [0,1,3] (grade2 gain=3)",
            "reported_metrics": "Thesis DCG (2^grade-1) on raw grades 0/1/2; binary metrics use grade>=2",
        },
        "cebu_ca_hyltr": {
            "best_iteration": int(getattr(model_cebu, "best_iteration_", 0) or 0),
            "lightgbm_best_score": _best_score(model_cebu),
            "model_dir": str(cebu_dir),
        },
        "ltr_ablation": {
            "best_iteration": int(getattr(model_ab, "best_iteration_", 0) or 0),
            "lightgbm_best_score": _best_score(model_ab),
            "model_dir": str(ab_dir),
        },
        "systems": systems,
        "cebuano_features_improved_ndcg": (
            systems["cebu_ca_hyltr"]["ranking"]["NDCG@5"] > systems["ltr_ablation"]["ranking"]["NDCG@5"]
        ),
        "cebuano_features_improved_vs_hybrid": (
            systems["cebu_ca_hyltr"]["ranking"]["NDCG@5"] > systems["hybrid_current"]["ranking"]["NDCG@5"]
        ),
    }
    out = EXPERIMENT / "reports" / "ltr_val.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(json.dumps({
        "training_succeeded": True,
        "cebu_best_iteration": report["cebu_ca_hyltr"]["best_iteration"],
        "cebu_val": systems["cebu_ca_hyltr"],
        "ablation_val": systems["ltr_ablation"]["ranking"],
        "hybrid_val": systems["hybrid_current"]["ranking"],
        "model_dir": str(cebu_dir),
        "report": str(out),
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
