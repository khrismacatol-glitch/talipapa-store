# Feedback-driven adaptive ranking

This document describes how Talipapa search can learn query–product relevance
from shopper behavior without adding a new intent class for every modifier
(dessert, fresh, halo-halo).

Speech-to-text is unchanged: OpenAI `gpt-4o-mini-transcribe` / `whisper-1`.
Ranking remains LaBSE + sklearn logistic regression + LightGBM LambdaMART + BM25.

## Training dataset sources

| Source | Role | When used |
|---|---|---|
| `ml/data/queries.csv` | Intent labels, train/test split | Always. Test queries never train. |
| `ml/data/qrels.csv` | Verified 0/1/2 grades from product specs | Always (frozen ground truth). |
| `ml/data/products.json` | Benchmark catalog | Ranker train/eval. |
| `ml/data/live_product_labels.csv` | Milk vs not-milk | Milk-gate classifier only. |
| `search_sessions` / `search_impressions` / `search_events` | Implicit feedback | Label builder only after export. |
| `ml/data/feedback/qrels.csv` | Aggregated implicit grades | LambdaMART extra groups + kNN features. |

Nothing in the feedback pipeline invents rows. An empty export produces an
empty qrels file and **does not** replace `ml/models/ranker.txt`.

## Feedback collection

Laravel records, when `SEARCH_FEEDBACK_ENABLED=true`:

- Search query and `query_norm` (lowercased, collapsed whitespace)
- Shown product ids and ranking positions (the current page only)
- Clicks (`POST /search-feedback/click`)
- Add-to-cart (typed and voice)
- Checkout purchases linked to the recent search cookie
- Model version returned by `/rank`
- Timestamps

Identifiers are HMAC-SHA256 of the Laravel session id (and user id if logged
in) using `APP_KEY`. Names, emails, and IP addresses are not stored on these
tables. The browser holds a short-lived UUID cookie `search_fb` (30 minutes).

## Feature extraction

Base LambdaMART features are unchanged (`BASE_FEATURES` in `ranker.py`).

When a promoted model includes feedback columns:

- `feedback_self` — aggregated grade for this query_norm + product name
- `feedback_knn` — max grade × cosine from other feedback queries with LaBSE
  similarity ≥ 0.75 (this is how “gatas para sa dessert” can help
  “gatas pang halo-halo” / “milk for baking” without a synonym list)
- `feedback_support` — whether self or neighbor evidence exists
- `inv_impressions` — `1 / (1 + impressions)` so rarely shown SKUs are not frozen out

The **milk gate** still drops non-milk products on milk queries (fresh tomatoes).
Leftover-word BM25 remains a weak prior until a feedback-trained model is promoted.

The currently loaded `ranker.txt` may have 28 features. FastAPI slices or pads
columns to `Booster.num_feature()`, so the live shop does not break before the
first promote.

## Training methodology

1. `php artisan search:export-feedback`
2. `python build_feedback_qrels.py` — position-debiased clicks, carts, purchases;
   drop bot-like sessions; require ≥ 5 distinct sessions and ≥ 3 cart/purchase
   events for grade 2
3. `python train.py --out-dir ml/models/candidates/<version> --with-feedback`
   - Intent and milk classifiers still use verified labels only
   - Extra LambdaMART groups are feedback queries whose embeddings are **not**
     within cosine 0.92 of a frozen test query (query-grouped split)
   - Classifiers are not fit on clicks
4. Batch only. Not after every search. `search:retrain` is scheduled weekly
   and **does not promote** unless you pass `--promote`.

## Evaluation

`python evaluate.py` on the frozen test split reports P@5, R@5, NDCG@5, MRR,
Leak@5, intent accuracy, macro-F1, and accuracy by `lang` (ceb / tl / en).

Required Cebuano queries stay in `ml/tests/test_rank.py`.

`promote.py --require-eval` refuses a candidate if NDCG@5 falls more than 0.02
or Leak@5 rises more than 0.02 versus `ml/reports/eval.json`.

Do not treat empty feedback as an improvement. Compare only after a real
retrain has written `reports/eval.json`.

## Model versioning and rollback

- `ml/models/meta.json` → `version` (UTC timestamp after a train)
- Candidates: `ml/models/candidates/<version>/`
- On promote, the previous artifacts are copied to `ml/models/archive/<version>/`
- Failed pytest during promote restores the archive
- Restart uvicorn after a successful promote (`php artisan search:warm`)

## Retraining and rollback procedures

```bash
php artisan search:retrain              # export + labels; train candidate if evidence exists
php artisan search:retrain --promote    # also swap ml/models after pytest
# rollback: copy ml/models/archive/<version>/* back to ml/models/ and restart uvicorn
```

## Limits of implicit feedback

- Position bias: people click rank 1. Clicks are inverse-log weighted; carts
  and purchases count more; single clicks are not enough.
- Popularity bias: well-known brands get more carts.
- Sparse traffic: Cebuano paraphrases may never reach the evidence floor.
- Subject errors: the milk gate is still required; feedback must not revive tomatoes.
- Cold start: new listings rely on LaBSE + BM25 + `inv_impressions` until logged.
- Not online learning: the live `/rank` request does not update weights.
- Voice STT can mis-hear a query; those sessions still look like text.
