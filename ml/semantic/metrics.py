"""Ranking metrics. A product is relevant when its grade is 2."""
from __future__ import annotations

import math

K = 5


def precision_at_k(ranked: list[str], grades: dict[str, int], k: int = K) -> float:
    return sum(1 for key in ranked[:k] if grades.get(key, 0) >= 2) / k


def recall_at_k(ranked: list[str], grades: dict[str, int], k: int = K) -> float:
    relevant = sum(1 for g in grades.values() if g >= 2)
    return sum(1 for key in ranked[:k] if grades.get(key, 0) >= 2) / relevant if relevant else 0.0


def capped_recall_at_k(ranked: list[str], grades: dict[str, int], k: int = K) -> float:
    """Recall against min(k, |relevant|), so 1.0 is reachable when |relevant| > k."""
    relevant = sum(1 for g in grades.values() if g >= 2)
    return sum(1 for key in ranked[:k] if grades.get(key, 0) >= 2) / min(k, relevant) if relevant else 0.0


def ndcg_at_k(ranked: list[str], grades: dict[str, int], k: int = K) -> float:
    def dcg(gains: list[int]) -> float:
        return sum((2 ** g - 1) / math.log2(i + 2) for i, g in enumerate(gains))

    ideal = dcg(sorted(grades.values(), reverse=True)[:k])
    return dcg([grades.get(key, 0) for key in ranked[:k]]) / ideal if ideal else 0.0


def mrr(ranked: list[str], grades: dict[str, int]) -> float:
    for i, key in enumerate(ranked):
        if grades.get(key, 0) >= 2:
            return 1 / (i + 1)
    return 0.0


def average_precision(ranked: list[str], grades: dict[str, int]) -> float:
    relevant = sum(1 for g in grades.values() if g >= 2)
    hits, total = 0, 0.0
    for i, key in enumerate(ranked):
        if grades.get(key, 0) >= 2:
            hits += 1
            total += hits / (i + 1)
    return total / relevant if relevant else 0.0


def leakage_at_k(ranked: list[str], milk_keys: set[str], k: int = K) -> float:
    """Share of the top k that are not milk products."""
    top = ranked[:k]
    return sum(1 for key in top if key not in milk_keys) / len(top) if top else 0.0


def evaluate_run(ranked: list[str], grades: dict[str, int], milk_keys: set[str]) -> dict[str, float]:
    return {
        "P@5": precision_at_k(ranked, grades),
        "R@5": recall_at_k(ranked, grades),
        "cR@5": capped_recall_at_k(ranked, grades),
        "NDCG@5": ndcg_at_k(ranked, grades),
        "MRR": mrr(ranked, grades),
        "MAP": average_precision(ranked, grades),
        "Leak@5": leakage_at_k(ranked, milk_keys),
    }


def macro(rows: list[dict[str, float]]) -> dict[str, float]:
    return {m: sum(r[m] for r in rows) / len(rows) for m in rows[0]} if rows else {}


def binary_topk_counts(
    ranked: list[str],
    grades: dict[str, int],
    k: int = K,
    relevant_at: int = 2,
) -> dict[str, int]:
    """Actual-vs-predicted counts over a fully graded catalog. Predicted = top-k."""
    universe = set(grades)
    relevant = {key for key, grade in grades.items() if grade >= relevant_at}
    predicted = [key for key in ranked[:k] if key in universe]
    pred_set = set(predicted)
    tp = len(relevant & pred_set)
    fp = len(pred_set - relevant)
    fn = len(relevant - pred_set)
    tn = len(universe - relevant - pred_set)
    return {"TP": tp, "FP": fp, "FN": fn, "TN": tn}


def binary_rates(counts: dict[str, int]) -> dict[str, float]:
    tp, fp, fn, tn = counts["TP"], counts["FP"], counts["FN"], counts["TN"]
    total = tp + fp + fn + tn
    precision = tp / (tp + fp) if (tp + fp) else 0.0
    recall = tp / (tp + fn) if (tp + fn) else 0.0
    f1 = 2 * precision * recall / (precision + recall) if (precision + recall) else 0.0
    accuracy = (tp + tn) / total if total else 0.0
    return {
        "accuracy": accuracy,
        "precision": precision,
        "recall": recall,
        "f1": f1,
    }


def add_confusion(left: dict[str, int], right: dict[str, int]) -> dict[str, int]:
    return {key: left.get(key, 0) + right.get(key, 0) for key in ("TP", "FP", "FN", "TN")}
