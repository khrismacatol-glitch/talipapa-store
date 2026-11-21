# Grocery ranking dataset

Labeling set for grocery-wide LambdaMART. It does not replace `ml/data/queries.csv`,
`ml/data/qrels.csv`, or live `ml/models/*`.

## Grades

| Grade | Meaning |
|---|---|
| **2** | The product is what the query asked for |
| **1** | Related but not the exact product |
| **0** | Irrelevant |

`qrels.csv` is written by `python build_grocery_qrels.py` from catalog names and
the family contrasts below, merged with any existing graded rows.

| Contrast | Grade 2 | Grade 1 |
|---|---|---|
| `tinapa` / smoked fish vs sardines | tinapa / smoked fish | canned sardines |
| `sabon pang laba` vs `sabon pang ligo` | laundry detergent / laundry bar | bath soap |
| corned beef vs corned tuna | corned beef | San Marino corned tuna |
| `bugas` / jasmine rice vs infant cereal | table rice | Cerelac |
| dishwashing paste vs laundry powder | Dazz / Axion / Smart paste | Ariel / Surf / Tide |

Rows with `split=test` in `queries.csv` stay in test.

## Commands

```bash
cd ml
python grocery_labeler.py
python build_grocery_qrels.py
python train_grocery.py
python evaluate_grocery.py --candidate models/candidates/grocery-<timestamp> --split val
```

## Files

| File | Role |
|---|---|
| `products.json` | Encoded live catalog copy |
| `queries.csv` | Queries with train/val/test split |
| `qrels.csv` | Graded (query, product) pairs |
| `labeler.html` | Local labeling UI |
