# Cebu-CA-HyLTR train labeling audit

**Status:** Approved product-first grades are in the official **train** pack (`labeling_sheet_train_wide.csv` and `labeling_sheet_train.csv`). LambdaMART was **not** retrained. Validation (51), held-out test (100), production models, live search, and Monday 03:00 UTC `search:retrain` were **not** modified.

## Dataset

| Split | Queries | Role |
| --- | ---: | --- |
| Train | 171 | Labeled here (171 × 42 = 7,182 cells) |
| Validation | 51 | Frozen; not modified |
| Held-out test | 100 | Frozen; not modified |

Catalog: frozen 42-SKU experiment subset (`products.json` / `products_reference_full.csv`), not the live MySQL shop.

## Grade meanings

| Grade | Meaning |
| ---: | --- |
| **2** | Requested product class **and** stated attributes (brand, form, size, fat, sugar, source, exclusion) |
| **1** | Same class or a genuine substitute that **fails** a stated attribute |
| **0** | Different class, excluded, or irrelevant |

Never Grade 2 from a shared adjective alone (`fresh`/`presko`, `organic`, `low fat`, `sugar-free`).

## Rules used (human-approved)

Product class first, then attributes from catalog name, description, and `specs.*` only.

- Genuine no-match (`toyo`, `itlog`, `yogurt`, `sabon`, `sardinas`, `asukal`, `1 litro nga coke`, `presko nga isda`, `asin ug asukal`): all **0**.
- Explicit 1 liter: `volume_ml=1000` only; 946 ml is **1**.
- Generic cow’s milk: ordinary cow liquids and cow powders **2**; Glucerna (diabetes formula) **1**.
- Low-fat vs non-fat kept distinct.
- Health/weight claims (`dili makataba`, diet, niwang): **E1** — no Grade 2; do not infer fat benefit.
- Diabetes-specific queries: Glucerna **2** when description supports diabetes nutrition; unsweetened drinks **1**.
- Sugar: unsweetened / no-added / sugar-free matched to specs, not equated.
  - `walay asukal` / `walang asukal`: unsweetened **and** Glucerna “Sugar free” **2**; NSA/Anlene `no_added` **1**.
  - `dili tam-is` / `hindi matamis`: unsweetened **2**; sugar-free is **not** assumed to taste unsweetened.
- Organic plant milk **2** on generic organic milk; **1** on organic *fresh*/cow milk.
- Kids/infant/UHT-for-kids: below Grade 2.
- Missing brands/flavors/products: below Grade 2; Grade 1 only for genuine substitutes.
- Pack/size/creamer: specs only (no assumed carton/bottle).
- Alaska / Nestlé / coffee / vegan: product type and attributes, not brand alone.
- Ordinary powder and “not full cream” cow milk: Glucerna **1**.

## Process

1. Independent 2/1/0 cartesian labels on the 42-SKU universe (not ranker scores, not VOC auto-labels).
2. Rejected earlier AI keyword-leak drafts.
3. Product-first builder produced a **provisional** sheet; official train sheet stayed blank until approval.
4. Human decision sheet (groups A–L), then refinements (Glucerna, sugar claims, coffee, organic plant).
5. Manual spot-check of 20 queries (milk, grocery, brand, negation, sugar/fat, health, no-match).
6. Consistency checks: class/exclusion/brand/size/sugar/Glucerna/no-Grade-2 taxonomy.
7. User approved the provisional sheet; grades copied into the official train wide/long sheets.

Rejected AI drafts were **not** copied.

## Manual spot-check (20 queries)

Documented in `proposed_train_spotcheck_20.md`. Coverage: generic cow, 1 L, soy, Bear Brand, Alaska, Nestlé fresh, cooking-not-condensed, vegan-not-chocolate, not plant-based, `walay asukal`, unsweetened, non-fat, low-fat, diabetes, `dili makataba`, organic brown rice, low-fat crackers, tapa, coconut gata, `presko nga isda`.

## Final consistency checks

`proposed_train_consistency_checks.csv`: **0 issues**.

Copy verification: `official_copy_verification.json`.

## Final counts (approved)

| | |
| --- | --- |
| Grade 2 | 514 |
| Grade 1 | 1,907 |
| Grade 0 | 4,761 |
| Queries with ≥1 Grade 2 | 127 |
| Queries with no Grade 2 | 44 |

## Documented catalog gaps (no Grade 2)

- `gatas nga ubos ang asukal` — no verified low-sugar specification
- `kape nga 3 in 1` — no complete 3-in-1 mix in the 42 SKUs

## Files

| File | Role |
| --- | --- |
| `labeling_sheet_train_wide.pre_copy_blank.csv` | Backup of the blank official wide sheet |
| `labeling_sheet_train_wide.csv` | Official train wide sheet (Windows lock if open in the editor) |
| `labeling_sheet_train_wide.approved.csv` | Verified filled wide copy (same 7,182 grades); use this if the blank file is still locked |
| `labeling_sheet_train.csv` | Official long form with specs + grade |
| `proposed_train_labels_product_first_wide.csv` | Approved provisional source |
| `labeling_sheet.csv` (experiment root) | Val + test + unlabeled train rows — **not used for this copy** |

## Isolation

- No LambdaMART retrain
- No change to val/test grades
- No write to `ml/models/ranker.txt` or live mix
- Monday schedule unchanged
