# Train labeling pack (171 queries × 42 SKUs)

Official grades are filled from the approved product-first provisional sheet. See `LABELING_AUDIT.md`.

Val (51) and test (100) were **not** modified. LambdaMART has **not** been retrained.

| File | Use |
| --- | --- |
| `labeling_sheet_train_wide.csv` | Official wide grades (replaced from the verified approved copy) |
| `labeling_sheet_train.csv` | Official long form with specs + grade |
| `labeling_sheet_train_wide.pre_copy_blank.csv` | Backup taken before the copy |
| `LABELING_AUDIT.md` | Rules, process, spot-check, counts |

## Final counts

514 × Grade 2, 1,907 × Grade 1, 4,761 × Grade 0. 127 queries have at least one Grade 2; 44 have none (including catalog gaps `gatas nga ubos ang asukal` and `kape nga 3 in 1`).
