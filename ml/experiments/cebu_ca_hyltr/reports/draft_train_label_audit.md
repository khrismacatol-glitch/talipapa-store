# Independent audit of AI draft training grades

**Verdict: do not approve.** Do not copy this draft into `labeling_sheet_train_wide.csv`. Val/test and production were not changed.

Draft: `labeling_train/training_labels_spec_corrected_DRAFT.csv`  
Checked against: `products.json` (42 SKUs) and Cebuano query meaning.  
Machine findings also in `reports/draft_train_label_audit.csv`.

## Completeness

| Check | Result |
| --- | --- |
| Queries | **171 / 171** |
| Grade cells | **7,182 / 7,182** (171 × 42) |
| Values | 673×`2`, 3090×`1`, 3419×`0` — no blanks, no illegal grades |
| Original train wide sheet | still **all blank** |
| Queries with **no Grade 2** | **42** |

This is a complete draft, not a correct one.

---

## Do not approve — critical errors

These treat the **wrong product family** as Grade 2 (keyword leak: `organic`, `low fat`, `presko` / fresh).

| # | Query | Draft Grade 2 | Independent finding | Recommended |
| ---: | --- | --- | --- | --- |
| 107 | `presko nga isda` | 8 **fresh milks** | **Isda = fish.** No fish SKU. Draft followed “presko” as fresh milk (`training_label_changes.csv`: “Fresh milk request”). | **All 42 → 0** |
| 67 | `organic brown rice` | 5 **organic milks**; Organic Brown Rice 2kg is **not** 2 | Organic is on milk specs; the rice SKU was ignored. | **Organic Brown Rice 2kg → 2**; other organic groceries (lettuce) **1**; milks **0** |
| 68 | `low fat crackers` | 9 **low-fat milks**; Low Fat Whole Wheat Crackers **not** 2 | Same leak on `fat=low`. | **Crackers → 2**; low-fat milks **0** |
| 69 | `karne sa baka` | none | Beef Tapa **is** named Karne sa Baka; draft left it at **1**. | **Beef Tapa → 2** |
| 87 | `karne sa baka para sa sinugba` | none | Same tapa SKU only 1. | **Beef Tapa → 2** |
| 119 | `soya milk` | Silk **plus** almond, goat, two oat milks | Goat is not soy. Oat/almond are plant, not soy. | **Silk → 2**; other plant **1**; goat **0** |

---

## Incorrect Grade 2 (attribute / size / brand / form)

| # | Query | Problem | Recommended correction |
| ---: | --- | --- | --- |
| 1, 2, 6, 91 | generic cow / dairy | Nesquik chocolate drink **2**; four **powders** **2** | Nesquik **1**; powders **1** unless the query asks for pulbos; cow **liquids** stay 2 (judgment on carabao/goat) |
| 3 | `pure cow milk 1 liter` | Same 18×2 as a generic cow query: powders, 946 ml Horizon/Meiji, 180 ml Nesquik | **2** only cow **liquid 1000 ml**; 946 ml **1**; powder/180 ml **0 or 1** |
| 4 | `whole milk from cows` | Only 4×2 (Alaska Fresh, Arla Organic, Meiji, Organic Valley). Magnolia Fresh, Nestlé Fresh, Nestlé NSA, Fresh Milk 1L are **1** | Those four full-cream cow liquids → **2**; Nesquik not 2 |
| 5 | `full cream fresh milk` | Carabao + **goat** **2** | Goat/carabao **1** unless you treat any fresh animal milk as 2; Meiji/OV missing from 2 |
| 9 | `fresh milk ng baka 1 litro` | 500 ml carabao/goat **2** for **baka** and **1 litro** | Carabao/goat **1 or 0**; 1L cow fresh **2**; 946 ml **1** |
| 7 | `uht cow milk for kids` | **Zero** Grade 2. Alaska Fresh description is **UHT** (Grade 1 only) | Alaska Fresh **2** or leave **1** as judgment (kids not in specs). Do not leave all 0s if you accept UHT. |
| 14, 26, 75 | `dili makapatambok` / `hindi nakakataba` / `dili kaayo makataba` | **25×2 including full cream** | Full cream **0 or 1**; low/non-fat **1 or 2** as judgment. Do not Grade 2 every milk. |
| 145 | `Nestlé nga fresh milk` | Nestlé **skim** and **vegan oat** also **2** | Nestlé Fresh 1L **2**; NSA **1 or 2** (judgment); skim/oat **1 or 0** |
| 40 | `zero sugar almond milk` | Almond Breeze **2** (OK). Other unsweetened plant **1** (OK) | Keep; human check only |
| 41 | `unsweetened soy milk` | Silk **2** (OK) | Keep |
| 122 | `walay lactose` | Arla lactose-free **2** (OK) | Keep |
| 120 | `Bear Brand nga gatas` | Bear Brand only **2** (OK) | Keep |
| 143 | `Alaska nga gatas` | All four Alaska SKUs **2** | Judgment: drinking 2 vs evaporada/condensada 1 |
| 163 | `pangluto pero dili condensed` | Evaporada **2**, condensed **0** (OK) | Judgment on UFC gata / fresh milk as 1 |

---

## Incorrect Grade 1 / 0 (under-grading)

| # | Query | Draft | Recommended |
| ---: | --- | --- | --- |
| 69, 87 | karne / tapa | Tapa = 1 | Tapa **2** |
| 67 | organic brown rice | Rice SKU = 0 | Rice **2** |
| 68 | low fat crackers | Crackers SKU = 0 | Crackers **2** |
| 49 | `milk without pesticides and chemicals` | **0** Grade 2; 5 organic milks as 1 | Organic milks (Arla Organic, Horizon, Farm Fresh “walay kemikal”, Organic Valley, Green Basket) at least **1**, likely **2** for organic certified |
| 4 | whole milk | Several full-cream cow 1L = 1 | **2** |

NO_MATCH queries that look correctly all-0: `toyo`, `itlog`, `yogurt`, `sabon`, `sardinas`, `asukal`, `1 litro nga coke`, `asin ug asukal`. **Do not** treat 107 the same — the draft did not keep it all-0.

---

## Queries with no Grade 2 (42)

**Plausible zeros / related-only (human still grades every cell):**  
toyo, itlog, yogurt, sabon, sardinas, asukal, coke, asin ug asukal, 3-in-1, probiotic (2), pang kids, botelya, karton, thick creamer, 1 year old, newborn, Chuckie, banana, strawberry, vanilla adulto, bata-not-baby, 300 g, 680 g, 4.8 kg, tin powder, diet/weight-loss claims with no 2.

**Not acceptable as “no 2” without a human pass:**  
`uht cow milk for kids` (UHT exists), `karne sa baka` / `sinugba` (tapa exists), `milk without pesticides` (organic milks exist), health-claim rows that inconsistently use 0 vs 25×2.

Inconsistent health-claim policy: 14/26/75 = **25×2**; 15/16/21/22/25/74/76–78/93/97/98 = **0×2**. Same intent family, opposite labels.

---

## High-priority file (`training_label_review_priority.csv`)

95 rows marked HIGH. The file’s notes are generic (“verify specifications”). The **changes** file is more revealing and sometimes **wrong**:

| Changes reason | Query | Audit |
| --- | --- | --- |
| “Fresh milk request” | **`presko nga isda`** | False. Isda ≠ milk. |
| “Organic specification” | **`organic brown rice`** | Applied to milks, not the rice SKU. |
| “Low-fat requirement” | **`low fat crackers`** | Applied to milks, not crackers. |
| “Brand mismatch” | Bear Brand, Anchor, Anlene | Bear Brand G2 looks correct. |
| “Chuckie absent” | Chuckie | Correct to withhold 2; Nesquik should stay **1** not 2. |
| “Tin packaging not verified” | powder in a tin | Correct to withhold 2. |
| “Non-fat is not low-fat” | walay tambok / skim | Directionally right (Nestlé skim + Anchor **2**). |

---

## Cases requiring human judgment (do not auto-fix)

- Cow vs carabao/goat/plant on generic `gatas sa baka`
- 1 L vs 946 ml
- UHT vs “fresh”; “for kids” with no kids SKU
- Glucerna-only vs all unsweetened for diabetes
- Alaska’s four SKUs
- Nestlé Fresh vs no-sugar-added vs skim
- Cooking milk: evaporada vs gata vs drinking milk vs Coffee-Mate (`gatas para sa kape` currently **2** on Coffee-Mate — that is **not gatas**)
- Pack: botelya / karton / lata
- Diet/weight language vs `fat=low` / `fat=non`
- Size 300 g vs Bear Brand 320 g

---

## Suitability

The draft is **not** safe to train LambdaMART on. Completeness is fine; **product identity** (rice vs milk, crackers vs milk, fish vs milk, soy vs goat) is not.

**Next (only after you approve):** patch the errors above into a new file; still do not overwrite `labeling_sheet_train_wide.csv` until you say so. No retrain, no test eval, no production.
