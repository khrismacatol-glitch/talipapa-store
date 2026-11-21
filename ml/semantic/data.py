"""Dataset loading and the relevance rules used to build qrels."""
from __future__ import annotations

import csv
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT / "data"
GROCERY = DATA / "grocery"
MODELS = ROOT / "models"
REPORTS = ROOT / "reports"

INTENTS = ["cow", "low_fat", "diabetic", "organic", "powder"]
CLASSES = INTENTS + ["other"]
POWDER_TERMS = ("powder", "powdered", "pulbos")


def load_products(path: Path = DATA / "products.json") -> list[dict]:
    return json.loads(path.read_text(encoding="utf-8"))


def load_queries(path: Path = DATA / "queries.csv") -> list[dict]:
    with path.open(encoding="utf-8", newline="") as fh:
        return list(csv.DictReader(fh))


def load_qrels(path: Path = DATA / "qrels.csv") -> dict[str, dict[str, int]]:
    qrels: dict[str, dict[str, int]] = {}
    if not path.exists():
        return qrels
    with path.open(encoding="utf-8", newline="") as fh:
        for row in csv.DictReader(fh):
            grade = str(row.get("grade") or "").strip()
            query = (row.get("query") or "").strip()
            key = (row.get("product_key") or "").strip()
            if grade == "" or query == "" or key == "":
                continue
            qrels.setdefault(query, {})[key] = int(grade)
    return qrels


def load_grocery_products() -> list[dict]:
    return load_products(GROCERY / "products.json")


def load_grocery_queries() -> list[dict]:
    return load_queries(GROCERY / "queries.csv")


def load_grocery_qrels() -> dict[str, dict[str, int]]:
    """Grocery grades. Blank grade cells are skipped, not treated as 0."""
    return load_qrels(GROCERY / "qrels.csv")


def load_live_products(exclude_names: set[str]) -> tuple[list[dict], "np.ndarray"]:
    """Products exported from the live database with reviewed milk labels
    (data/live_product_labels.csv), one row per distinct name."""
    import numpy as np

    with (DATA / "live_product_labels.csv").open(encoding="utf-8", newline="") as fh:
        labels = {row["name"]: int(row["is_milk"]) for row in csv.DictReader(fh)}
    products, seen = [], set(exclude_names)
    for p in load_products(DATA / "live_products.json"):
        if p["name"] in labels and p["name"] not in seen:
            seen.add(p["name"])
            products.append(p)
    return products, np.array([labels[p["name"]] for p in products])


def is_milk(product: dict) -> bool:
    return bool((product.get("specs") or {}).get("is_milk"))


def is_powder(product: dict) -> bool:
    """Prefer specs.form; otherwise name/description (live catalog may lack form)."""
    specs = product.get("specs") or {}
    form = specs.get("form")
    if form == "powder":
        return True
    if form == "liquid":
        return False
    blob = f"{product.get('name') or ''} {product.get('description') or ''}".lower()
    return any(term in blob for term in POWDER_TERMS)


def grade(intent: str, product: dict) -> int:
    """2 = matches the intent, 1 = milk with the wrong attribute, 0 = not relevant."""
    specs = product.get("specs") or {}
    if not specs.get("is_milk"):
        return 0

    source, fat, sugar = specs.get("source"), specs.get("fat"), specs.get("sugar")

    if intent == "cow":
        if source == "cow":
            return 1 if sugar == "sweetened" else 2
        return 0 if source == "plant" else 1
    if intent == "low_fat":
        return 2 if fat in ("low", "non") else 1
    if intent == "diabetic":
        if sugar in ("no_added", "unsweetened"):
            return 2
        return 0 if sugar == "sweetened" else 1
    if intent == "organic":
        return 2 if specs.get("organic") else 1
    if intent == "powder":
        return 2 if is_powder(product) else 1
    return 0


def normalize_query(text: str) -> str:
    """LaBSE is cased; shoppers type "Gatas" and "gatas" interchangeably."""
    return " ".join(text.lower().split())


def product_text(product: dict) -> str:
    parts = [product.get("name") or "", product.get("description") or "", product.get("category") or ""]
    return ". ".join(p.strip().rstrip(".") for p in parts if p and p.strip())
