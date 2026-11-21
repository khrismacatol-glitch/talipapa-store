"""Copy approved provisional grades into the official train wide/long sheets.

Does not touch val, test, production, or experiment-root labeling_sheet.csv.
"""
from __future__ import annotations

import csv
import json
import shutil
from collections import Counter
from pathlib import Path

OUT = Path(__file__).resolve().parent
PROVISIONAL = OUT / "proposed_train_labels_product_first_wide.csv"
OFFICIAL_WIDE = OUT / "labeling_sheet_train_wide.csv"
APPROVED_WIDE = OUT / "labeling_sheet_train_wide.approved.csv"
OFFICIAL_LONG = OUT / "labeling_sheet_train.csv"
BACKUP = OUT / "labeling_sheet_train_wide.pre_copy_blank.csv"
VERIFY = OUT / "official_copy_verification.json"

META = {"train_index", "review_flag", "review_note", "product_class", "ambiguous",
        "query", "cluster_id", "split", "intent", "lang"}
GAPS = {"gatas nga ubos ang asukal", "kape nga 3 in 1"}


def load(path: Path) -> tuple[list[str], list[dict]]:
    with path.open(encoding="utf-8-sig", newline="") as fh:
        r = csv.DictReader(fh)
        return list(r.fieldnames or []), list(r)


def product_cols(fields: list[str]) -> list[str]:
    return [c for c in fields if c not in META]


def main() -> None:
    if not BACKUP.exists():
        shutil.copy2(OFFICIAL_WIDE, BACKUP)

    off_fields, official = load(OFFICIAL_WIDE)
    prop_fields, proposed = load(PROVISIONAL)
    off_keys = product_cols(off_fields)
    prop_keys = product_cols(prop_fields)

    errors: list[str] = []
    if official[0]["split"] != "train" or proposed[0]["split"] != "train":
        errors.append("split is not train")
    if len(official) != 171 or len(proposed) != 171:
        errors.append(f"query count official={len(official)} proposed={len(proposed)}")
    if off_keys != prop_keys:
        errors.append("product column order/IDs differ")
    if len(off_keys) != 42:
        errors.append(f"product columns={len(off_keys)}")

    for i, (a, b) in enumerate(zip(official, proposed), 1):
        if a["query"] != b["query"]:
            errors.append(f"query order mismatch at {i}: {a['query']!r} vs {b['query']!r}")
        if a["train_index"] != b["train_index"]:
            errors.append(f"train_index mismatch at {i}")
        if a.get("cluster_id") != b.get("cluster_id"):
            errors.append(f"cluster_id mismatch at {i}")

    if errors:
        raise SystemExit("pre-copy errors:\n" + "\n".join(errors[:20]))

    for a, b in zip(official, proposed):
        for k in off_keys:
            a[k] = b[k]

    def write_wide(path: Path) -> None:
        with path.open("w", encoding="utf-8-sig", newline="") as fh:
            w = csv.DictWriter(fh, fieldnames=off_fields)
            w.writeheader()
            w.writerows(official)

    write_wide(APPROVED_WIDE)
    wide_written_to = str(APPROVED_WIDE.name)
    locked = False
    try:
        write_wide(OFFICIAL_WIDE)
        wide_written_to = str(OFFICIAL_WIDE.name)
    except PermissionError:
        locked = True

    dist = Counter()
    no2 = 0
    gap_ok = {}
    for row in official:
        vals = [int(row[k]) for k in off_keys]
        dist.update(vals)
        if 2 not in vals:
            no2 += 1
        qn = row["query"].strip().lower()
        if qn in GAPS:
            gap_ok[row["query"]] = 2 not in vals

    copied = official
    mismatches = 0
    for a, b in zip(copied, proposed):
        if a["query"] != b["query"]:
            mismatches += 1
            continue
        for k in off_keys:
            if int(a[k]) != int(b[k]):
                mismatches += 1
                break
    _, disk = load(APPROVED_WIDE)
    for a, b in zip(disk, proposed):
        for k in off_keys:
            if int(a[k]) != int(b[k]):
                mismatches += 1
                break

    if OFFICIAL_LONG.exists():
        long_fields, long_rows = load(OFFICIAL_LONG)
        grades_by = {(r["query"], k): r[k] for r in copied for k in off_keys}
        for row in long_rows:
            row["grade"] = grades_by[(row["query"], row["product_key"])]
        with OFFICIAL_LONG.open("w", encoding="utf-8-sig", newline="") as fh:
            w = csv.DictWriter(fh, fieldnames=long_fields)
            w.writeheader()
            w.writerows(long_rows)

    expected = {2: 514, 1: 1907, 0: 4761}
    summary = {
        "backup": str(BACKUP.name),
        "official_wide": wide_written_to,
        "official_wide_locked": locked,
        "queries": len(copied),
        "product_ids": off_keys,
        "product_id_count": len(off_keys),
        "cells": len(copied) * len(off_keys),
        "grade_dist": {str(k): dist[k] for k in (0, 1, 2)},
        "queries_with_grade2": len(copied) - no2,
        "queries_without_grade2": no2,
        "catalog_gaps_have_no_grade2": gap_ok,
        "exact_match_to_provisional": mismatches == 0,
        "row_mismatches": mismatches,
        "counts_match_approved": dict(dist) == expected and (len(copied) - no2) == 127 and no2 == 44,
        "val_test_production_untouched": True,
        "lambdaMART_not_retrained": True,
        "errors": errors,
    }
    VERIFY.write_text(json.dumps(summary, indent=2), encoding="utf-8")
    print(json.dumps({k: v for k, v in summary.items() if k != "product_ids"}, indent=2))
    if mismatches or dict(dist) != expected:
        raise SystemExit("verification failed")
    if locked:
        raise SystemExit(
            "Grades verified on labeling_sheet_train_wide.approved.csv and the long sheet. "
            "Close labeling_sheet_train_wide.csv in the editor and rerun copy_approved_train_grades.py to replace it."
        )


if __name__ == "__main__":
    main()
