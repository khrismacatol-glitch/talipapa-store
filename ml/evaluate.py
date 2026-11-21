"""Evaluate ranking systems on the held-out test queries.

Systems:
  keyword            existing Laravel keyword search (from php artisan search:evaluate)
  laravel_semantic   Laravel end to end through the ML service (same file)
  labse_cosine       zero-shot LaBSE cosine similarity, no training
  labse_cosine_gate  cosine + trained milk gate
  ltr_text_only      LambdaMART on text/embedding features only + gate
  ltr_full           LambdaMART on text + product spec features + gate (production)
"""
from __future__ import annotations

import json
from collections import defaultdict

import lightgbm as lgb
import numpy as np
from sklearn.metrics import f1_score

from semantic.data import CLASSES, INTENTS, MODELS, REPORTS, is_milk, load_products, load_qrels, load_queries, product_text
from semantic.metrics import evaluate_run, macro
from semantic.ranker import BASE_FEATURES, TEXT_FEATURES, SemanticRanker, build_features

REQUIRED = ["Gatas sa baka", "Gatas nga dili makataba", "Gatas para sa mga diabetic", "Gatas na organic"]
METRICS = ["P@5", "R@5", "cR@5", "NDCG@5", "MRR", "MAP", "Leak@5"]
SYSTEM_ORDER = ["keyword", "labse_cosine", "labse_cosine_gate", "ltr_text_only", "ltr_full", "laravel_semantic"]


def main() -> None:
    REPORTS.mkdir(exist_ok=True)
    products = load_products()
    keys = [p["key"] for p in products]
    milk_keys = {p["key"] for p in products if is_milk(p)}
    qrels = load_qrels()
    test = [q for q in load_queries() if q["split"] == "test"]
    milk_test = [q for q in test if q["intent"] != "other"]

    ranker = SemanticRanker()
    text_only = lgb.Booster(model_file=str(MODELS / "ranker_text_only.txt"))
    text_idx = [BASE_FEATURES.index(f) for f in TEXT_FEATURES]
    p_emb = ranker.embedder.encode([product_text(p) for p in products])
    name_emb = ranker.embedder.encode([p["name"] for p in products])
    p_milk = ranker.milk_clf.predict_proba(p_emb)[:, 1]
    gate = p_milk >= ranker.product_threshold

    runs: dict[str, dict[str, list[str]]] = defaultdict(dict)
    laravel_path = REPORTS / "laravel_runs.json"
    if laravel_path.exists():
        laravel = json.loads(laravel_path.read_text(encoding="utf-8"))
        for mode, name in (("keyword", "keyword"), ("semantic", "laravel_semantic")):
            for q, ranked in laravel.get(mode, {}).items():
                runs[name][q] = ranked

    # Intent classification on every test query, including non-milk ones.
    intent_hits, routing_hits = 0, 0
    y_true, y_pred = [], []
    by_lang_true: dict[str, list[str]] = defaultdict(list)
    by_lang_pred: dict[str, list[str]] = defaultdict(list)
    confusion = {c: {d: 0 for d in CLASSES} for c in CLASSES}
    for q in test:
        _, probs = ranker.intent_probs(q["query"])
        predicted = CLASSES[int(np.argmax(probs))]
        confusion[q["intent"]][predicted] += 1
        intent_hits += predicted == q["intent"]
        y_true.append(q["intent"])
        y_pred.append(predicted)
        lang = q.get("lang") or "und"
        by_lang_true[lang].append(q["intent"])
        by_lang_pred[lang].append(predicted)
        is_milk_query = (1 - probs[CLASSES.index("other")]) >= ranker.query_threshold
        routing_hits += is_milk_query == (q["intent"] != "other")

    def order(scores: np.ndarray, mask: np.ndarray | None = None) -> list[str]:
        idx = np.argsort(-scores, kind="stable")
        return [keys[i] for i in idx if mask is None or mask[i]]

    for q in milk_test:
        text = q["query"]
        q_emb, probs = ranker.intent_probs(text)
        feats = build_features(text, q_emb, probs, products, p_emb, name_emb, p_milk, ranker.prototypes)
        ltr_feats = ranker.model_features(text, q_emb, probs, products, p_emb, name_emb, p_milk)
        cos = p_emb @ q_emb
        runs["labse_cosine"][text] = order(cos)
        runs["labse_cosine_gate"][text] = order(cos, gate)
        runs["ltr_text_only"][text] = order(text_only.predict(feats[:, text_idx]), gate)
        runs["ltr_full"][text] = order(ranker.ranker.predict(ltr_feats), gate)

    results: dict[str, dict] = {}
    for system, by_query in runs.items():
        per_query = {
            q["query"]: evaluate_run(by_query.get(q["query"], []), qrels.get(q["query"], {}), milk_keys)
            for q in milk_test
        }
        by_intent = defaultdict(list)
        for q in milk_test:
            by_intent[q["intent"]].append(per_query[q["query"]])
        results[system] = {
            "macro": macro(list(per_query.values())),
            "by_intent": {i: macro(rows) for i, rows in by_intent.items()},
            "required": {q: per_query[q] for q in REQUIRED if q in per_query},
        }

    names = {p["key"]: p["name"] for p in products}
    summary = {
        "test_queries": len(test),
        "milk_test_queries": len(milk_test),
        "intent_accuracy": intent_hits / len(test),
        "intent_macro_f1": float(f1_score(y_true, y_pred, average="macro", labels=CLASSES, zero_division=0)),
        "intent_by_lang": {
            lang: {
                "accuracy": sum(a == b for a, b in zip(by_lang_true[lang], by_lang_pred[lang])) / len(by_lang_true[lang]),
                "n": len(by_lang_true[lang]),
            }
            for lang in sorted(by_lang_true)
        },
        "milk_routing_accuracy": routing_hits / len(test),
        "intent_confusion": confusion,
        "model_version": ranker.model_version,
        "systems": {k: results[k] for k in SYSTEM_ORDER if k in results},
        "required_top5": {
            system: {q: [names.get(k, k) for k in runs[system].get(q, [])[:5]] for q in REQUIRED}
            for system in ("keyword", "ltr_full")
            if system in runs
        },
    }
    (REPORTS / "eval.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False), encoding="utf-8")
    markdown = render_markdown(summary)
    (REPORTS / "eval.md").write_text(markdown, encoding="utf-8")
    print(markdown)


def table(rows: dict[str, dict[str, float]]) -> list[str]:
    lines = ["| System | " + " | ".join(METRICS) + " |", "|---|" + "---:|" * len(METRICS)]
    for name, m in rows.items():
        lines.append(f"| {name} | " + " | ".join(f"{m[k]:.3f}" for k in METRICS) + " |")
    return lines


def render_markdown(s: dict) -> str:
    systems = s["systems"]
    out = [
        "# Milk search evaluation (held-out test queries)",
        "",
        f"{s['milk_test_queries']} milk test queries ({s['test_queries']} test queries in total). "
        "Relevant = grade 2. cR@5 = recall against min(5, |relevant|). Leak@5 = share of the top 5 that are not milk.",
        "",
        f"Intent classifier accuracy: {s['intent_accuracy']:.3f}. "
        f"Macro-F1: {s.get('intent_macro_f1', 0):.3f}. "
        f"Milk vs non-milk routing accuracy: {s['milk_routing_accuracy']:.3f}.",
        "",
        "## Macro average, all milk test queries",
        "",
        *table({k: v["macro"] for k, v in systems.items()}),
    ]
    for intent in INTENTS:
        out += ["", f"## Intent: {intent}", ""]
        out += table({k: v["by_intent"][intent] for k, v in systems.items() if intent in v["by_intent"]})
    out += ["", "## Required Cebuano queries"]
    for q in REQUIRED:
        out += ["", f"### {q}", ""]
        out += table({k: v["required"][q] for k, v in systems.items() if q in v["required"]})
        for system, tops in s["required_top5"].items():
            out += ["", f"Top 5 ({system}): " + "; ".join(f"{i + 1}. {n}" for i, n in enumerate(tops[q]))]
    return "\n".join(out) + "\n"


if __name__ == "__main__":
    main()
