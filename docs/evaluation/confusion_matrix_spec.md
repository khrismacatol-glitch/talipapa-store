# Spec: Top-1 product-family confusion matrix for the ranking lab

Purpose: evaluate six scorers on a fixed set of 100 milk queries, using the confusion-matrix method in the thesis literature review (Tharwat, 2021; Grandini et al., 2020; Opitz, 2024; Chicco & Jurman, 2020; Manning et al., 2008; Järvelin & Kekäläinen, 2002).

## Hard rules

1. Do NOT change any ranking code, `config/voc.php`, model files, weights or thresholds in this task. This task only measures.
2. Gold labels come ONLY from `storage/app/eval/gold_queries.csv`, written by a person. Never derive a gold family from `QueryAttributeExtractor`, `config/voc.php`, catalog aliases or any other system rule. If a query has no gold label, skip it and list it in the report.
3. Product families come ONLY from `storage/app/eval/product_family.csv` (product_id, family), reviewed by a person. If a ranked product has no family, use `Unmapped` and count it in the report. Do not infer families from product names.
4. Strip leading list numbering from queries before running them (`^\s*\d+[.)]\s*`). Log the cleaned text.
5. Same queries, gold file, family file and code path for every run. Stamp every output with: timestamp, ranker mode, model version (e.g. `20261116T085420Z`), git commit, and SHA-256 of the gold and family files.

## Inputs

- `storage/app/eval/gold_queries.csv`
  - columns: `query_id, query, gold_family, second_grader_family, status`
  - `status`: `graded` | `no_relevant_item` (exclude `no_relevant_item` from every metric; report the count)
  - `second_grader_family` is blank except for the doubly labeled subset
- `storage/app/eval/product_family.csv`: `product_id, name, family, secondary_families, note`
  - `family` = the product's one primary family
  - `secondary_families` = other families it also correctly answers, separated by `; ` (may be blank)
- Allowed family labels (exact strings, 16): Drinking / cow, Non-cow, Low fat, No sugar, Organic, Powder, Flavored, Infant, Kids, Adult / senior, Condensed, Evaporated, Cooking, Probiotic, Coconut / gata, Other. "Cooking" is never a primary family; products reach it only through secondary_families
- Only products with stock > 0 reach the ranker. Report in run_info.json any product in product_family.csv that was out of stock during the run.

## Scorers (six)

For each query, get each scorer's full ordering of the candidate set from the existing `/rank` response and PHP pipeline, without changing them:

| Scorer | Ordering |
| --- | --- |
| live_total | live shop order (after PHP keep rules) |
| labse | sort by LaBSE cosine, descending |
| bm25 | sort by full-query BM25, descending |
| leftover_bm25 | sort by leftover BM25, descending |
| lambdamart | sort by raw LambdaMART score, descending |
| voc_keyword | sort by PHP VOC keyword score, descending (rule-only baseline) |

Ties: break by product_id ascending, and document it.

## Matrix (per scorer)

- predicted family = family of the product ranked #1 by that scorer, with this rule for products that fit several families: if the query's gold family is the product's primary family OR one of its secondary families, the predicted family is the gold family (a correct answer); otherwise it is the product's primary family. Document this rule in the report.
- "relevant" for P@5 and NDCG@10 uses the same rule: a product is relevant when the gold family is its primary or a secondary family
- rows = gold family, columns = predicted family, cell = number of queries
- add a row total (support) and a column total
- keep all allowed families as rows and columns even when empty, plus an `Unmapped` column if any

## Metrics (per scorer)

Let C be the matrix, N the number of graded queries, K the gold families with support > 0.

- Accuracy = trace(C) / N
- Per family c: precision = C[c,c] / column total; recall = C[c,c] / row total; F1 = 2PR / (P + R). If a denominator is 0, the value is 0 and is flagged `undefined` in the report (zero_division=0).
- Macro precision, macro recall (= balanced accuracy), macro F1 = unweighted mean over K
- Weighted F1 = mean weighted by support
- MCC = multi-class Matthews correlation coefficient (`sklearn.metrics.matthews_corrcoef`)
- P@5 = share of the top 5 products that are relevant (rule above), averaged over queries
- NDCG@10 = binary gain (1 if relevant by the rule above, else 0), log2 discount, ideal = all relevant first among the candidates; average over queries
- Inter-annotator agreement: Cohen's kappa between `gold_family` and `second_grader_family` on rows where both are filled (`sklearn.metrics.cohen_kappa_score`); report n and kappa

Use scikit-learn (`confusion_matrix`, `classification_report`, `matthews_corrcoef`, `cohen_kappa_score`) so numbers match the literature definitions.

## Outputs

- `storage/app/eval/<run_id>/matrix_<scorer>.csv` (rows gold, columns predicted, with totals)
- `storage/app/eval/<run_id>/per_family_<scorer>.csv` (family, support, precision, recall, F1)
- `storage/app/eval/<run_id>/summary.csv`, one row per scorer: accuracy, macro_precision, macro_recall, macro_f1, weighted_f1, mcc, p_at_5, ndcg_at_10, n_graded, n_excluded, n_unmapped
- `storage/app/eval/<run_id>/per_query.csv`: query_id, query, gold_family, and for each scorer the #1 product_id, its family, and correct (true/false)
- `storage/app/eval/<run_id>/run_info.json`: the stamps in hard rule 5, plus kappa

## Ranking-lab page

- One matrix per scorer, rows gold, columns predicted; diagonal cells green, off-diagonal cells orange, darker = larger count; row and column totals shown
- Above each matrix: accuracy, macro F1 and MCC (not accuracy alone)
- A summary table of all scorers (the columns of summary.csv)
- A note under the matrices: "Gold families labeled by a person before the run; product families from product_family.csv."
- A "Download run" button for the files above

## Tests (write these first)

1. A 3-class toy case with a hand-computed matrix: check accuracy, per-class P/R/F1, macro F1 and MCC against values worked out by hand in the test.
2. A class that is never predicted gives precision 0, flagged `undefined`, without crashing.
3. Leading numbering ("1. Gatas gikan sa baka") is stripped before ranking.
4. A query with status `no_relevant_item` is excluded from all metrics and counted.
5. A missing gold label stops the run for that query and lists it; it never falls back to a system rule.
6. Kappa equals 1.0 when both graders agree on every row.
7. A #1 product whose secondary family matches the gold family lands on the diagonal; one that matches neither lands in its primary family's column.
8. The columns `suggested_family` and `reason` in gold_queries.csv are never read; only `gold_family` is.

## Process

1. Read this spec and list the files you will add or change. Wait for approval.
2. Write the tests in "Tests"; run them and show them failing.
3. Implement; run the tests; show them passing.
4. Run once on the 100 queries and show summary.csv. Do not interpret the results.
