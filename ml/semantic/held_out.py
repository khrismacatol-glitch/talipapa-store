"""Thesis held-out queries. Production training must not use these texts."""
from __future__ import annotations

import csv
from pathlib import Path

from .data import ROOT, normalize_query

EXPERIMENT = ROOT / "experiments" / "cebu_ca_hyltr"
SPLITS_PATH = EXPERIMENT / "splits.csv"


def load_held_out_queries(path: Path = SPLITS_PATH) -> list[str]:
    if not path.exists():
        return []
    out = []
    with path.open(encoding="utf-8", newline="") as fh:
        for row in csv.DictReader(fh):
            if (row.get("split") or "").strip() != "test":
                continue
            text = (row.get("query") or "").strip()
            if text:
                out.append(text)
    return out


def held_out_norms(path: Path = SPLITS_PATH) -> set[str]:
    return {normalize_query(q) for q in load_held_out_queries(path)}


def is_held_out(query: str, blocked: set[str] | None = None) -> bool:
    blocked = held_out_norms() if blocked is None else blocked
    return normalize_query(query) in blocked
