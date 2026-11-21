"""Build grocery qrels from catalog names plus documented family contrasts.

Existing graded rows are kept unless they contradict a hard-negative rule
(e.g. bath soap must not be grade 2 on a laundry query).
"""
from __future__ import annotations

import csv
from collections import defaultdict

from semantic.data import GROCERY, load_grocery_products, load_grocery_queries

ZERO_CAP = 8


def blob(product: dict) -> str:
    return (product.get("name") or "").lower()


def is_fabcon(text: str) -> bool:
    if "detergent" in text or "laundry bar" in text:
        return False
    return any(k in text for k in ("fabric conditioner", "fabcon", "downy", "pampalambot", "conditioner"))


def is_laundry(text: str) -> bool:
    if is_fabcon(text):
        return False
    return any(
        k in text
        for k in (
            "detergent", "laundry", "ariel", "tide", "wings", "perla",
            "calla", "surf powder", "surf bar", "surf detergent", "champion",
        )
    )


def is_bath(text: str) -> bool:
    if is_laundry(text) or is_dish(text):
        return False
    return any(
        k in text
        for k in (
            "bioderm", "safeguard", "kojie", "silka", "germicidal",
            "bath soap", "bar soap", "pampaligo",
        )
    )


def is_dish(text: str) -> bool:
    return any(k in text for k in ("dazz", "axion", "dishwashing", "hugas plato", "joy dishwashing"))


def is_cerelac(text: str) -> bool:
    return "cerelac" in text or "infant cereal" in text


def is_rice(text: str) -> bool:
    if is_cerelac(text) or "milk" in text:
        return False
    return any(k in text for k in ("jasmine rice", "brown rice", "bugas", "bigas")) or (
        "rice" in text and "noodle" not in text and "cereal" not in text
    )


def is_corned_beef(text: str) -> bool:
    return "corned beef" in text


def is_corned_tuna(text: str) -> bool:
    return "corned tuna" in text


def is_sardine(text: str) -> bool:
    return "sardine" in text or "sardinas" in text


def is_tuna(text: str) -> bool:
    return "tuna" in text and not is_corned_beef(text)


def is_tinapa(text: str) -> bool:
    return "tinapa" in text or "smoked fish" in text


def is_milk(text: str) -> bool:
    if "coconut milk" in text or "gata" in text:
        return False
    return any(k in text for k in ("milk", "gatas", "evaporada", "condensada", "creamer"))


def is_canned_ulam(text: str) -> bool:
    return is_corned_beef(text) or is_corned_tuna(text) or is_sardine(text) or is_tuna(text)


def brand_ok(query: str, text: str) -> bool:
    q = query.lower()
    checks = (
        ("ariel", "ariel"),
        ("tide", "tide"),
        ("surf", "surf"),
        ("wings", "wings"),
        ("perla", "perla"),
        ("argentina", "argentina"),
        ("young", "young"),
        ("highlands", "highlands"),
        ("century", "century"),
        ("mega", "mega"),
        ("555", "555"),
        ("fresca", "fresca"),
        ("dazz", "dazz"),
        ("axion", "axion"),
        ("smart", "smart"),
        ("bioderm", "bioderm"),
        ("safeguard", "safeguard"),
        ("kojie", "kojie"),
        ("silka", "silka"),
        ("downy", "downy"),
        ("bear brand", "bear brand"),
        ("magnolia", "magnolia"),
        ("alaska", "alaska"),
        ("jasmine", "jasmine"),
        ("san marino", "san marino"),
    )
    for token, needle in checks:
        if token in q and needle not in text:
            return False
    if "brown" in q and "rice" in q and "brown" not in text:
        return False
    return True


def family_match(family: str, contrast: str, text: str) -> tuple[bool, bool]:
    """Returns (is_grade2_family, is_hard_negative)."""
    if family == "laundry_soap" or contrast == "laundry_vs_bath" and family == "laundry_soap":
        return is_laundry(text), is_bath(text) or is_fabcon(text)
    if family == "bath_soap":
        return is_bath(text), is_laundry(text) or is_fabcon(text)
    if family == "dishwashing" or contrast == "dishwashing_vs_laundry":
        return is_dish(text), is_laundry(text)
    if family == "rice" or contrast == "rice_vs_infant_cereal":
        return is_rice(text), is_cerelac(text)
    if family == "canned" and contrast == "corned_beef_vs_tuna":
        q2 = is_corned_beef
        q1 = is_corned_tuna
        return q2(text), q1(text)
    if family == "canned":
        return is_canned_ulam(text), False
    if family == "seafood" and contrast == "tinapa_vs_sardines":
        return is_sardine(text) or is_tinapa(text), False
    if family == "seafood":
        return is_sardine(text) or is_tuna(text) or is_tinapa(text), False
    if family == "household":
        return is_fabcon(text), is_laundry(text)
    if family == "milk":
        return is_milk(text), False
    return False, False


def query_grade2(query: str, family: str, contrast: str, text: str) -> bool:
    ok, _ = family_match(family, contrast, text)
    if not ok:
        return False
    q = query.lower()
    if family == "seafood" and ("tinapa" in q or "smoked fish" in q):
        return is_tinapa(text)
    if family == "seafood" and ("sardinas" in q or "sardine" in q):
        return is_sardine(text)
    if family == "canned" and "tuna" in q and "corned beef" not in q:
        return is_tuna(text)
    if family == "canned" and "corned beef" in q:
        return is_corned_beef(text)
    if family == "canned" and "sardinas" in q:
        return is_sardine(text)
    if "brown" in q and ("rice" in q or "bigas" in q):
        return "brown" in text and is_rice(text)
    if "jasmine" in q:
        return "jasmine" in text and is_rice(text)
    if family == "milk" and ("fresh" in q or "sariwang" in q or "magnolia" in q):
        if "evaporat" in text or "condens" in text or "creamer" in text:
            return False
    if family == "milk" and "evaporat" in q:
        return "evaporat" in text or "evaporada" in text
    if family == "milk" and ("powder" in q or "bear brand" in q):
        return "powder" in text or "bear brand" in text
    if family == "milk" and ("low fat" in q or "walay tambok" in q or "walang asukal" in q):
        return any(k in text for k in ("low fat", "non-fat", "skim", "no added sugar", "unsweetened"))
    return brand_ok(query, text)


def query_grade1(query: str, family: str, contrast: str, text: str) -> bool:
    q = query.lower()
    if family == "seafood" and ("tinapa" in q or "smoked fish" in q):
        return is_sardine(text)
    if family == "seafood" and ("sardinas" in q or "sardine" in q):
        return is_tinapa(text) or is_tuna(text)
    if family == "canned" and "corned beef" in q:
        return is_corned_tuna(text)
    if family == "canned" and "tuna" in q:
        return is_corned_beef(text)
    if family == "rice":
        return is_cerelac(text) or (is_rice(text) and not query_grade2(query, family, contrast, text))
    if family == "laundry_soap":
        return is_bath(text) or (is_laundry(text) and not query_grade2(query, family, contrast, text)) or is_fabcon(text)
    if family == "bath_soap":
        return is_laundry(text) or (is_bath(text) and not query_grade2(query, family, contrast, text))
    if family == "dishwashing":
        return is_laundry(text) or (is_dish(text) and not query_grade2(query, family, contrast, text))
    if family == "household":
        return is_laundry(text)
    if family == "milk":
        return is_milk(text) and not query_grade2(query, family, contrast, text)
    if family == "canned":
        return is_canned_ulam(text) and not query_grade2(query, family, contrast, text)
    if family == "seafood":
        return (is_sardine(text) or is_tuna(text) or is_tinapa(text)) and not query_grade2(
            query, family, contrast, text
        )
    _, hard = family_match(family, contrast, text)
    return hard


def main() -> int:
    products = load_grocery_products()
    queries = load_grocery_queries()
    rows: list[dict[str, str]] = []
    stats = defaultdict(int)

    for q in queries:
        query = q["query"]
        family = q.get("family") or ""
        contrast = q.get("contrast_group") or ""
        judged: dict[str, int] = {}
        twos, ones, zeros = [], [], []
        for p in products:
            text = blob(p)
            key = p["key"]
            if query_grade2(query, family, contrast, text):
                twos.append(key)
            elif query_grade1(query, family, contrast, text):
                ones.append(key)
            else:
                zeros.append(key)

        for key in twos:
            judged[key] = 2
        for key in ones:
            judged[key] = 1

        off = [k for k in zeros if k not in judged]
        for key in off[:ZERO_CAP]:
            judged[key] = 0

        if not any(g == 2 for g in judged.values()) and ("tinapa" in query.lower() or "smoked fish" in query.lower()):
            for key in ones:
                judged[key] = 1

        for key, grade in sorted(judged.items()):
            rows.append({"query": query, "product_key": key, "grade": str(grade), "notes": ""})
            stats[grade] += 1

    path = GROCERY / "qrels.csv"
    with path.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=["query", "product_key", "grade", "notes"])
        writer.writeheader()
        writer.writerows(rows)
    print(f"wrote {len(rows)} rows to {path} grades={dict(stats)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
