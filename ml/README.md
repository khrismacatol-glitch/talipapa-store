# Semantic milk search (ML ranking service)

Laravel's `ProductSearchService` sends the shopper's query and the candidate
products to this service, which ranks them with trained models. If the service
is off, unreachable, or decides the query is not about milk, Laravel falls
back to the original keyword search, so voice search and voice ordering keep
working either way.

```
query ─► LaBSE embedding ─► intent classifier ──► P(cow, low_fat, diabetic, organic, powder, other)
products ─► LaBSE embedding ─► milk classifier ─► P(milk)
(query, product) features ─► LightGBM LambdaMART ─► score ─► milk gate ─► ranked ids
```

| Component | Model | Trained on |
|---|---|---|
| Embeddings | `sentence-transformers/LaBSE` (pre-trained, supports Cebuano and Tagalog), run with ONNX Runtime | — |
| Query intent | Logistic regression on query embeddings | 113 labelled train queries (`data/queries.csv`) |
| Product milk gate | Logistic regression on product embeddings, threshold chosen by out-of-fold F0.5 | 709 labelled products (benchmark catalog + reviewed live catalog) |
| Ranker | LightGBM `lambdarank` on 28 features (LaBSE cosine, BM25, intent probabilities, intent prototypes, product specs, interactions) | 9,130 graded (query, product) pairs (`data/qrels.csv`) |

PyTorch is blocked by Windows Application Control on the dev machine, so LaBSE
runs through its official ONNX export plus its Dense head. The output is the
same sentence-transformers pipeline: CLS → Dense(tanh) → L2 normalize.

## Data

- `../database/data/milk_catalog.php`: 37 products with specs (source, fat, sugar, organic). It holds 5–6 per category plus 14 negatives such as condensed milk, milk tea, coconut milk (gata), "Karne sa Baka" and organic rice. `MilkCatalogSeeder` writes them to `products.specs`.
- `data/queries.csv`: Cebuano, Tagalog, English and Taglish queries with intent and split. The four required queries and their close paraphrases are in the **test** split only.
- `data/qrels.csv`: graded relevance from `build_qrels.py`. 2 = matches the intent, 1 = milk with the wrong attribute, 0 = not relevant or contradicting.
- `data/products.json`: the benchmark catalog (fresh seed), exported by `php artisan search:export-catalog`.
- `data/live_products.json` + `data/live_product_labels.csv`: products from the local MySQL DB with reviewed milk / not-milk labels. These are used only for training the milk gate.

## Run it

```bash
py -3.12 -m venv ml/.venv
ml/.venv/Scripts/python -m pip install -r ml/requirements.txt

# Benchmark catalog (SQLite, reproducible)
php artisan migrate:fresh --seed            # with DB_CONNECTION=sqlite DB_DATABASE=storage/search-bench.sqlite
php artisan search:export-catalog

cd ml
../ml/.venv/Scripts/python build_qrels.py
../ml/.venv/Scripts/python train.py          # writes ml/models/*
../ml/.venv/Scripts/python -m pytest tests
cd ..

# Serve
ml/.venv/Scripts/python -m uvicorn app:app --app-dir ml --port 8088
# .env: SEMANTIC_SEARCH_ENABLED=true
php artisan search:warm                      # embed the catalog once after the service starts
php artisan search:evaluate --show           # keyword vs ML through ProductSearchService
cd ml && ../ml/.venv/Scripts/python evaluate.py   # full report → reports/eval.md
```

## Results (held-out test queries, benchmark catalog)

The full report is in `reports/eval.md`. Macro average over 24 milk test queries:

| System | P@5 | R@5 | NDCG@5 | MRR | Non-milk in top 5 |
|---|---:|---:|---:|---:|---:|
| Keyword search (before) | 0.500 | 0.308 | 0.657 | 0.600 | 1.4% |
| LaBSE cosine, zero-shot | 0.650 | 0.428 | 0.779 | 1.000 | 6.7% |
| LambdaMART, text features only | 1.000 | 0.645 | 1.000 | 1.000 | 0% |
| LambdaMART, full (production) | 1.000 | 0.645 | 1.000 | 1.000 | 0% |
| Laravel end to end via the service | 1.000 | 0.645 | 1.000 | 1.000 | 0% |

R@5 is capped by the number of relevant products: "cow's milk" has 17 relevant
products, so 5/17 = 0.29 is the maximum. `reports/eval.md` also reports cR@5,
which is recall against min(5, |relevant|).

Other numbers:

- Intent classifier: 0.92 CV accuracy on train. Test accuracy is 0.85, and all four required queries are classified correctly.
- Milk gate on held-out live products (180 unseen, 4 milk): precision 1.00, recall 0.50, accuracy 0.99.
- Live MySQL catalog (768 products): 0 non-milk products are returned for any of the four queries. This check is partly in-sample for the gate.

## Adaptive feedback (batch)

Shopper clicks, carts, and purchases are stored in MySQL (`search_sessions`,
`search_impressions`, `search_events`) with hashed session keys. They are **not**
used until you export and retrain:

```bash
php artisan search:retrain              # labels only; trains a candidate if evidence exists
php artisan search:retrain --promote    # swap ml/models after pytest; then restart uvicorn
```

Live `/rank` keeps the current LightGBM file until a promote. Empty logs do not
change rankings. Details: `ml/docs/adaptive-training.md`.

## Known limitations

- The dataset is small: 113 train queries and 24 milk test queries. The test set is too small to separate the two LambdaMART variants, so treat the perfect scores as "no errors on this test set", not as production accuracy.
- 5 of the 10 non-milk test queries are misrouted to the milk ranker: "condensed milk para sa leche flan", "milk tea nga wintermelon", "chocolate nga dairy milk", "karne sa baka para sa sinugba" and "biskwit para sa diabetic". These queries share milk, cow or diabetic words without asking for milk, and the shopper sees milk instead of the product they wanted. Every milk test query is routed correctly.
- Within a relevant set the labels are flat. For "gatas sa baka", low-fat cow milk and plain fresh milk are both grade 2, so the ranker has no reason to put plain fresh milk first.
- The gate favours precision. Borderline milk such as the "Chuckie" chocolate milk drink can be filtered out.
- Laravel sends the full candidate list on every search, up to `SEMANTIC_SEARCH_CANDIDATES` (default 2000). Embeddings are cached in the service, so this is fast after `search:warm`, but a larger catalog would need a vector index.
