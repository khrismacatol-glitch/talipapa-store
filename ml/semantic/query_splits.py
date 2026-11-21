"""Query-level splits that keep near-paraphrases in the same fold."""
from __future__ import annotations

import re
from collections import defaultdict

import numpy as np

from .data import normalize_query

NEAR_DUP_COS = 0.92
LEXICAL_JACCARD = 0.7
_TOKEN = re.compile(r"[\w']+", re.UNICODE)
_CONTENT_STOP = {
    "gatas", "milk", "nga", "na", "sa", "para", "for", "the", "a", "ang", "og",
    "ug", "ko", "mga", "nang", "and", "with", "from", "gikan", "palit", "kog",
    "gusto", "mangita", "naa", "moy", "kanang", "unsa", "ang", "akong", "og",
}
SPLIT_SEED = 13
SPLIT_SHARE = {"train": 0.60, "val": 0.20, "test": 0.20}

# Development examples stay in val. Held-out pins stay in test.
VAL_PINS = (
    "gatas nga low fat pero dili powder",
    "gatas nga organic ug walay sugar",
    "gatas para sa kape pero dili tam-is",
)
TEST_PINS = (
    "gatas nga soya pero dili chocolate",
    "gatas nga powder",
    "gatas nga presko",
)


def content_tokens(text: str) -> set[str]:
    return {tok for tok in _TOKEN.findall(text.lower()) if tok not in _CONTENT_STOP and len(tok) > 1}


def lexical_paraphrase(a: str, b: str) -> bool:
    """Merge obvious rephrasings without collapsing every query that shares one token."""
    na, nb = normalize_query(a), normalize_query(b)
    if na == nb:
        return True
    shorter, longer = (na, nb) if len(na) <= len(nb) else (nb, na)
    if shorter in longer and (len(shorter.split()) >= 3 or len(content_tokens(shorter)) >= 2):
        return True
    ta, tb = content_tokens(a), content_tokens(b)
    if not ta or not tb:
        return False
    shared = len(ta & tb)
    return shared >= 2 and (shared / len(ta | tb)) >= LEXICAL_JACCARD


def cluster_queries(texts: list[str], embeddings: np.ndarray, threshold: float = NEAR_DUP_COS) -> list[int]:
    """Union-find clusters: cosine or lexical paraphrase stay together."""
    n = len(texts)
    parent = list(range(n))

    def find(i: int) -> int:
        while parent[i] != i:
            parent[i] = parent[parent[i]]
            i = parent[i]
        return i

    def union(i: int, j: int) -> None:
        a, b = find(i), find(j)
        if a != b:
            parent[b] = a

    norms = embeddings / np.clip(np.linalg.norm(embeddings, axis=1, keepdims=True), 1e-9, None)
    sim = norms @ norms.T
    for i in range(n):
        for j in range(i + 1, n):
            if float(sim[i, j]) >= threshold or lexical_paraphrase(texts[i], texts[j]):
                union(i, j)
    roots = {}
    labels = []
    next_id = 0
    for i in range(n):
        r = find(i)
        if r not in roots:
            roots[r] = next_id
            next_id += 1
        labels.append(roots[r])
    return labels


def assign_splits(
    queries: list[dict],
    cluster_ids: list[int],
    seed: int = SPLIT_SEED,
) -> list[str]:
    """One split per cluster. Pins first, then greedy fill by query count."""
    n = len(queries)
    if n != len(cluster_ids):
        raise ValueError("cluster_ids length must match queries")
    by_cluster: dict[int, list[int]] = defaultdict(list)
    for i, cid in enumerate(cluster_ids):
        by_cluster[cid].append(i)

    norm_index = {normalize_query(q["query"]): i for i, q in enumerate(queries)}
    assigned: dict[int, str] = {}

    def pin(names: tuple[str, ...], split: str) -> None:
        for name in names:
            i = norm_index.get(normalize_query(name))
            if i is None:
                continue
            cid = cluster_ids[i]
            if cid in assigned and assigned[cid] != split:
                continue
            assigned[cid] = split

    pin(TEST_PINS, "test")
    pin(VAL_PINS, "val")

    remaining = [cid for cid in by_cluster if cid not in assigned]
    rng = np.random.default_rng(seed)
    rng.shuffle(remaining)

    def intent_key(cid: int) -> str:
        intents = [queries[i].get("intent") or "other" for i in by_cluster[cid]]
        return max(set(intents), key=intents.count)

    remaining.sort(key=lambda cid: (intent_key(cid), -len(by_cluster[cid]), cid))

    counts = {s: sum(len(by_cluster[c]) for c, sp in assigned.items() if sp == s) for s in SPLIT_SHARE}
    total = n

    def deficit(split: str) -> float:
        return SPLIT_SHARE[split] * total - counts[split]

    for cid in remaining:
        split = max(("train", "val", "test"), key=deficit)
        assigned[cid] = split
        counts[split] += len(by_cluster[cid])

    return [assigned[cid] for cid in cluster_ids]


def splits_leak(queries: list[dict], cluster_ids: list[int], splits: list[str]) -> list[int]:
    """Cluster ids that were assigned more than one split (should be empty)."""
    seen: dict[int, set[str]] = defaultdict(set)
    for cid, split in zip(cluster_ids, splits):
        seen[cid].add(split)
    return [cid for cid, names in seen.items() if len(names) > 1]
