"""Train a grocery LambdaMART candidate (does not write ml/models/)."""
from __future__ import annotations

import argparse
import json
import shutil
from datetime import datetime, timezone
from pathlib import Path

import joblib
import numpy as np

from semantic.data import (
    CLASSES,
    GROCERY,
    MODELS,
    load_grocery_products,
    load_grocery_qrels,
    load_grocery_queries,
    load_products,
    load_qrels,
    load_queries,
    normalize_query,
    product_text,
)
from semantic.embedder import MODEL_ID, LabseEmbedder
from semantic.ranker import (
    EXTENDED_FEATURES,
    TEXT_FEATURES,
    build_extended_features,
    extra_query_tokens,
)
from train import train_ranker

MILK_ARTIFACTS = (
    "intent_classifier.joblib",
    "product_milk_classifier.joblib",
    "intent_prototypes.npy",
    "ranker.txt",
    "ranker_text_only.txt",
    "meta.json",
)


def _encode_catalog(embedder: LabseEmbedder, products: list[dict]) -> dict[str, np.ndarray]:
    p_emb = embedder.encode([product_text(p) for p in products])
    name_emb = embedder.encode([p.get("name") or "" for p in products])
    desc_emb = embedder.encode([p.get("description") or "" for p in products])
    cat_emb = embedder.encode([p.get("category") or "" for p in products])
    return {"p_emb": p_emb, "name_emb": name_emb, "desc_emb": desc_emb, "cat_emb": cat_emb}


def _group_features(
    query: str,
    products: list[dict],
    catalog: dict[str, np.ndarray],
    embedder: LabseEmbedder,
    intent_clf,
    milk_clf,
    prototypes: np.ndarray,
) -> np.ndarray:
    q_emb = embedder.encode([normalize_query(query)])[0]
    q_probs = intent_clf.predict_proba(q_emb[None, :])[0]
    p_milk = milk_clf.predict_proba(catalog["p_emb"])[:, 1]
    return build_extended_features(
        query,
        q_emb,
        q_probs,
        products,
        catalog["p_emb"],
        catalog["name_emb"],
        p_milk,
        prototypes,
        catalog["desc_emb"],
        catalog["cat_emb"],
    )


def _append_group(X: list, y: list, groups: list, feats: np.ndarray, labels: list[int]) -> None:
    if feats.shape[0] != len(labels) or max(labels) <= 0:
        return
    X.append(feats)
    y.extend(labels)
    groups.append(len(labels))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--out-dir", default="", help="Candidate directory (never ml/models)")
    args = parser.parse_args()
    version = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    out_dir = Path(args.out_dir) if args.out_dir else MODELS / "candidates" / f"grocery-{version}"
    if out_dir.resolve() == MODELS.resolve():
        print("Refusing to write grocery LambdaMART into ml/models.")
        return 1
    out_dir.mkdir(parents=True, exist_ok=True)

    for name in MILK_ARTIFACTS:
        src = MODELS / name
        if src.exists():
            shutil.copy2(src, out_dir / f"milk_production_{name}")

    intent_clf = joblib.load(MODELS / "intent_classifier.joblib")
    milk_clf = joblib.load(MODELS / "product_milk_classifier.joblib")
    prototypes = np.load(MODELS / "intent_prototypes.npy")
    milk_meta = json.loads((MODELS / "meta.json").read_text(encoding="utf-8"))

    print("Loading LaBSE (ONNX)...", flush=True)
    embedder = LabseEmbedder(MODEL_ID)
    milk_products = load_products()
    print(f"Encoding milk catalog ({len(milk_products)} products)...", flush=True)
    milk_catalog = _encode_catalog(embedder, milk_products)
    print("Milk catalog encoded.", flush=True)
    milk_qrels = load_qrels()
    milk_train = [q for q in load_queries() if q["split"] == "train" and q.get("intent") != "other"]

    grocery_qrels = load_grocery_qrels()
    grocery_products = load_grocery_products() if (GROCERY / "products.json").exists() else []
    grocery_queries = load_grocery_queries() if (GROCERY / "queries.csv").exists() else []
    grocery_catalog = {}
    if grocery_products and grocery_qrels:
        print(f"Encoding grocery catalog ({len(grocery_products)} products, {sum(len(v) for v in grocery_qrels.values())} grades)...", flush=True)
        grocery_catalog = _encode_catalog(embedder, grocery_products)
        print("Grocery catalog encoded.", flush=True)
    else:
        print("No grocery qrels with grades; skipping grocery catalog encode.", flush=True)
    grocery_train = [q for q in grocery_queries if q.get("split") == "train"]
    grocery_test = [q for q in grocery_queries if q.get("split") == "test"]
    test_texts = {normalize_query(q["query"]) for q in grocery_test}

    X, y, groups = [], [], []
    milk_groups = 0
    for q in milk_train:
        judged = milk_qrels.get(q["query"], {})
        labels = [judged.get(p["key"], 0) for p in milk_products]
        feats = _group_features(
            q["query"], milk_products, milk_catalog, embedder, intent_clf, milk_clf, prototypes,
        )
        before = len(groups)
        _append_group(X, y, groups, feats, labels)
        milk_groups += int(len(groups) > before)

    grocery_groups = 0
    skipped_unlabeled = 0
    skipped_test_leak = 0
    for q in grocery_train:
        text = q["query"]
        if normalize_query(text) in test_texts:
            skipped_test_leak += 1
            continue
        judged = grocery_qrels.get(text) or grocery_qrels.get(normalize_query(text), {})
        if not judged:
            skipped_unlabeled += 1
            continue
        index = {p["key"]: i for i, p in enumerate(grocery_products)}
        keep = [index[k] for k in judged if k in index]
        if not keep:
            skipped_unlabeled += 1
            continue
        subset = [grocery_products[i] for i in keep]
        sub_cat = {k: v[keep] for k, v in grocery_catalog.items()}
        labels = [judged[p["key"]] for p in subset]
        feats = _group_features(text, subset, sub_cat, embedder, intent_clf, milk_clf, prototypes)
        before = len(groups)
        _append_group(X, y, groups, feats, labels)
        grocery_groups += int(len(groups) > before)

    if not groups:
        print("No labelled ranking groups. Fill ml/data/grocery/qrels.csv and/or keep milk qrels.")
        return 1

    print(f"Fitting LambdaMART ({milk_groups} milk groups, {grocery_groups} grocery groups)...", flush=True)
    feature_names = list(EXTENDED_FEATURES)
    X_mat = np.vstack(X)
    y_arr = np.array(y)
    ranker = train_ranker(X_mat, y_arr, groups)
    ranker.booster_.save_model(str(out_dir / "ranker.txt"))

    text_idx = [feature_names.index(f) for f in TEXT_FEATURES if f in feature_names]
    ranker_text = train_ranker(X_mat[:, text_idx], y_arr, groups)
    ranker_text.booster_.save_model(str(out_dir / "ranker_text_only.txt"))

    shutil.copy2(MODELS / "intent_classifier.joblib", out_dir / "intent_classifier.joblib")
    shutil.copy2(MODELS / "product_milk_classifier.joblib", out_dir / "product_milk_classifier.joblib")
    shutil.copy2(MODELS / "intent_prototypes.npy", out_dir / "intent_prototypes.npy")

    importance = dict(sorted(
        zip(feature_names, ranker.booster_.feature_importance("gain").round(2).tolist()),
        key=lambda kv: -kv[1],
    ))
    meta = {
        "version": version,
        "kind": "grocery_ltr_candidate",
        "deployed": False,
        "embedding_model": MODEL_ID,
        "classes": CLASSES,
        "features": feature_names,
        "text_features": TEXT_FEATURES,
        "query_milk_threshold": milk_meta.get("query_milk_threshold", 0.5),
        "product_milk_threshold": milk_meta.get("product_milk_threshold"),
        "source_milk_model": milk_meta.get("version"),
        "milk_groups": milk_groups,
        "grocery_groups": grocery_groups,
        "grocery_train_queries": len(grocery_train),
        "grocery_train_unlabeled_skipped": skipped_unlabeled,
        "grocery_test_leak_skipped": skipped_test_leak,
        "train_pairs": int(len(y_arr)),
        "milk_products": len(milk_products),
        "grocery_products": len(grocery_products),
        "ranker_feature_gain": importance,
        "deployed_to_rank": False,
        "grocery_fillers_example": extra_query_tokens("tanan gatas"),
    }
    (out_dir / "meta.json").write_text(json.dumps(meta, indent=2), encoding="utf-8")
    printable = {k: v for k, v in meta.items() if k != "ranker_feature_gain"}
    print(json.dumps(printable, indent=2))
    print("top features:", list(importance)[:8])
    print(f"candidate written to {out_dir} (production ml/models untouched)")
    if grocery_groups == 0:
        print("WARNING: no grocery qrels. Candidate is milk groups with extra grocery features only.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
