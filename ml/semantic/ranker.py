"""Feature extraction and ranking: LaBSE, BM25, intent/milk classifiers, LambdaMART."""
from __future__ import annotations

import json
import math
import re
from collections import Counter
from dataclasses import dataclass
from pathlib import Path

import joblib
import lightgbm as lgb
import numpy as np

from .data import CLASSES, INTENTS, MODELS, is_powder, normalize_query, product_text
from .embedder import LabseEmbedder
from .feedback import FEEDBACK_FEATURES, FeedbackIndex

TEXT_FEATURES = [
    "cos_text", "cos_name", "bm25", "p_milk", "q_milk",
    "q_cow", "q_low_fat", "q_diabetic", "q_organic", "q_powder",
    "proto_cow", "proto_low_fat", "proto_diabetic", "proto_organic", "proto_powder",
    "intent_proto_match", "q_milk_x_p_milk",
]
SPEC_FEATURES = [
    "src_cow", "src_other_animal", "src_plant",
    "fat_low", "sugar_free", "sweetened", "organic", "form_powder",
    "q_cow_x_src_cow", "q_cow_x_src_plant", "q_low_fat_x_fat_low",
    "q_diabetic_x_sugar_free", "q_diabetic_x_sweetened", "q_organic_x_organic",
    "q_powder_x_form_powder",
]
BASE_FEATURES = TEXT_FEATURES + SPEC_FEATURES
GROCERY_FEATURES = [
    "cos_desc",
    "cos_cat",
    "bm25_name",
    "bm25_desc",
    "bm25_cat",
    "token_overlap",
    "extra_term_hit",
]
EXTENDED_FEATURES = BASE_FEATURES + GROCERY_FEATURES
ALL_FEATURES = BASE_FEATURES + FEEDBACK_FEATURES
ALL_GROCERY_FEATURES = EXTENDED_FEATURES + FEEDBACK_FEATURES
SEMANTIC_COS_WEIGHT = 1.0
SEMANTIC_BM25_WEIGHT = 1.0
LTR_WEIGHT = 1.0
TEXT_WEIGHT = 1.25
# Closed-class / quantifier tokens. Leftover-term BM25 uses the rest of the query
# for every grocery search (gatas, sabon, delata, …).
_GROCERY_FILLERS = frozenset({
    "nga", "na", "sa", "para", "for", "the", "a", "an", "ang", "og",
    "ug", "ko", "mga", "nang", "and", "with", "dili", "hindi", "not", "walay",
    "walang", "from", "tanan", "tanang", "all", "every", "products", "product",
    "mga", "ay", "at", "of", "to", "in", "on",
})

_TOKEN = re.compile(r"[\w']+", re.UNICODE)


def tokenize(text: str) -> list[str]:
    return _TOKEN.findall(text.lower())


def bm25_scores(query: str, docs: list[str], k1: float = 1.2, b: float = 0.75) -> np.ndarray:
    tokenized = [tokenize(d) for d in docs]
    if not tokenized:
        return np.zeros(0)
    avg_len = sum(len(t) for t in tokenized) / len(tokenized) or 1.0
    df = Counter(term for toks in tokenized for term in set(toks))
    n = len(tokenized)
    q_terms = tokenize(query)
    scores = np.zeros(n)
    for i, toks in enumerate(tokenized):
        tf = Counter(toks)
        for term in q_terms:
            if term not in tf:
                continue
            idf = math.log(1 + (n - df[term] + 0.5) / (df[term] + 0.5))
            scores[i] += idf * tf[term] * (k1 + 1) / (tf[term] + k1 * (1 - b + b * len(toks) / avg_len))
    return scores


def spec_flags(specs: dict | None) -> dict[str, float]:
    s = specs or {}
    source, fat, sugar = s.get("source"), s.get("fat"), s.get("sugar")
    return {
        "src_cow": float(source == "cow"),
        "src_other_animal": float(source in ("carabao", "goat")),
        "src_plant": float(source == "plant"),
        "fat_low": float(fat in ("low", "non")),
        "sugar_free": float(sugar in ("no_added", "unsweetened")),
        "sweetened": float(sugar == "sweetened"),
        "organic": float(bool(s.get("organic"))),
    }


def build_features(
    query: str,
    q_emb: np.ndarray,
    q_probs: np.ndarray,
    products: list[dict],
    p_emb: np.ndarray,
    name_emb: np.ndarray,
    p_milk: np.ndarray,
    prototypes: np.ndarray,
) -> np.ndarray:
    """One row per product, columns in BASE_FEATURES order."""
    probs = dict(zip(CLASSES, q_probs))
    q_milk = 1.0 - probs["other"]
    cos_text = p_emb @ q_emb
    cos_name = name_emb @ q_emb
    bm25 = bm25_scores(query, [product_text(p) for p in products])
    proto = p_emb @ prototypes.T  # (N, len(INTENTS))
    intent_vec = np.array([probs[i] for i in INTENTS])

    rows = []
    for i, product in enumerate(products):
        f = spec_flags(product.get("specs"))
        f["form_powder"] = float(is_powder(product))
        row = {
            "cos_text": cos_text[i],
            "cos_name": cos_name[i],
            "bm25": bm25[i],
            "p_milk": p_milk[i],
            "q_milk": q_milk,
            "q_cow": probs["cow"],
            "q_low_fat": probs["low_fat"],
            "q_diabetic": probs["diabetic"],
            "q_organic": probs["organic"],
            "q_powder": probs["powder"],
            "proto_cow": proto[i, 0],
            "proto_low_fat": proto[i, 1],
            "proto_diabetic": proto[i, 2],
            "proto_organic": proto[i, 3],
            "proto_powder": proto[i, 4],
            "intent_proto_match": float(intent_vec @ proto[i]),
            "q_milk_x_p_milk": q_milk * p_milk[i],
            **f,
            "q_cow_x_src_cow": probs["cow"] * f["src_cow"],
            "q_cow_x_src_plant": probs["cow"] * f["src_plant"],
            "q_low_fat_x_fat_low": probs["low_fat"] * f["fat_low"],
            "q_diabetic_x_sugar_free": probs["diabetic"] * f["sugar_free"],
            "q_diabetic_x_sweetened": probs["diabetic"] * f["sweetened"],
            "q_organic_x_organic": probs["organic"] * f["organic"],
            "q_powder_x_form_powder": probs["powder"] * f["form_powder"],
        }
        rows.append([row[name] for name in BASE_FEATURES])
    return np.asarray(rows, dtype=np.float64)


def token_overlap_scores(query: str, products: list[dict]) -> np.ndarray:
    q_set = set(tokenize(query))
    if not q_set:
        return np.zeros(len(products), dtype=np.float64)
    scores = np.zeros(len(products), dtype=np.float64)
    for i, product in enumerate(products):
        name_set = set(tokenize(product.get("name") or ""))
        scores[i] = len(q_set & name_set) / len(q_set)
    return scores


def grocery_feature_matrix(
    query: str,
    q_emb: np.ndarray,
    products: list[dict],
    desc_emb: np.ndarray,
    cat_emb: np.ndarray,
) -> np.ndarray:
    """Name / description / category lexical and semantic features."""
    n = len(products)
    if n == 0:
        return np.zeros((0, len(GROCERY_FEATURES)), dtype=np.float64)
    names = [product.get("name") or "" for product in products]
    descs = [product.get("description") or "" for product in products]
    cats = [product.get("category") or "" for product in products]
    extra = extra_term_scores(query, products)
    extra_peak = float(extra.max()) if extra.size else 0.0
    extra_n = extra / extra_peak if extra_peak > 0 else extra
    rows = np.column_stack([
        desc_emb @ q_emb,
        cat_emb @ q_emb,
        bm25_scores(query, names),
        bm25_scores(query, descs),
        bm25_scores(query, cats),
        token_overlap_scores(query, products),
        extra_n,
    ])
    return np.asarray(rows, dtype=np.float64)


def build_extended_features(
    query: str,
    q_emb: np.ndarray,
    q_probs: np.ndarray,
    products: list[dict],
    p_emb: np.ndarray,
    name_emb: np.ndarray,
    p_milk: np.ndarray,
    prototypes: np.ndarray,
    desc_emb: np.ndarray,
    cat_emb: np.ndarray,
) -> np.ndarray:
    base = build_features(query, q_emb, q_probs, products, p_emb, name_emb, p_milk, prototypes)
    extra = grocery_feature_matrix(query, q_emb, products, desc_emb, cat_emb)
    return np.hstack([base, extra])


def semantic_text_scores(
    query: str,
    q_emb: np.ndarray,
    products: list[dict],
    p_emb: np.ndarray,
) -> np.ndarray:
    """LaBSE cosine plus scaled BM25 over name + description + category."""
    cos_text = p_emb @ q_emb
    bm25 = bm25_scores(query, [product_text(p) for p in products])
    peak = float(bm25.max()) if len(bm25) else 0.0
    bm25_n = bm25 / peak if peak > 0 else bm25
    return SEMANTIC_COS_WEIGHT * cos_text + SEMANTIC_BM25_WEIGHT * bm25_n


def _minmax(values: np.ndarray) -> np.ndarray:
    if values.size == 0:
        return values
    lo, hi = float(values.min()), float(values.max())
    if hi - lo < 1e-9:
        return np.zeros_like(values)
    return (values - lo) / (hi - lo)


def extra_query_tokens(query: str) -> list[str]:
    """Content tokens after grocery fillers: powder, diabetic, delata, senior, …"""
    return [
        token for token in tokenize(query)
        if token not in _GROCERY_FILLERS and len(token) > 2
    ]


def leftover_query_tokens(query: str) -> list[str]:
    """Modifiers after the first content word (same rule for every grocery query)."""
    tokens = extra_query_tokens(query)
    return tokens[1:] if len(tokens) > 1 else tokens


def _term_hits_doc(term: str, doc: str) -> bool:
    if term in doc:
        return True
    stem = term[:6] if len(term) >= 6 else term
    if len(stem) < 4:
        return False
    return any(word.startswith(stem) or stem in word for word in tokenize(doc))


def extra_term_scores(query: str, products: list[dict]) -> np.ndarray:
    """Score modifiers against name+description (dessert⊂desserts, diabet⊂Diabetasol)."""
    extra = leftover_query_tokens(query)
    if not extra or not products:
        return np.zeros(len(products), dtype=np.float64)
    docs = [product_text(p).lower() for p in products]
    scores = bm25_scores(" ".join(extra), docs)
    for i, doc in enumerate(docs):
        hits = sum(1 for term in extra if _term_hits_doc(term, doc))
        if hits:
            scores[i] += 2.0 * hits
    return scores


@dataclass
class RankResult:
    milk_query: float
    intents: dict[str, float]
    ranked: list[dict]  # {index, score, p_milk}
    mode: str = "grocery_ltr"


class SemanticRanker:
    """Loads the trained artifacts from ml/models and ranks products."""

    def __init__(self, embedder: LabseEmbedder | None = None, models_dir: Path | None = None) -> None:
        root = Path(models_dir) if models_dir else MODELS
        self.models_dir = root
        self.meta = json.loads((root / "meta.json").read_text())
        self.embedder = embedder or LabseEmbedder(self.meta["embedding_model"])
        self.intent_clf = joblib.load(root / "intent_classifier.joblib")
        self.milk_clf = joblib.load(root / "product_milk_classifier.joblib")
        self.prototypes = np.load(root / "intent_prototypes.npy")
        self.ranker = lgb.Booster(model_file=str(root / "ranker.txt"))
        self.query_threshold = float(self.meta["query_milk_threshold"])
        self.product_threshold = float(self.meta["product_milk_threshold"])
        self.feedback_index = FeedbackIndex.load(root / "feedback_index.npz")
        self.n_model_features = int(self.ranker.num_feature())
        self.model_version = str(self.meta.get("version", "legacy"))

    def intent_probs(self, query: str) -> tuple[np.ndarray, np.ndarray]:
        q_emb = self.embedder.encode([normalize_query(query)])[0]
        return q_emb, self.intent_clf.predict_proba(q_emb[None, :])[0]

    def model_features(
        self,
        query: str,
        q_emb: np.ndarray,
        q_probs: np.ndarray,
        products: list[dict],
        p_emb: np.ndarray,
        name_emb: np.ndarray,
        p_milk: np.ndarray,
        desc_emb: np.ndarray | None = None,
        cat_emb: np.ndarray | None = None,
    ) -> np.ndarray:
        """LTR columns for the loaded booster (base, grocery, optional feedback)."""
        names = list(self.meta.get("features") or BASE_FEATURES)
        base = build_features(query, q_emb, q_probs, products, p_emb, name_emb, p_milk, self.prototypes)
        blocks = [base]
        if any(name in GROCERY_FEATURES for name in names):
            if desc_emb is None:
                desc_emb = self.embedder.encode([p.get("description") or "" for p in products])
            if cat_emb is None:
                cat_emb = self.embedder.encode([p.get("category") or "" for p in products])
            blocks.append(grocery_feature_matrix(query, q_emb, products, desc_emb, cat_emb))
        extra = self.feedback_index.features(query, q_emb, products)
        if extra.size and any(name in FEEDBACK_FEATURES for name in names):
            blocks.append(extra)
        full = np.hstack(blocks)
        n = self.n_model_features
        if full.shape[1] >= n:
            return full[:, :n]
        return np.hstack([full, np.zeros((full.shape[0], n - full.shape[1]), dtype=np.float64)])

    def rank(self, query: str, products: list[dict], top_k: int | None = None, gate: bool = True) -> RankResult:
        q_emb, q_probs = self.intent_probs(query)
        intents = {c: float(p) for c, p in zip(CLASSES, q_probs)}
        milk_query = 1.0 - intents["other"]
        if not products:
            return RankResult(milk_query, intents, [], "semantic")

        p_emb = self.embedder.encode([product_text(p) for p in products])
        name_emb = self.embedder.encode([p.get("name") or "" for p in products])
        grocery_ltr = any(name in GROCERY_FEATURES for name in (self.meta.get("features") or []))
        desc_emb = cat_emb = None
        if grocery_ltr:
            desc_emb = self.embedder.encode([p.get("description") or "" for p in products])
            cat_emb = self.embedder.encode([p.get("category") or "" for p in products])
        p_milk = self.milk_clf.predict_proba(p_emb)[:, 1]
        labse_scores = p_emb @ q_emb
        bm25_raw = bm25_scores(query, [product_text(p) for p in products])
        extra_scores = extra_term_scores(query, products)
        features = self.model_features(
            query, q_emb, q_probs, products, p_emb, name_emb, p_milk,
            desc_emb, cat_emb,
        )
        ltr_scores = self.ranker.predict(features)
        ltr_all = ltr_scores
        scores = LTR_WEIGHT * _minmax(ltr_scores) + TEXT_WEIGHT * _minmax(extra_scores)
        mode = "grocery_ltr" if grocery_ltr else "ltr"

        order = np.argsort(-scores, kind="stable")
        ranked = []
        for i in order:
            parts = {
                "labse": round(float(labse_scores[i]), 4),
                "bm25": round(float(bm25_raw[i]), 4),
                "lambdamart": None if np.isnan(ltr_all[i]) else round(float(ltr_all[i]), 4),
                "extra": round(float(extra_scores[i]), 4),
                "total": round(float(scores[i]), 4),
            }
            ranked.append({
                "index": int(i),
                "score": float(scores[i]),
                "p_milk": float(p_milk[i]),
                "parts": parts,
            })
        return RankResult(milk_query, intents, ranked[:top_k] if top_k else ranked, mode)
