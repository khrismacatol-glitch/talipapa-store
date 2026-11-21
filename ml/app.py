"""HTTP ranking service used by Laravel (app/Services/Search/SemanticRanker.php).

Run: ml/.venv/Scripts/python -m uvicorn app:app --app-dir ml --port 8088
"""
from __future__ import annotations

from fastapi import FastAPI
from pydantic import BaseModel

from semantic.ranker import SemanticRanker

app = FastAPI(title="Talipapa semantic search")
ranker = SemanticRanker()


class Product(BaseModel):
    id: int
    name: str
    description: str | None = None
    category: str | None = None
    specs: dict | None = None


class RankRequest(BaseModel):
    query: str
    products: list[Product]
    top_k: int | None = None


@app.get("/health")
def health() -> dict:
    return {
        "status": "ok",
        "model": ranker.meta["embedding_model"],
        "features": len(ranker.meta["features"]),
        "model_version": ranker.model_version,
        "embed_cache": len(ranker.embedder._cache),
    }


@app.post("/rank")
def rank(req: RankRequest) -> dict:
    products = [p.model_dump() for p in req.products]
    result = ranker.rank(req.query, products, req.top_k)
    return {
        "milk_query": result.milk_query,
        "is_milk_query": result.milk_query >= ranker.query_threshold,
        "mode": result.mode,
        "model_version": ranker.model_version,
        "intents": result.intents,
        "results": [
            {
                "id": products[r["index"]]["id"],
                "score": r["score"],
                "p_milk": r["p_milk"],
                "parts": r.get("parts") or {},
            }
            for r in result.ranked
        ],
    }
