"""Evaluate BM25, LaBSE, and the frozen hybrid on manually graded sheets.

Skips any query with a blank grade. Does not invent labels. Default split is
val. The held-out test split requires --held-out-ok.

Run from ml/:  .venv/Scripts/python evaluate_cebu_ca.py --split val
"""
from __future__ import annotations

import argparse
import csv
import itertools
import json
from collections import defaultdict
from pathlib import Path

import numpy as np

from semantic.data import ROOT, is_milk, load_products, product_text
from semantic.metrics import (
    add_confusion,
    binary_rates,
    binary_topk_counts,
    evaluate_run,
    macro,
    ndcg_at_k,
)
from semantic.cebu_ca_features import catalog_brands, combine_scores, feature_row
from semantic.price_exclude import load_excluded_queries
from semantic.ranker import SemanticRanker, bm25_scores

EXPERIMENT = ROOT / "experiments" / "cebu_ca_hyltr"
SNAPSHOT = EXPERIMENT / "baseline_snapshot"
SYSTEMS = ("bm25", "labse", "hybrid_current")
STAGE2_SYSTEMS = (
    "hybrid_current",
    "ablate_negation",
    "ablate_attr_match",
    "ablate_brand_form_qty",
    "candidate_cebu",
)
RANK_METRICS = ("P@5", "R@5", "NDCG@5", "MRR")
WEIGHT_GRID = {
    "neg_violate": (0.0, 1.0, 2.0, 3.0),
    "attr_match": (0.0, 0.5, 1.0, 1.5),
    "brand_match": (0.0, 0.5, 1.0),
    "form_match": (0.0, 0.5, 1.0),
    "qty_match": (0.0, 0.5, 1.0),
}


def load_splits() -> dict[str, str]:
    path = EXPERIMENT / "splits.csv"
    out = {}
    with path.open(encoding="utf-8", newline="") as fh:
        for row in csv.DictReader(fh):
            out[row["query"]] = row["split"]
    return out


def load_sheet_grades(path: Path) -> dict[str, dict[str, int | None]]:
    """Blank grade stays None. Never coerced from specs or scores."""
    grades: dict[str, dict[str, int | None]] = defaultdict(dict)
    with path.open(encoding="utf-8", newline="") as fh:
        for row in csv.DictReader(fh):
            raw = str(row.get("grade") or "").strip()
            key = (row.get("product_key") or "").strip()
            query = (row.get("query") or "").strip()
            if not query or not key:
                continue
            grades[query][key] = int(raw) if raw != "" else None
    return dict(grades)


def complete_queries(
    grades: dict[str, dict[str, int | None]],
    product_keys: list[str],
) -> dict[str, dict[str, int]]:
    done = {}
    for query, by_p in grades.items():
        if any(by_p.get(key) is None for key in product_keys):
            continue
        if any(key not in by_p for key in product_keys):
            continue
        done[query] = {key: int(by_p[key]) for key in product_keys}
    return done


def rank_bm25(query: str, products: list[dict]) -> list[str]:
    scores = bm25_scores(query, [product_text(p) for p in products])
    order = np.argsort(-scores, kind="stable")
    return [products[i]["key"] for i in order]


def rank_labse(query: str, products: list[dict], ranker: SemanticRanker) -> list[str]:
    q_emb, _ = ranker.intent_probs(query)
    p_emb = ranker.embedder.encode([product_text(p) for p in products])
    scores = p_emb @ q_emb
    order = np.argsort(-scores, kind="stable")
    return [products[i]["key"] for i in order]


def rank_hybrid(query: str, products: list[dict], ranker: SemanticRanker) -> list[str]:
    result = ranker.rank(query, products)
    return [products[row["index"]]["key"] for row in result.ranked]


def hybrid_scored(query: str, products: list[dict], ranker: SemanticRanker) -> list[tuple[str, float, dict]]:
    result = ranker.rank(query, products)
    return [
        (products[row["index"]]["key"], float(row["score"]), products[row["index"]])
        for row in result.ranked
    ]


def order_with_weights(
    scored: list[tuple[str, float, dict]],
    feat_rows: list[dict[str, float]],
    weights: dict[str, float],
) -> list[str]:
    if not scored:
        return []
    hybrid = [s for _, s, _ in scored]
    combined = combine_scores(hybrid, feat_rows, weights)
    order = sorted(range(len(scored)), key=lambda i: -combined[i])
    return [scored[i][0] for i in order]


def tune_weights(
    scored_by_query: dict[str, list[tuple[str, float, dict]]],
    feats_by_query: dict[str, list[dict[str, float]]],
    grades: dict[str, dict[str, int]],
) -> dict[str, float]:
    keys = list(WEIGHT_GRID)
    best, best_ndcg = None, -1.0
    for combo in itertools.product(*(WEIGHT_GRID[k] for k in keys)):
        weights = dict(zip(keys, combo))
        ndcgs = []
        for query, scored in scored_by_query.items():
            ranked = order_with_weights(scored, feats_by_query[query], weights)
            ndcgs.append(ndcg_at_k(ranked, grades[query]))
        mean = sum(ndcgs) / len(ndcgs) if ndcgs else 0.0
        if mean > best_ndcg:
            best, best_ndcg = weights, mean
    return best or {k: 0.0 for k in keys}


def confusion_block(counts: dict[str, int]) -> dict:
    return {**counts, **binary_rates(counts)}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--split", default="val", choices=("train", "val", "test"))
    parser.add_argument("--held-out-ok", action="store_true", help="Required to score the frozen test split")
    parser.add_argument("--sheet", default=str(EXPERIMENT / "labeling_sheet.csv"))
    parser.add_argument("--stage2", action="store_true", help="Val-only candidate features and ablation; does not write ml/models")
    args = parser.parse_args()

    if args.split == "test" and not args.held_out_ok:
        print("Refusing to score the held-out test split. Pass --held-out-ok after labeling is approved.")
        return 2

    products = load_products(EXPERIMENT / "products.json")
    keys = [p["key"] for p in products]
    milk_keys = {p["key"] for p in products if is_milk(p)}
    split_of = load_splits()
    excluded = load_excluded_queries()
    grades_raw = load_sheet_grades(Path(args.sheet))
    complete = complete_queries(grades_raw, keys)
    eval_queries = [
        q for q, g in complete.items()
        if split_of.get(q) == args.split and q not in excluded
    ]

    report = {
        "split": args.split,
        "catalog_size": len(keys),
        "queries_in_split": sum(1 for q, s in split_of.items() if s == args.split and q not in excluded),
        "queries_fully_labeled": len(eval_queries),
        "price_queries_excluded": sorted(excluded),
        "status": "ok" if eval_queries else "labels_incomplete",
        "systems": {},
    }
    if not eval_queries:
        out = EXPERIMENT / "reports" / f"baseline_{args.split}.json"
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
        print(json.dumps(report, indent=2))
        print("No fully labeled queries in this split. Fill labeling_sheet.csv (grade 2/1/0) and rerun.")
        return 0

    ranker = SemanticRanker(models_dir=SNAPSHOT)
    system_names = SYSTEMS
    runs = {name: {} for name in SYSTEMS}
    for query in eval_queries:
        runs["bm25"][query] = rank_bm25(query, products)
        runs["labse"][query] = rank_labse(query, products, ranker)
        runs["hybrid_current"][query] = rank_hybrid(query, products, ranker)

    tuned = None
    if args.stage2:
        if args.split == "test":
            print("Stage 2 weight selection is val-only. Refusing --stage2 on test.")
            return 2
        brands = catalog_brands(products)
        scored_by_query = {}
        feats_by_query = {}
        for query in eval_queries:
            scored = hybrid_scored(query, products, ranker)
            scored_by_query[query] = scored
            feats_by_query[query] = [feature_row(query, p, brands) for _, _, p in scored]
        tuned = tune_weights(scored_by_query, feats_by_query, complete)
        ablations = {
            "hybrid_current": {k: 0.0 for k in WEIGHT_GRID},
            "ablate_negation": {**tuned, "neg_violate": 0.0},
            "ablate_attr_match": {**tuned, "attr_match": 0.0},
            "ablate_brand_form_qty": {**tuned, "brand_match": 0.0, "form_match": 0.0, "qty_match": 0.0},
            "candidate_cebu": tuned,
        }
        system_names = list(SYSTEMS)
        for extra in STAGE2_SYSTEMS:
            if extra not in system_names:
                system_names.append(extra)
        for name, weights in ablations.items():
            if name == "hybrid_current":
                continue
            runs[name] = {
                query: order_with_weights(scored_by_query[query], feats_by_query[query], weights)
                for query in eval_queries
            }
        report["candidate_weights"] = tuned
        report["weights_tuned_on"] = "val"
        report["lambdamart_retrained"] = False
        report["production_untouched"] = True

    for name in system_names:
        per_query = []
        conf = {"TP": 0, "FP": 0, "FN": 0, "TN": 0}
        for query in eval_queries:
            ranked = runs[name][query]
            g = complete[query]
            per_query.append(evaluate_run(ranked, g, milk_keys))
            conf = add_confusion(conf, binary_topk_counts(ranked, g))
        metrics = macro(per_query)
        report["systems"][name] = {
            "ranking": {k: metrics[k] for k in RANK_METRICS},
            "binary_top5_micro": confusion_block(conf),
            "n_queries": len(eval_queries),
        }

    report["model_version"] = ranker.model_version
    report["mix"] = json.loads((SNAPSHOT / "formula.json").read_text(encoding="utf-8")).get("mix")
    report_name = f"stage2_{args.split}.json" if args.stage2 else f"baseline_{args.split}.json"
    out = EXPERIMENT / "reports" / report_name
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(render_markdown(report))
    return 0


def render_markdown(report: dict) -> str:
    title = "stage2 candidate" if report.get("candidate_weights") else "baseline"
    lines = [
        f"# Cebu-CA-HyLTR {title} ({report['split']})",
        "",
        f"Fully labeled queries: {report['queries_fully_labeled']} / {report['queries_in_split']} in split.",
        "",
        "| System | P@5 | R@5 | NDCG@5 | MRR |",
        "|---|---:|---:|---:|---:|",
    ]
    for name, block in report["systems"].items():
        r = block["ranking"]
        lines.append(
            f"| {name} | {r['P@5']:.3f} | {r['R@5']:.3f} | {r['NDCG@5']:.3f} | {r['MRR']:.3f} |"
        )
    lines += ["", "## Binary retrieval (Actual vs Predicted, top-5, micro)", ""]
    lines += [
        "| System | TP | FN | FP | TN | Accuracy | Precision | Recall | F1 |",
        "|---|---:|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for name, block in report["systems"].items():
        c = block["binary_top5_micro"]
        lines.append(
            f"| {name} | {c['TP']} | {c['FN']} | {c['FP']} | {c['TN']} | "
            f"{c['accuracy']:.3f} | {c['precision']:.3f} | {c['recall']:.3f} | {c['f1']:.3f} |"
        )
    return "\n".join(lines) + "\n"


if __name__ == "__main__":
    raise SystemExit(main())
