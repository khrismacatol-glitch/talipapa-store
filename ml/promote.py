"""Copy a candidate model directory into ml/models after backing up current.

Does not invent metrics. Optional --require-eval compares reports/eval.json
against reports/candidate_eval.json when both exist.
"""
from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

from semantic.data import MODELS, REPORTS

ARTIFACTS = [
    "ranker.txt",
    "ranker_text_only.txt",
    "intent_classifier.joblib",
    "product_milk_classifier.joblib",
    "intent_prototypes.npy",
    "meta.json",
    "feedback_index.npz",
]
NDCG_TOLERANCE = 0.02
LEAK_TOLERANCE = 0.02


def _copy_artifacts(src: Path, dst: Path) -> None:
    dst.mkdir(parents=True, exist_ok=True)
    for name in ARTIFACTS:
        path = src / name
        if path.exists():
            shutil.copy2(path, dst / name)


def _ndcg(eval_path: Path) -> float | None:
    if not eval_path.exists():
        return None
    data = json.loads(eval_path.read_text(encoding="utf-8"))
    return float(data["systems"]["ltr_full"]["macro"]["NDCG@5"])


def _leak(eval_path: Path) -> float | None:
    if not eval_path.exists():
        return None
    data = json.loads(eval_path.read_text(encoding="utf-8"))
    return float(data["systems"]["ltr_full"]["macro"]["Leak@5"])


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--candidate", required=True)
    parser.add_argument("--skip-pytest", action="store_true")
    parser.add_argument("--require-eval", action="store_true")
    args = parser.parse_args()
    candidate = Path(args.candidate)
    if not (candidate / "ranker.txt").exists() or not (candidate / "meta.json").exists():
        print("candidate is incomplete")
        return 1

    current_eval = REPORTS / "eval.json"
    candidate_eval = REPORTS / "candidate_eval.json"
    if args.require_eval:
        cur_n, new_n = _ndcg(current_eval), _ndcg(candidate_eval)
        cur_l, new_l = _leak(current_eval), _leak(candidate_eval)
        if cur_n is None or new_n is None:
            print("missing eval.json; refuse to promote")
            return 1
        if new_n < cur_n - NDCG_TOLERANCE:
            print(f"NDCG@5 {new_n:.3f} < current {cur_n:.3f}")
            return 1
        if cur_l is not None and new_l is not None and new_l > cur_l + LEAK_TOLERANCE:
            print(f"Leak@5 {new_l:.3f} worse than current {cur_l:.3f}")
            return 1

    meta = json.loads((MODELS / "meta.json").read_text(encoding="utf-8"))
    stamp = meta.get("version") or datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    archive = MODELS / "archive" / str(stamp)
    _copy_artifacts(MODELS, archive)

    _copy_artifacts(candidate, MODELS)

    if not args.skip_pytest:
        result = subprocess.run(
            [sys.executable, "-m", "pytest", "tests/test_rank.py", "-q"],
            cwd=str(Path(__file__).resolve().parent),
        )
        if result.returncode != 0:
            print("pytest failed; restoring archive")
            _copy_artifacts(archive, MODELS)
            return result.returncode

    print(f"promoted {candidate} (previous snapshot {archive})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
