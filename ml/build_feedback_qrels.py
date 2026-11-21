"""Turn exported search sessions into graded feedback qrels.

Does not invent labels. If the export is empty or below the evidence
threshold, the output file has only a header.
"""
from __future__ import annotations

from semantic.feedback import (
    EXPORT_PATH, QRELS_PATH, build_feedback_qrels, load_export, write_feedback_qrels,
)


def main() -> None:
    export = load_export(EXPORT_PATH)
    rows = build_feedback_qrels(export)
    write_feedback_qrels(rows, QRELS_PATH)
    print(f"{len(export.get('sessions') or [])} sessions -> {len(rows)} feedback judgements")


if __name__ == "__main__":
    main()
