"""Evaluate a grocery LambdaMART candidate against LaBSE+BM25 on a frozen split."""
from __future__ import annotations

import argparse
import json
from collections import defaultdict
from pathlib import Path

import joblib
import lightgbm as lgb
import numpy as np

from semantic.data import MODELS, REPORTS, load_grocery_products, load_grocery_qrels, load_grocery_queries, normalize_query, product_text
from semantic.embedder import LabseEmbedder
from semantic.metrics import mrr, ndcg_at_k, precision_at_k, recall_at_k
from semantic.ranker import TEXT_FEATURES, build_extended_features, semantic_text_scores

METRICS = ["P@5", "R@5", "NDCG@5", "MRR", "Leak@5"]


def _leak(ranked: list[str], grades: dict[str, int], k: int = 5) -> float:
    """Share of top-k with an explicit grade 0. Unlabeled keys are not leakage."""
    top = ranked[:k]
    if not top:
        return 0.0
    return sum(1 for key in top if grades.get(key) == 0) / len(top)


def _metrics(ranked: list[str], grades: dict[str, int]) -> dict[str, float]:
    return {
        "P@5": precision_at_k(ranked, grades),
        "R@5": recall_at_k(ranked, grades),
        "NDCG@5": ndcg_at_k(ranked, grades),
        "MRR": mrr(ranked, grades),
        "Leak@5": _leak(ranked, grades),
    }


def _macro(rows: list[dict[str, float]]) -> dict[str, float]:
    if not rows:
        return {m: None for m in METRICS}
    return {m: sum(r[m] for r in rows) / len(rows) for m in METRICS}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--candidate", required=True)
    parser.add_argument("--split", default="val", help="val (selection) or test (frozen, only if labeled)")
    args = parser.parse_args()
    candidate = Path(args.candidate)
    if not (candidate / "ranker.txt").exists():
        print("candidate ranker.txt missing")
        return 1

    qrels = load_grocery_qrels()
    queries = [q for q in load_grocery_queries() if q.get("split") == args.split]
    labeled = [q for q in queries if q["query"] in qrels]
    REPORTS.mkdir(exist_ok=True)
    if not labeled:
        report = {
            "split": args.split,
            "candidate": str(candidate),
            "labeled_queries": 0,
            "note": "No grocery grades for this split.",
        }
        out = REPORTS / f"grocery_{args.split}_eval.json"
        out.write_text(json.dumps(report, indent=2), encoding="utf-8")
        print(json.dumps(report, indent=2))
        return 0

    products = load_grocery_products()
    keys = [p["key"] for p in products]
    embedder = LabseEmbedder(json.loads((candidate / "meta.json").read_text())["embedding_model"])
    intent_clf = joblib.load(candidate / "intent_classifier.joblib")
    milk_clf = joblib.load(candidate / "product_milk_classifier.joblib")
    prototypes = np.load(candidate / "intent_prototypes.npy")
    full = lgb.Booster(model_file=str(candidate / "ranker.txt"))
    text_only = lgb.Booster(model_file=str(candidate / "ranker_text_only.txt"))
    p_emb = embedder.encode([product_text(p) for p in products])
    name_emb = embedder.encode([p.get("name") or "" for p in products])
    desc_emb = embedder.encode([p.get("description") or "" for p in products])
    cat_emb = embedder.encode([p.get("category") or "" for p in products])
    p_milk = milk_clf.predict_proba(p_emb)[:, 1]
    feature_names = json.loads((candidate / "meta.json").read_text())["features"]
    text_idx = [feature_names.index(f) for f in TEXT_FEATURES if f in feature_names]

    def order(scores: np.ndarray) -> list[str]:
        return [keys[i] for i in np.argsort(-scores, kind="stable")]

    runs: dict[str, dict[str, list[str]]] = defaultdict(dict)
    by_family: dict[str, dict[str, list]] = defaultdict(lambda: defaultdict(list))
    by_lang: dict[str, dict[str, list]] = defaultdict(lambda: defaultdict(list))

    for q in labeled:
        text = q["query"]
        q_emb = embedder.encode([normalize_query(text)])[0]
        q_probs = intent_clf.predict_proba(q_emb[None, :])[0]
        feats = build_extended_features(
            text, q_emb, q_probs, products, p_emb, name_emb, p_milk, prototypes, desc_emb, cat_emb,
        )
        grades = qrels[text]
        runs["labse_bm25"][text] = order(semantic_text_scores(text, q_emb, products, p_emb))
        runs["ltr_text_only"][text] = order(text_only.predict(feats[:, text_idx]))
        nfeat = int(full.num_feature())
        block = feats[:, :nfeat] if feats.shape[1] >= nfeat else np.hstack(
            [feats, np.zeros((feats.shape[0], nfeat - feats.shape[1]))]
        )
        runs["ltr_full"][text] = order(full.predict(block))
        for system, ranked in (
            ("labse_bm25", runs["labse_bm25"][text]),
            ("ltr_text_only", runs["ltr_text_only"][text]),
            ("ltr_full", runs["ltr_full"][text]),
        ):
            m = _metrics(ranked, grades)
            by_family[system][q.get("family") or "unknown"].append(m)
            by_lang[system][q.get("lang") or "und"].append(m)

    per_system = {}
    for system, by_query in runs.items():
        rows = [_metrics(by_query[q["query"]], qrels[q["query"]]) for q in labeled]
        per_system[system] = {
            "macro": _macro(rows),
            "by_family": {fam: _macro(rs) for fam, rs in by_family[system].items()},
            "by_lang": {lang: _macro(rs) for lang, rs in by_lang[system].items()},
        }

    summary = {
        "split": args.split,
        "candidate": str(candidate),
        "labeled_queries": len(labeled),
        "unlabeled_queries_in_split": len(queries) - len(labeled),
        "production_models_dir": str(MODELS),
        "systems": per_system,
        "feedback_ltr": "not evaluated (no promoted grocery feedback model)",
    }
    out = REPORTS / f"grocery_{args.split}_eval.json"
    out.write_text(json.dumps(summary, indent=2), encoding="utf-8")
    print(json.dumps(summary, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
