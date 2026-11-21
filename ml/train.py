"""Train the intent classifier, product milk classifier and LambdaMART ranker.

Only the `train` split of ml/data/queries.csv is used. Test queries (including
the four required Cebuano queries) are never seen here. Feedback qrels are
optional and never mixed into the frozen test split.
"""
from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone
from pathlib import Path

import joblib
import lightgbm as lgb
import numpy as np
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, precision_recall_curve, precision_score, recall_score
from sklearn.model_selection import StratifiedKFold, cross_val_predict, cross_val_score, train_test_split

from semantic.data import (
    CLASSES, INTENTS, MODELS, is_milk, load_live_products, load_products, load_qrels, load_queries, normalize_query, product_text,
)
from semantic.embedder import MODEL_ID, LabseEmbedder
from semantic.feedback import FeedbackIndex, load_feedback_qrels
from semantic.held_out import held_out_norms, load_held_out_queries
from semantic.ranker import ALL_FEATURES, BASE_FEATURES, TEXT_FEATURES, build_features

SEED = 13
QUERY_MILK_THRESHOLD = 0.5


def pick_threshold(y: np.ndarray, probs: np.ndarray, beta: float = 0.5) -> float:
    """Product gate threshold that maximizes F-beta on out-of-fold
    predictions. beta < 1 favours precision: keeping non-milk out of milk
    results matters more than recalling every borderline milk product."""
    precision, recall, thresholds = precision_recall_curve(y, probs)
    f = (1 + beta**2) * precision * recall / np.maximum(beta**2 * precision + recall, 1e-9)
    return float(thresholds[int(np.argmax(f[:-1]))])


def logreg() -> LogisticRegression:
    return LogisticRegression(C=4.0, max_iter=5000, class_weight="balanced", random_state=SEED)


def train_ranker(X: np.ndarray, y: np.ndarray, groups: list[int]) -> lgb.LGBMRanker:
    model = lgb.LGBMRanker(
        objective="lambdarank",
        n_estimators=300,
        learning_rate=0.05,
        num_leaves=15,
        min_child_samples=5,
        subsample=0.9,
        subsample_freq=1,
        colsample_bytree=0.9,
        label_gain=[0, 1, 3],
        random_state=SEED,
        verbose=-1,
    )
    model.fit(X, y, group=groups)
    return model


def _near_test(query: str, q_emb: np.ndarray, test_emb: np.ndarray, threshold: float = 0.92) -> bool:
    if test_emb.size == 0:
        return False
    v = q_emb / (np.linalg.norm(q_emb) or 1.0)
    sims = test_emb @ v
    return bool(np.max(sims) >= threshold)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--out-dir", default=str(MODELS), help="Directory for model artifacts")
    parser.add_argument("--with-feedback", action="store_true", help="Add implicit-feedback pairs when qrels exist")
    args = parser.parse_args()
    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    embedder = LabseEmbedder(MODEL_ID)
    products = load_products()
    blocked = held_out_norms()
    queries = [
        q for q in load_queries()
        if q["split"] == "train" and normalize_query(q["query"]) not in blocked
    ]
    qrels = load_qrels()
    version = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    name_to_idx = {normalize_query(p["name"]): i for i, p in enumerate(products)}
    fb_rows = load_feedback_qrels() if args.with_feedback else []
    if fb_rows and blocked:
        fb_rows = [row for row in fb_rows if normalize_query(row["query"]) not in blocked]
    use_feedback = bool(fb_rows)
    feature_names = ALL_FEATURES if use_feedback else BASE_FEATURES

    # 1. Query intent classifier.
    q_texts = [normalize_query(q["query"]) for q in queries]
    q_emb = embedder.encode(q_texts)
    q_labels = np.array([CLASSES.index(q["intent"]) for q in queries])
    cv = StratifiedKFold(n_splits=5, shuffle=True, random_state=SEED)
    intent_cv = cross_val_score(logreg(), q_emb, q_labels, cv=cv).mean()
    # Out-of-fold probabilities so ranker features look like test-time ones.
    q_probs_oof = cross_val_predict(logreg(), q_emb, q_labels, cv=cv, method="predict_proba")
    intent_clf = logreg().fit(q_emb, q_labels)

    # 2. Product milk classifier, trained on the benchmark catalog plus the
    #    labelled live catalog. 30% of live products are held out to measure
    #    how well the gate generalizes to products it has never seen.
    p_emb = embedder.encode([product_text(p) for p in products])
    name_emb = embedder.encode([p.get("name") or "" for p in products])
    p_labels = np.array([int(is_milk(p)) for p in products])
    live, live_labels = load_live_products({p["name"] for p in products})
    live_emb = embedder.encode([product_text(p) for p in live])
    live_train, live_test = train_test_split(
        np.arange(len(live)), test_size=0.3, stratify=live_labels, random_state=SEED,
    )
    gate_X = np.vstack([p_emb, live_emb[live_train]])
    gate_y = np.concatenate([p_labels, live_labels[live_train]])
    p_cv = StratifiedKFold(n_splits=5, shuffle=True, random_state=SEED)
    gate_threshold = pick_threshold(gate_y, cross_val_predict(logreg(), gate_X, gate_y, cv=p_cv, method="predict_proba")[:, 1])
    held_out = logreg().fit(gate_X, gate_y).predict_proba(live_emb[live_test])[:, 1] >= gate_threshold
    gate_report = {
        "threshold": round(gate_threshold, 4),
        "held_out_live_products": int(len(live_test)),
        "held_out_milk_products": int(live_labels[live_test].sum()),
        "accuracy": round(float(accuracy_score(live_labels[live_test], held_out)), 4),
        "precision": round(float(precision_score(live_labels[live_test], held_out, zero_division=0)), 4),
        "recall": round(float(recall_score(live_labels[live_test], held_out, zero_division=0)), 4),
    }
    # Final gate on all labelled products. Out-of-fold P(milk) picks the
    # threshold and is the ranker feature for the benchmark products.
    all_X = np.vstack([p_emb, live_emb])
    all_y = np.concatenate([p_labels, live_labels])
    milk_cv = cross_val_score(logreg(), all_X, all_y, cv=p_cv).mean()
    all_oof = cross_val_predict(logreg(), all_X, all_y, cv=p_cv, method="predict_proba")[:, 1]
    product_threshold = pick_threshold(all_y, all_oof)
    p_milk_oof = all_oof[: len(products)]
    milk_clf = logreg().fit(all_X, all_y)

    # 3. Intent prototypes: mean training-query embedding per intent.
    prototypes = np.stack([q_emb[q_labels == CLASSES.index(i)].mean(axis=0) for i in INTENTS])
    prototypes /= np.linalg.norm(prototypes, axis=1, keepdims=True)

    # 4. LambdaMART over (milk query, product) pairs.
    test_texts = [normalize_query(q["query"]) for q in load_queries() if q["split"] == "test"]
    for text in load_held_out_queries():
        n = normalize_query(text)
        if n not in test_texts:
            test_texts.append(n)
    test_emb = embedder.encode(test_texts) if test_texts else np.zeros((0, 1))
    if len(test_emb):
        test_emb = test_emb / np.clip(np.linalg.norm(test_emb, axis=1, keepdims=True), 1e-9, None)

    fb_index = FeedbackIndex.build(fb_rows, embedder) if use_feedback else FeedbackIndex()

    X, y, groups = [], [], []
    for qi, q in enumerate(queries):
        if q["intent"] == "other":
            continue
        feats = build_features(q["query"], q_emb[qi], q_probs_oof[qi], products, p_emb, name_emb, p_milk_oof, prototypes)
        if use_feedback:
            feats = np.hstack([feats, fb_index.features(q["query"], q_emb[qi], products)])
        judged = qrels.get(q["query"], {})
        X.append(feats)
        y.extend(judged.get(p["key"], 0) for p in products)
        groups.append(len(products))

    fb_groups = 0
    if use_feedback:
        by_query: dict[str, dict[str, int]] = {}
        for row in fb_rows:
            qn = normalize_query(row["query"])
            by_query.setdefault(qn, {})[normalize_query(row["product_name"])] = int(row["grade"])
        for qn, name_grades in by_query.items():
            qv = embedder.encode([qn])[0]
            if _near_test(qn, qv, test_emb):
                continue
            matched = {name_to_idx[n]: g for n, g in name_grades.items() if n in name_to_idx}
            if not matched:
                continue
            q_probs = intent_clf.predict_proba(qv[None, :])[0]
            feats = build_features(qn, qv, q_probs, products, p_emb, name_emb, p_milk_oof, prototypes)
            feats = np.hstack([feats, fb_index.features(qn, qv, products)])
            labels = [matched.get(i, 0) for i in range(len(products))]
            if max(labels) <= 0:
                continue
            X.append(feats)
            y.extend(labels)
            groups.append(len(products))
            fb_groups += 1

    X = np.vstack(X)
    y = np.array(y)

    ranker = train_ranker(X, y, groups)
    ranker.booster_.save_model(str(out_dir / "ranker.txt"))

    text_idx = [feature_names.index(f) for f in TEXT_FEATURES]
    ranker_text = train_ranker(X[:, text_idx], y, groups)
    ranker_text.booster_.save_model(str(out_dir / "ranker_text_only.txt"))

    joblib.dump(intent_clf, out_dir / "intent_classifier.joblib")
    joblib.dump(milk_clf, out_dir / "product_milk_classifier.joblib")
    np.save(out_dir / "intent_prototypes.npy", prototypes)
    if use_feedback:
        fb_index.save(out_dir / "feedback_index.npz")

    importance = dict(sorted(
        zip(feature_names, ranker.booster_.feature_importance("gain").round(2).tolist()),
        key=lambda kv: -kv[1],
    ))
    meta = {
        "version": version,
        "embedding_model": MODEL_ID,
        "classes": CLASSES,
        "features": feature_names,
        "text_features": TEXT_FEATURES,
        "query_milk_threshold": QUERY_MILK_THRESHOLD,
        "product_milk_threshold": round(product_threshold, 4),
        "train_queries": len(queries),
        "feedback_query_groups": fb_groups,
        "train_pairs": int(len(y)),
        "products": len(products),
        "intent_classifier_cv_accuracy": round(float(intent_cv), 4),
        "product_milk_classifier_cv_accuracy": round(float(milk_cv), 4),
        "product_milk_classifier_products": int(len(all_y)),
        "milk_gate_held_out": gate_report,
        "ranker_feature_gain": importance,
        "with_feedback": use_feedback,
        "held_out_blocked": len(blocked),
    }
    (out_dir / "meta.json").write_text(json.dumps(meta, indent=2))
    print(json.dumps({k: v for k, v in meta.items() if k != "ranker_feature_gain"}, indent=2))
    print("top features:", list(importance)[:8])


if __name__ == "__main__":
    main()
