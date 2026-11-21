"""Copy the live catalog into ml/data/grocery/ and write inventory counts."""
from __future__ import annotations

import csv
import json
import re
import shutil
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"
GROCERY = DATA / "grocery"

# Reporting-only. Never copied into qrels.csv.
FAMILY_PATTERNS: dict[str, tuple[str, ...]] = {
    "milk": (
        " milk", "milk ", "gatas", "evaporated", "condensada", "condensed milk",
        "powdered milk", "milk drink", "milk powder", "soymilk", "oat milk",
        "almond milk",
    ),
    "canned": (
        "corned", "sardine", "tuna flakes", "delata", "canned", "in tomato sauce",
        "in oil", "wow ulam",
    ),
    "seafood": (
        "sardine", "tuna", "tinapa", "smoked", "fish", "bulad", "daing",
    ),
    "rice": ("rice", "bugas", "bigas"),
    "laundry_soap": (
        "detergent", "laundry", "ariel", "surf powder", "surf bar", "tide ",
        "wings", "calla", "perla", "champion", "downy", "fabcon", "fabric conditioner",
    ),
    "bath_soap": (
        "germicidal soap", "bar soap", "whitening soap", "safeguard", "bioderm",
        "kojie", "silka", "skin white",
    ),
    "dishwashing": ("dishwashing", "dish wash", "axion", "dazz paste", "smart dishwashing"),
    "household": (
        "fabric conditioner", "downy", "fabcon", "glue", "napkin", "pads",
    ),
}


def blob(product: dict) -> str:
    return " ".join(
        str(product.get(k) or "") for k in ("name", "description", "category")
    ).lower()


def reporting_families(product: dict) -> list[str]:
    text = blob(product)
    name = (product.get("name") or "").lower()
    hits = []
    for family, needles in FAMILY_PATTERNS.items():
        if family == "rice":
            continue
        if any(n in text for n in needles):
            hits.append(family)
    if "cerelac" in name or "infant cereal" in text:
        hits.append("rice_adjacent_not_table_rice")
    elif re.search(r"\b(rice|bugas|bigas)\b", name) and "bath" not in name:
        hits.append("rice")
    if "corned tuna" in name:
        hits = list(dict.fromkeys(hits + ["canned", "seafood"]))
    if "wings" in name and "detergent" not in name and "solve" not in name:
        hits = [h for h in hits if h != "laundry_soap"]
    return hits


def load_milk_names() -> set[str]:
    path = DATA / "live_product_labels.csv"
    if not path.exists():
        return set()
    with path.open(encoding="utf-8", newline="") as fh:
        return {row["name"] for row in csv.DictReader(fh) if row.get("is_milk") == "1"}


def ensure_products() -> list[dict]:
    GROCERY.mkdir(parents=True, exist_ok=True)
    dest = GROCERY / "products.json"
    live = DATA / "live_products.json"
    if live.exists():
        shutil.copyfile(live, dest)
    if not dest.exists():
        raise SystemExit("No grocery products.json and no ml/data/live_products.json")
    return json.loads(dest.read_text(encoding="utf-8"))


def assert_qrels_unlabeled() -> None:
    path = GROCERY / "qrels.csv"
    if not path.exists():
        return
    with path.open(encoding="utf-8", newline="") as fh:
        rows = list(csv.DictReader(fh))
    graded = [r for r in rows if str(r.get("grade") or "").strip() != ""]
    if graded:
        print(f"qrels.csv has {len(graded)} human grades (left untouched)")
    else:
        print("qrels.csv has no grades (template only)")


def inventory(products: list[dict]) -> dict:
    milk_names = load_milk_names()
    by_aisle = Counter((p.get("category") or "(none)") for p in products)
    by_family: dict[str, set[str]] = defaultdict(set)
    for p in products:
        name = p.get("name") or ""
        if name in milk_names:
            by_family["milk"].add(name)
        for fam in reporting_families(p):
            if fam == "milk":
                continue
            by_family[fam].add(name)
    queries_path = GROCERY / "queries.csv"
    query_stats: dict[str, dict[str, int]] = {}
    if queries_path.exists():
        with queries_path.open(encoding="utf-8", newline="") as fh:
            qs = list(csv.DictReader(fh))
        by_split: dict[str, int] = Counter(q["split"] for q in qs)
        by_fam = Counter(q["family"] for q in qs)
        by_lang = Counter(q["lang"] for q in qs)
        query_stats = {
            "total": len(qs),
            "by_split": dict(by_split),
            "by_family": dict(by_fam),
            "by_lang": dict(by_lang),
        }
    return {
        "source": "ml/data/live_products.json (encoded catalog)",
        "mysql_available_snapshot": "ml/data/grocery/products_mysql_available.json",
        "product_rows": len(products),
        "unique_names": len({p.get("name") for p in products}),
        "by_store_aisle": dict(by_aisle.most_common()),
        "by_labeling_family_unique_names": {k: len(v) for k, v in sorted(by_family.items())},
        "family_name_samples": {k: sorted(v)[:12] for k, v in sorted(by_family.items())},
        "gaps": [
            "No listing name contains tinapa. Tinapa queries have no grade-2 SKU until you add smoked fish / tinapa.",
            "Store aisle 'Buy It Again' holds most encoded SKUs; aisle names are not reliable family labels.",
            "Inventory families can overlap (e.g. sardines in canned and seafood). Overlap is not a relevance grade.",
        ],
        "queries": query_stats,
        "labels": "none assigned by this script",
    }


def write_inventory_markdown(inv: dict) -> str:
    lines = [
        "# Grocery labeling inventory",
        "",
        "Family counts below are **reporting heuristics** from product names.",
        "They are **not** relevance labels and must not be copied into `qrels.csv`.",
        "",
        f"- Product rows: **{inv['product_rows']}**",
        f"- Unique names: **{inv['unique_names']}**",
        f"- Source: `{inv['source']}`",
        f"- Current MySQL `available()` snapshot: `{inv['mysql_available_snapshot']}`",
        "",
        "## Unique names by labeling family",
        "",
        "| Family | Unique names |",
        "|---|---:|",
    ]
    for fam, n in inv["by_labeling_family_unique_names"].items():
        lines.append(f"| {fam} | {n} |")
    lines += ["", "## Store aisle (category field)", "", "| Aisle | Rows |", "|---|---:|"]
    for aisle, n in inv["by_store_aisle"].items():
        lines.append(f"| {aisle} | {n} |")
    lines += ["", "## Gaps", ""]
    for g in inv["gaps"]:
        lines.append(f"- {g}")
    qs = inv.get("queries") or {}
    if qs:
        lines += ["", "## Queries in queries.csv", "", f"Total: **{qs['total']}**", ""]
        lines.append("Splits: " + ", ".join(f"{k}={v}" for k, v in qs["by_split"].items()))
        lines.append("")
        lines.append("Families: " + ", ".join(f"{k}={v}" for k, v in qs["by_family"].items()))
        lines.append("")
        lines.append("Languages: " + ", ".join(f"{k}={v}" for k, v in qs["by_lang"].items()))
    return "\n".join(lines) + "\n"


def main() -> None:
    products = ensure_products()
    assert_qrels_unlabeled()
    inv = inventory(products)
    (GROCERY / "inventory.json").write_text(json.dumps(inv, indent=2, ensure_ascii=False), encoding="utf-8")
    (GROCERY / "inventory.md").write_text(write_inventory_markdown(inv), encoding="utf-8")
    print(json.dumps({k: inv[k] for k in ("product_rows", "unique_names", "by_labeling_family_unique_names", "queries", "labels")}, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
