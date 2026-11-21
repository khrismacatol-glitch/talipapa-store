"""Price queries excluded from the Cebu-CA-HyLTR experiment only."""
from __future__ import annotations

import csv
from pathlib import Path

from .data import ROOT, normalize_query

EXPERIMENT = ROOT / "experiments" / "cebu_ca_hyltr"
EXCLUDE_PATH = EXPERIMENT / "excluded_price_queries.csv"
PRICE_TERMS = (
    "barato",
    "mahal",
    "presyo",
    "budget",
    "affordable",
    "cheap",
    "molapas",
)


def is_price_query(text: str) -> bool:
    tokens = set(normalize_query(text).split())
    return any(term in tokens for term in PRICE_TERMS)


def load_excluded_queries(path: Path = EXCLUDE_PATH) -> set[str]:
    if not path.exists():
        return set()
    out = set()
    with path.open(encoding="utf-8", newline="") as fh:
        for row in csv.DictReader(fh):
            q = (row.get("query") or "").strip()
            if q:
                out.add(q)
    return out


def is_excluded(query: str, blocked: set[str] | None = None) -> bool:
    blocked = load_excluded_queries() if blocked is None else blocked
    return query in blocked or normalize_query(query) in {normalize_query(q) for q in blocked}
