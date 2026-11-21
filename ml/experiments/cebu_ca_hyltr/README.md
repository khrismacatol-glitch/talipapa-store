# Cebu-CA-HyLTR Stage 1 (review packet)

Frozen baseline and labeling materials. Production `ml/models/ranker.txt` was not modified.

## Catalog

- `42` products: 39 milk-catalog SKUs with specs plus 3 grocery distractors (tomatoes, jasmine rice, pandesal).
- Product facts in `products.csv` are for the judge. They are **not** relevance grades.

## How to label `labeling_sheet.csv`

Grade every (query, product) pair yourself. Leave cells blank until you are sure.

| Grade | Meaning |
| ---: | --- |
| 2 | Highly relevant to this query (would satisfy the shopper) |
| 1 | Related but wrong attribute / weaker match |
| 0 | Irrelevant |

Do not copy scores from the ranker. Do not fill grades from `is_milk` or specs with a script.

`labeling_sheet_wide.csv` is the same task, one row per query, one column per product key.

Train-only pack (val/test rows not edited): `labeling_train/`. Grades there are blank. Guide: 2 = satisfies the query and its specified attributes; 1 = related but fails a specified attribute; 0 = irrelevant.

## Splits (`splits.csv`)

Near-paraphrases share a `cluster_id` and the same split (LaBSE cosine ≥ 0.92, or one query contains the other).

| Split | Queries | Role |
| --- | ---: | --- |
| train | 171 | Future LTR fit only (after you approve labels) |
| val | 51 | Feature/ablation decisions |
| test | 100 | Held-out, one-shot |

Price queries (barato / molapas / …) are listed in `excluded_price_queries.csv` and removed from the experiment sheets. Original sheets: `backups/pre_price_exclude/`. Remaining split ids were not reassigned. Clusters originally 177 over 296 queries; 5 price queries dropped.

31 extra Cebuano gatas queries were appended to **test only** (`source=thesis_test_expand_100`). Grades for those rows are blank. Do not score `--split test` until they are labeled. `gatas para sa halo-halo` was not copied into test because it is already train; `gatas para sa scrambled egg` was used so the held-out set stays 100 independent queries.

Stage 2 example queries are flagged `stage2_example=1`. Val pins: low-fat¬powder, organic∧no-sugar, kape¬tam-is. Test pins: soya¬chocolate, powder, presko.

## Evaluate (after some val rows are fully graded)

```
.venv/Scripts/python evaluate_cebu_ca.py --split val
```

`--split test` is refused unless you pass `--held-out-ok`. Incomplete queries (any blank grade) are skipped. No metrics are invented.

Baseline snapshot: `baseline_snapshot/` including `formula.json`.

## Isolation from production (do not promote)

- Live ranking still uses `ml/models/` and the mix in `formula.json`.
- Monday `search:retrain` (03:00 **UTC**) writes `ml/models/candidates/<stamp>/` and does **not** pass `--promote`.
- Thesis files live only under `ml/experiments/cebu_ca_hyltr/`.
- `train.py` drops queries in `splits.csv` split=`test` from LTR groups and from feedback qrels, and treats them as near-test for implicit logs.
- Do not copy this folder into `ml/models/`. Do not run `search:retrain --promote` unless the owner approves.
