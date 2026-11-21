"""Implicit search-feedback labels and inference features."""
from __future__ import annotations

import json
import math
from collections import defaultdict
from dataclasses import dataclass, field
from pathlib import Path

import numpy as np

from .data import DATA, normalize_query

FEEDBACK_FEATURES = [
    "feedback_self",
    "feedback_knn",
    "feedback_support",
    "inv_impressions",
]

EXPORT_PATH = DATA / "feedback" / "export.json"
QRELS_PATH = DATA / "feedback" / "qrels.csv"

MIN_SESSIONS = 5
MIN_STRONG = 3
NEGATIVE_IMPRESSIONS = 20
KNN_MIN_COS = 0.75
MAX_EVENTS_PER_SESSION = 40
CLICK_IPW_CAP = 4.0


def load_export(path: Path = EXPORT_PATH) -> dict:
    if not path.exists():
        return {"sessions": []}
    return json.loads(path.read_text(encoding="utf-8"))


def _is_suspicious(session: dict) -> bool:
    events = session.get("events") or []
    impressions = session.get("impressions") or []
    if len(events) > MAX_EVENTS_PER_SESSION:
        return True
    if not (session.get("query_norm") or session.get("query")):
        return True
    if not impressions and events:
        return True
    return False


def _click_weight(position: int | None) -> float:
    pos = int(position or 1)
    return min(CLICK_IPW_CAP, 1.0 / math.log2(pos + 1))


def _product_name(impression_or_event: dict, names: dict[int, str]) -> str | None:
    pid = impression_or_event.get("product_id")
    name = impression_or_event.get("name") or names.get(pid)
    if not name:
        return None
    return name.strip()


def build_feedback_qrels(
    export: dict,
    min_sessions: int = MIN_SESSIONS,
    min_strong: int = MIN_STRONG,
    negative_impressions: int = NEGATIVE_IMPRESSIONS,
) -> list[dict]:
    """Aggregate implicit events into graded (query, product_name) rows."""
    stats: dict[tuple[str, str], dict] = {}
    names: dict[int, str] = {}

    for session in export.get("sessions") or []:
        if _is_suspicious(session):
            continue
        query = normalize_query(session.get("query_norm") or session.get("query") or "")
        if not query:
            continue
        sid = session.get("token") or id(session)
        for imp in session.get("impressions") or []:
            if imp.get("name") and imp.get("product_id"):
                names[int(imp["product_id"])] = imp["name"]
            name = _product_name(imp, names)
            if not name:
                continue
            row = stats.setdefault((query, name), {
                "sessions": set(),
                "impressions": 0,
                "clicks": 0.0,
                "carts": 0,
                "purchases": 0,
            })
            row["sessions"].add(sid)
            row["impressions"] += 1

        seen_types: set[tuple] = set()
        for event in session.get("events") or []:
            name = _product_name(event, names)
            if not name:
                continue
            key = (query, name)
            if key not in stats:
                continue
            t = event.get("type")
            dedupe = (sid, name, t)
            if dedupe in seen_types:
                continue
            seen_types.add(dedupe)
            if t == "click":
                stats[key]["clicks"] += _click_weight(event.get("position"))
            elif t == "cart":
                stats[key]["carts"] += 1
            elif t == "purchase":
                stats[key]["purchases"] += 1

    rows = []
    for (query, name), row in stats.items():
        n_sess = len(row["sessions"])
        strong = row["purchases"] + row["carts"]
        grade = None
        if n_sess >= min_sessions and strong >= min_strong:
            grade = 2
        elif n_sess >= min_sessions and (strong >= 1 or row["clicks"] >= 1.5):
            grade = 1
        elif row["impressions"] >= negative_impressions and strong == 0 and row["clicks"] < 0.25:
            grade = 0
        if grade is None:
            continue
        rows.append({
            "query": query,
            "product_name": name,
            "grade": grade,
            "sessions": n_sess,
            "impressions": row["impressions"],
            "carts": row["carts"],
            "purchases": row["purchases"],
            "click_ipw": round(row["clicks"], 4),
        })
    return rows


def write_feedback_qrels(rows: list[dict], path: Path = QRELS_PATH) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    import csv
    fields = ["query", "product_name", "grade", "sessions", "impressions", "carts", "purchases", "click_ipw"]
    with path.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def load_feedback_qrels(path: Path = QRELS_PATH) -> list[dict]:
    if not path.exists():
        return []
    import csv
    with path.open(encoding="utf-8", newline="") as fh:
        return list(csv.DictReader(fh))


def _name_key(name: str) -> str:
    return normalize_query(name)


@dataclass
class FeedbackIndex:
    """Per-query product scores in LaBSE space for kNN generalization."""

    query_norms: list[str] = field(default_factory=list)
    q_emb: np.ndarray | None = None
    # query_norm -> {product_name_key: grade float}
    scores: dict[str, dict[str, float]] = field(default_factory=dict)
    impressions: dict[str, int] = field(default_factory=dict)

    def __bool__(self) -> bool:
        return bool(self.query_norms)

    def features(self, query: str, q_emb: np.ndarray, products: list[dict]) -> np.ndarray:
        n = len(products)
        out = np.zeros((n, len(FEEDBACK_FEATURES)), dtype=np.float64)
        if not self.query_norms or self.q_emb is None or n == 0:
            return out
        qn = normalize_query(query)
        self_map = self.scores.get(qn, {})
        qv = q_emb / (np.linalg.norm(q_emb) or 1.0)
        sims = self.q_emb @ qv
        knn: dict[str, list[float]] = defaultdict(list)
        for i, other in enumerate(self.query_norms):
            if other == qn:
                continue
            if float(sims[i]) < KNN_MIN_COS:
                continue
            for pname, grade in self.scores.get(other, {}).items():
                knn[pname].append(float(grade) * float(sims[i]))

        for i, product in enumerate(products):
            key = _name_key(product.get("name") or "")
            self_s = float(self_map.get(key, 0.0))
            neigh = knn.get(key) or [0.0]
            knn_s = max(neigh)
            support = float(self_s != 0.0) + (1.0 if knn_s else 0.0)
            inv = 1.0 / (1.0 + self.impressions.get(key, 0))
            out[i] = [self_s, knn_s, support, inv]
        return out

    def save(self, path: Path) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        np.savez_compressed(
            path,
            query_norms=np.array(self.query_norms, dtype=object),
            q_emb=self.q_emb if self.q_emb is not None else np.zeros((0, 1)),
            scores_json=np.array(json.dumps(self.scores), dtype=object),
            impressions_json=np.array(json.dumps(self.impressions), dtype=object),
        )

    @classmethod
    def load(cls, path: Path) -> "FeedbackIndex":
        if not path.exists():
            return cls()
        data = np.load(path, allow_pickle=True)
        q_emb = data["q_emb"]
        if q_emb.size == 0:
            return cls()
        return cls(
            query_norms=[str(x) for x in data["query_norms"].tolist()],
            q_emb=q_emb.astype(np.float64),
            scores=json.loads(str(data["scores_json"].item())),
            impressions=json.loads(str(data["impressions_json"].item())),
        )

    @classmethod
    def build(cls, rows: list[dict], embedder) -> "FeedbackIndex":
        if not rows:
            return cls()
        scores: dict[str, dict[str, float]] = defaultdict(dict)
        impressions: dict[str, int] = defaultdict(int)
        for row in rows:
            q = normalize_query(row["query"])
            name = _name_key(row["product_name"])
            scores[q][name] = max(scores[q].get(name, 0.0), float(row["grade"]))
            impressions[name] += int(row.get("impressions") or 0)
        norms = sorted(scores)
        q_emb = embedder.encode(norms)
        q_emb = q_emb / np.clip(np.linalg.norm(q_emb, axis=1, keepdims=True), 1e-9, None)
        return cls(query_norms=norms, q_emb=q_emb, scores=dict(scores), impressions=dict(impressions))
