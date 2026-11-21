"""Stage 1: snapshot baseline, labeling sheet, paraphrase-safe splits.

Does not write ml/models/ranker.txt, does not fill relevance grades, and does
not train. Run from ml/:  .venv/Scripts/python prepare_cebu_ca_stage1.py
"""
from __future__ import annotations

import csv
import json
import shutil
from pathlib import Path

from semantic.data import MODELS, ROOT, load_products, load_queries, normalize_query
from semantic.price_exclude import is_price_query
from semantic.embedder import LabseEmbedder
from semantic.query_splits import assign_splits, cluster_queries, splits_leak
from semantic.ranker import LTR_WEIGHT, SEMANTIC_BM25_WEIGHT, SEMANTIC_COS_WEIGHT, TEXT_WEIGHT

EXPERIMENT = ROOT / "experiments" / "cebu_ca_hyltr"
SNAPSHOT = EXPERIMENT / "baseline_snapshot"
DISTRACTOR_KEYS = ("fresh-tomatoes-1kg", "jasmine-rice-5kg", "pandesal-12-pcs")
ARTIFACTS = (
    "meta.json",
    "ranker.txt",
    "ranker_text_only.txt",
    "intent_classifier.joblib",
    "product_milk_classifier.joblib",
    "intent_prototypes.npy",
    "feedback_index.npz",
)
EXTRA_QUERIES = [
    {"query": "gatas nga presko", "intent": "cow", "lang": "ceb", "source": "stage2_example"},
]
STAGE2_EXAMPLES = [
    "gatas nga powder",
    "gatas nga presko",
    "gatas nga low fat pero dili powder",
    "gatas nga organic ug walay sugar",
    "gatas para sa kape pero dili tam-is",
    "gatas nga soya pero dili chocolate",
]


def snapshot_baseline() -> dict:
    SNAPSHOT.mkdir(parents=True, exist_ok=True)
    copied = []
    for name in ARTIFACTS:
        src = MODELS / name
        if src.exists():
            shutil.copy2(src, SNAPSHOT / name)
            copied.append(name)
    meta = json.loads((SNAPSHOT / "meta.json").read_text(encoding="utf-8")) if (SNAPSHOT / "meta.json").exists() else {}
    formula = {
        "name": "current_hybrid_lambdamart",
        "source_model_version": meta.get("version"),
        "source_dir": str(MODELS),
        "snapshot_dir": str(SNAPSHOT),
        "ltr_weight": LTR_WEIGHT,
        "text_weight": TEXT_WEIGHT,
        "labse_weight": SEMANTIC_COS_WEIGHT,
        "bm25_weight": SEMANTIC_BM25_WEIGHT,
        "bm25": {"k1": 1.2, "b": 0.75},
        "mix": "LTR_WEIGHT * minmax(lambdamart) + TEXT_WEIGHT * minmax(extra_term_bm25)",
        "labse": "L2-normalized product embedding dot query embedding",
        "production_models_untouched": True,
    }
    (SNAPSHOT / "formula.json").write_text(json.dumps(formula, indent=2), encoding="utf-8")
    return {"copied": copied, "formula": formula}


def catalog_products() -> list[dict]:
    """39 milk-catalog SKUs (specs present) plus 3 grocery distractors = 42."""
    all_products = load_products()
    by_key = {p["key"]: p for p in all_products}
    specced = [p for p in all_products if p.get("specs")]
    extra = [by_key[k] for k in DISTRACTOR_KEYS if k in by_key and k not in {p["key"] for p in specced}]
    catalog = specced + extra
    catalog.sort(key=lambda p: p["key"])
    return catalog


def experiment_queries() -> list[dict]:
    rows = []
    seen = set()
    for q in load_queries():
        text = (q.get("query") or "").strip()
        if not text or is_price_query(text):
            continue
        key = normalize_query(text)
        if key in seen:
            continue
        seen.add(key)
        rows.append({
            "query": text,
            "intent": q.get("intent") or "",
            "lang": q.get("lang") or "",
            "legacy_split": q.get("split") or "",
            "source": "queries.csv",
        })
    for extra in EXTRA_QUERIES:
        key = normalize_query(extra["query"])
        if key in seen:
            continue
        seen.add(key)
        rows.append({**extra, "legacy_split": ""})
    return rows


def write_products(products: list[dict]) -> None:
    (EXPERIMENT / "products.json").write_text(json.dumps(products, ensure_ascii=False, indent=2), encoding="utf-8")
    with (EXPERIMENT / "products.csv").open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=[
            "product_key", "name", "description", "category", "brand", "form",
            "source", "fat", "sugar", "organic", "volume_ml", "is_milk_spec",
        ])
        writer.writeheader()
        for p in products:
            specs = p.get("specs") or {}
            writer.writerow({
                "product_key": p["key"],
                "name": p.get("name") or "",
                "description": p.get("description") or "",
                "category": p.get("category") or "",
                "brand": specs.get("brand") or "",
                "form": specs.get("form") or "",
                "source": specs.get("source") or "",
                "fat": specs.get("fat") or "",
                "sugar": specs.get("sugar") or "",
                "organic": specs.get("organic") if specs else "",
                "volume_ml": specs.get("volume_ml") or "",
                "is_milk_spec": "" if not specs else int(bool(specs.get("is_milk"))),
            })


def write_splits(queries: list[dict], cluster_ids: list[int], splits: list[str]) -> None:
    path = EXPERIMENT / "splits.csv"
    with path.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=[
            "query", "cluster_id", "split", "intent", "lang", "legacy_split", "source", "stage2_example",
        ])
        writer.writeheader()
        examples = {normalize_query(t) for t in STAGE2_EXAMPLES}
        for q, cid, split in zip(queries, cluster_ids, splits):
            writer.writerow({
                "query": q["query"],
                "cluster_id": cid,
                "split": split,
                "intent": q.get("intent") or "",
                "lang": q.get("lang") or "",
                "legacy_split": q.get("legacy_split") or "",
                "source": q.get("source") or "",
                "stage2_example": int(normalize_query(q["query"]) in examples),
            })


def write_labeling_sheet(queries: list[dict], products: list[dict], cluster_ids: list[int], splits: list[str]) -> None:
    long_path = EXPERIMENT / "labeling_sheet.csv"
    fieldnames = [
        "query", "cluster_id", "split", "intent", "lang",
        "product_key", "product_name", "product_description", "product_category",
        "grade", "notes",
    ]
    with long_path.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        for q, cid, split in zip(queries, cluster_ids, splits):
            for p in products:
                writer.writerow({
                    "query": q["query"],
                    "cluster_id": cid,
                    "split": split,
                    "intent": q.get("intent") or "",
                    "lang": q.get("lang") or "",
                    "product_key": p["key"],
                    "product_name": p.get("name") or "",
                    "product_description": p.get("description") or "",
                    "product_category": p.get("category") or "",
                    "grade": "",
                    "notes": "",
                })

    wide_path = EXPERIMENT / "labeling_sheet_wide.csv"
    keys = [p["key"] for p in products]
    with wide_path.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=["query", "cluster_id", "split", "intent", "lang", *keys])
        writer.writeheader()
        for q, cid, split in zip(queries, cluster_ids, splits):
            row = {
                "query": q["query"],
                "cluster_id": cid,
                "split": split,
                "intent": q.get("intent") or "",
                "lang": q.get("lang") or "",
            }
            for key in keys:
                row[key] = ""
            writer.writerow(row)


def write_readme(n_queries: int, n_products: int, split_counts: dict, n_clusters: int) -> None:
    text = f"""# Cebu-CA-HyLTR Stage 1 (review packet)

Frozen baseline and labeling materials. Production `ml/models/ranker.txt` was not modified.

## Catalog

- `{n_products}` products: 39 milk-catalog SKUs with specs plus 3 grocery distractors (tomatoes, jasmine rice, pandesal).
- Product facts in `products.csv` are for the judge. They are **not** relevance grades.

## How to label `labeling_sheet.csv`

Grade every (query, product) pair yourself. Leave cells blank until you are sure.

| Grade | Meaning |
| ---: | --- |
| 2 | Highly relevant to this query (would satisfy the shopper) |
| 1 | Related but wrong attribute / weaker match |
| 0 | Irrelevant |

Do not copy scores from the ranker. Do not fill grades from `is_milk` or specs with a script.

`labeling_sheet_wide.csv` is the same task, one row per query, one column per product key.

## Splits (`splits.csv`)

Near-paraphrases share a `cluster_id` and the same split (LaBSE cosine ≥ 0.92, or one query contains the other).

| Split | Queries | Role |
| --- | ---: | --- |
| train | {split_counts.get("train", 0)} | Future LTR fit only (Stage 2, after you approve labels) |
| val | {split_counts.get("val", 0)} | Feature/ablation decisions |
| test | {split_counts.get("test", 0)} | Held-out, one-shot |

Clusters: {n_clusters}. Queries: {n_queries}.

Stage 2 example queries are flagged `stage2_example=1`. Val pins: low-fat¬powder, organic∧no-sugar, kape¬tam-is. Test pins: soya¬chocolate, powder, presko.

## Evaluate (after some val rows are fully graded)

```
.venv/Scripts/python evaluate_cebu_ca.py --split val
```

`--split test` is refused unless you pass `--held-out-ok`. Incomplete queries (any blank grade) are skipped. No metrics are invented.

Baseline snapshot: `baseline_snapshot/` including `formula.json`.
"""
    (EXPERIMENT / "README.md").write_text(text, encoding="utf-8")


def main() -> None:
    EXPERIMENT.mkdir(parents=True, exist_ok=True)
    snap = snapshot_baseline()
    products = catalog_products()
    queries = experiment_queries()
    embedder = LabseEmbedder()
    emb = embedder.encode([normalize_query(q["query"]) for q in queries])
    cluster_ids = cluster_queries([q["query"] for q in queries], emb)
    splits = assign_splits(queries, cluster_ids)
    leaked = splits_leak(queries, cluster_ids, splits)
    if leaked:
        raise SystemExit(f"split leakage in clusters: {leaked}")

    write_products(products)
    write_splits(queries, cluster_ids, splits)
    write_labeling_sheet(queries, products, cluster_ids, splits)
    counts = {s: splits.count(s) for s in ("train", "val", "test")}
    write_readme(len(queries), len(products), counts, len(set(cluster_ids)))

    status = {
        "products": len(products),
        "queries": len(queries),
        "clusters": len(set(cluster_ids)),
        "splits": counts,
        "grades_filled": 0,
        "snapshot": snap["formula"]["source_model_version"],
        "copied_artifacts": snap["copied"],
        "production_ranker_untouched": True,
    }
    (EXPERIMENT / "stage1_status.json").write_text(json.dumps(status, indent=2), encoding="utf-8")
    print(json.dumps(status, indent=2))


if __name__ == "__main__":
    main()
