# Grocery LambdaMART

`train_grocery.py` writes `ml/models/candidates/grocery-<timestamp>/`.
It copies production milk classifiers and does not overwrite `ml/models/` unless
evaluation beats LaBSE+BM25 and milk NDCG holds.

```bash
cd ml
python build_grocery_qrels.py
python train_grocery.py
python evaluate_grocery.py --candidate models/candidates/grocery-<timestamp> --split val
python evaluate_grocery.py --candidate models/candidates/grocery-<timestamp> --split test
```

Compare `ltr_full` to `labse_bm25` on NDCG@5, P@5, and MRR.
