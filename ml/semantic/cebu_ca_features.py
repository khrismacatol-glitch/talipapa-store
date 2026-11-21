"""Cebuano attribute / negation features for the candidate ranker only.

Does not change production VOC or LambdaMART. Closed phrase inventory; no GPT.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field

from .data import is_powder, normalize_query

_TOKEN = re.compile(r"[\w']+", re.UNICODE)
_QTY = re.compile(
    r"(\d+(?:\.\d+)?)\s*(litro|liters?|ml|mL|grams?|kilos?|kg|g|l)\b",
    re.IGNORECASE,
)

POS_LOW_FAT = (
    "dili makataba", "dili makatambok", "dili makapatambok", "walay tambok",
    "dili kaayo tambok", "low fat", "low-fat", "non-fat", "nonfat", "skim",
)
POS_NO_SUGAR = (
    "walay dugang nga asukal", "walay asukal", "walay sugar",
    "no sugar added", "no sugar", "sugar free", "sugar-free", "unsweetened",
)
NEG_MARKERS = ("pero dili", "dili", "hindi", "walay", "walang", "not")

ATTR_ALIASES: list[tuple[str, tuple[str, ...]]] = [
    ("powder", ("powdered", "powder", "pulbos")),
    ("chocolate", ("chocolate", "choco")),
    ("fresh", ("presko", "fresh")),
    ("condensed", ("condensada", "condensed")),
    ("creamer", ("creamer", "kremdensada", "evaporada", "evaporated")),
    ("organic", ("organic",)),
    ("soya", ("soya", "soy", "soybean")),
    ("cow", ("baka", "cow")),
    ("low_fat", ("low fat", "low-fat")),
    ("sweetened", ("tam-is", "tamis", "sweetened", "sweet")),
    ("coffee", ("kape", "coffee")),
    ("baby", ("pangbaby", "newborn", "months", "year old", "years old")),
    ("adult", ("adulto", "tigulang", "edaran", "ensure", "anlene")),
]


@dataclass
class QuerySlots:
    positive: set[str] = field(default_factory=set)
    negated: set[str] = field(default_factory=set)
    brand: str | None = None
    quantity_ml: float | None = None
    quantity_g: float | None = None


def _has(text: str, phrase: str) -> bool:
    return phrase in text


def parse_query(query: str, brands: list[str] | None = None) -> QuerySlots:
    raw = normalize_query(query)
    slots = QuerySlots()
    work = f" {raw} "

    for phrase in POS_LOW_FAT:
        if _has(work, phrase):
            slots.positive.add("low_fat")
            work = work.replace(phrase, " ")
    for phrase in POS_NO_SUGAR:
        if _has(work, phrase):
            slots.positive.add("no_sugar")
            work = work.replace(phrase, " ")

    # Longest-first so "pero dili" wins over "dili".
    markers = sorted(NEG_MARKERS, key=len, reverse=True)
    consumed = set()
    for marker in markers:
        start = 0
        while True:
            i = work.find(marker, start)
            if i < 0:
                break
            rest = work[i + len(marker) :]
            rest = re.sub(r"^\s*(?:sa|from|nga|ng|pang|a|the)\s+", "", rest)
            hit = _first_attr(rest)
            if hit and hit not in consumed:
                slots.negated.add(hit)
                consumed.add(hit)
            start = i + len(marker)

    for attr, phrases in ATTR_ALIASES:
        if attr in slots.negated:
            continue
        if any(_has(work, p) for p in phrases):
            slots.positive.add(attr)

    for brand in sorted(brands or [], key=len, reverse=True):
        if brand and brand in raw:
            slots.brand = brand
            break

    qty = _QTY.search(raw)
    if qty:
        amount = float(qty.group(1))
        unit = qty.group(2).lower()
        if unit in {"litro", "liter", "liters", "l"}:
            slots.quantity_ml = amount * 1000
        elif unit == "ml":
            slots.quantity_ml = amount
        elif unit in {"kilos", "kilo", "kg"}:
            slots.quantity_g = amount * 1000
        else:
            slots.quantity_g = amount
    return slots


def _first_attr(rest: str) -> str | None:
    rest = rest.strip()
    for attr, phrases in sorted(ATTR_ALIASES, key=lambda x: -max(len(p) for p in x[1])):
        for p in phrases:
            if rest.startswith(p) or rest.startswith(p.replace("-", " ")):
                return attr
    return None


def product_attrs(product: dict) -> dict:
    specs = product.get("specs") or {}
    blob = f"{product.get('name') or ''} {product.get('description') or ''}".lower()
    form = specs.get("form")
    if not form:
        form = "powder" if is_powder(product) else None
    return {
        "powder": form == "powder" or any(t in blob for t in ("powder", "pulbos")),
        "chocolate": any(t in blob for t in ("chocolate", "choco")),
        "fresh": "fresh" in blob or "presko" in blob,
        "condensed": "condens" in blob,
        "creamer": "creamer" in blob or "evaporad" in blob,
        "organic": bool(specs.get("organic")) or "organic" in blob,
        "soya": any(t in blob for t in ("soy", "soya", "oat", "almond", "plant")),
        "cow": (specs.get("source") == "cow") or "cow" in blob,
        "low_fat": specs.get("fat") in ("low", "non") or "low fat" in blob or "skim" in blob or "non-fat" in blob,
        "no_sugar": specs.get("sugar") in ("no_added", "unsweetened") or "no added sugar" in blob or "unsweetened" in blob,
        "sweetened": specs.get("sugar") == "sweetened" or "sweetened" in blob,
        "coffee": "coffee" in blob or "kape" in blob,
        "baby": any(t in blob for t in ("infant", "baby", "1-3", "newborn")),
        "adult": any(t in blob for t in ("adult", "anlene", "ensure", "glucerna")),
        "brand": str(specs.get("brand") or "").lower() or None,
        "volume_ml": specs.get("volume_ml"),
        "name": blob,
    }


def feature_row(query: str, product: dict, brands: list[str] | None = None) -> dict[str, float]:
    slots = parse_query(query, brands)
    p = product_attrs(product)
    pos = slots.positive
    match = 0.0
    if pos:
        hits = sum(1 for a in pos if p.get(a))
        match = hits / len(pos)
    neg_hit = 1.0 if any(p.get(a) for a in slots.negated) else 0.0
    brand = 0.0
    if slots.brand:
        brand = 1.0 if slots.brand in (p.get("brand") or "") or slots.brand in (p.get("name") or "") else 0.0
    form = 0.0
    if "powder" in pos:
        form = 1.0 if p.get("powder") else 0.0
    if "powder" in slots.negated:
        form = 1.0 if not p.get("powder") else 0.0
    if "fresh" in pos:
        form = max(form, 1.0 if p.get("fresh") and not p.get("powder") else 0.0)
    qty = 0.0
    if slots.quantity_ml and p.get("volume_ml"):
        qty = 1.0 if abs(float(p["volume_ml"]) - slots.quantity_ml) / max(slots.quantity_ml, 1) <= 0.15 else 0.0
    if slots.quantity_g:
        m = re.search(r"(\d+(?:\.\d+)?)\s*g", p.get("name") or "")
        if m:
            grams = float(m.group(1))
            qty = 1.0 if abs(grams - slots.quantity_g) / max(slots.quantity_g, 1) <= 0.15 else 0.0
    return {
        "attr_match": match,
        "neg_violate": neg_hit,
        "brand_match": brand,
        "form_match": form,
        "qty_match": qty,
    }


def catalog_brands(products: list[dict]) -> list[str]:
    found = []
    for p in products:
        b = str((p.get("specs") or {}).get("brand") or "").lower().strip()
        if b and b not in found:
            found.append(b)
    return found


def minmax(values) -> list[float]:
    xs = list(values)
    if not xs:
        return []
    lo, hi = min(xs), max(xs)
    if hi - lo < 1e-9:
        return [0.0] * len(xs)
    return [(x - lo) / (hi - lo) for x in xs]


def combine_scores(hybrid_scores: list[float], rows: list[dict[str, float]], weights: dict[str, float]) -> list[float]:
    base = minmax(hybrid_scores)
    out = []
    for i, row in enumerate(rows):
        s = base[i]
        s += weights.get("attr_match", 0.0) * row["attr_match"]
        s -= weights.get("neg_violate", 0.0) * row["neg_violate"]
        s += weights.get("brand_match", 0.0) * row["brand_match"]
        s += weights.get("form_match", 0.0) * row["form_match"]
        s += weights.get("qty_match", 0.0) * row["qty_match"]
        out.append(s)
    return out
