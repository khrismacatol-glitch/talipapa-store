# Human-review decision sheet (provisional labels)

**A–L approved** and applied to the provisional sheet. Official `labeling_sheet_train_wide.csv` is **unchanged**.

Final corrections applied. Remaining unresolved (catalog gaps, not labeling disputes):

1. `gatas nga ubos ang asukal` — no low-sugar spec; no Grade 2.
2. `kape nga 3 in 1` — no complete mix SKU; Coffee-Mate/evaporada **1**.

Resolved: Glucerna is **1** on generic cow’s milk and **2** on diabetes-specific queries. Coffee query: drinking milk **2**, creamers **1**.

**Recommended** = strict catalog specs only (name, description, `specs.*`). Not shopper marketing.

---

## A. Genuine no-match (9) — keep all 0

No SKU is that product. Proposed = recommended = **0** for all 42.

| Query | English |
| --- | --- |
| `toyo` | soy sauce |
| `itlog` | eggs |
| `yogurt` | yogurt |
| `sabon` | soap |
| `sardinas` | sardines |
| `asukal` | sugar |
| `1 litro nga coke` | 1 liter of Coke |
| `presko nga isda` | fresh **fish** (not fresh milk) |
| `asin ug asukal` | salt and sugar |

These 9 of the 43 no-Grade-2 queries are **correct zeros**, not over-strict rules.

---

## B. 1 liter vs 946 ml — approve substitute rule

**Queries:** `pure cow milk 1 liter`; `fresh milk ng baka 1 litro` (fresh cow milk, 1 liter)

| Product | Specs | Proposed | Uncertain? | Recommended |
| --- | --- | --- | --- | --- |
| Alaska / Magnolia / Nestlé / Arla / Fresh Milk 1L, etc. | `volume_ml=1000`, cow liquid | **2** | No | **2** |
| Meiji Whole Milk 946ml; Horizon Organic Low Fat 946ml; Almond/Silk 946ml | 946 ≠ 1000 | **1** | Yes if you treat ~1L as exact | **1** (not 2) |
| Nesquik 180ml; carabao/goat 500ml; powders | wrong size/source/form | **1** (carabao/goat, powder) or **0** (Nesquik) | — | keep |

**Rule to approve:** only `volume_ml=1000` can be Grade 2 for “1 liter / 1 litro”. 946 ml stays **1**.

---

## C. Cow vs carabao/goat vs powder vs chocolate on generic gatas

**Queries:** `gusto ko og gatas nga gikan sa baka` (I want milk from a cow); `puro nga gatas sa among baka`; `regular dairy milk`; `palit ko og gatas nga gikan sa baka`; `real cow milk not plant based`

| Product | Specs | Proposed | Recommended |
| --- | --- | --- | --- |
| Unflavored cow drinking liquids | `source=cow`, not powder | **2** | **2** |
| Fresh Carabao / Goat / Farm Fresh Carabao | `source=carabao` or `goat` | **1** | **1** |
| Cow powders | `form=powder` | **1** | **1** |
| Nesquik chocolate drink | cow, chocolate, 180 ml | **1** | **1** |
| Plant drinks | `source=plant` | **0** on “not plant based”; else **0** | **0** for *baka* / cow |

**Rule:** Grade 2 only `source=cow` drinking liquids. Other animals and powder are substitutes (**1**).

---

## D. Low-fat vs non-fat

**Queries:** `gatas nga gamay ra og tambok` / `gamay ang tambok` / `gaan ra ang tambok` (low fat); `reduced fat milk`; `low fat na gatas para pumayat`; `dili kaayo tambok`; `dili full cream`

| Product | Specs | Proposed | Recommended |
| --- | --- | --- | --- |
| Alaska / Magnolia / Arla LF / Horizon | `fat=low` | **2** | **2** |
| Nestlé Non-Fat Skim; Anchor skim powder | `fat=non` | **1** | **1** (stricter than low-fat, not the same) |
| Full cream cow | `fat=full` | **0 or 1** | **0** if query excludes full cream; else **1** |

`semi skimmed milk`: Arla lactose-free 1.5% and Magnolia LF 1.5% = **2**; Horizon 1% = **1**. Recommended: keep.

---

## E. Health / weight claims — 15 queries, currently **no Grade 2** (too conservative, not no-match)

Catalog **does** have low-fat and skim milk. Specs do **not** say “diet” or “dili makataba”.

| Query | English |
| --- | --- |
| `gatas nga dili makapatambok` | milk that will not make you fat |
| `gatas nga dili makataba` / `dili kaayo makataba` / `gatas dili makataba` | milk that doesn’t make you fat |
| `gatas na hindi nakakataba` / `hindi makataba` | (Tagalog) same |
| `gatas para sa nag diet` / `nagda-diet` | milk for someone on a diet |
| `gatas para sa gusto mo niwang` | milk for wanting to slim down |
| `gatas para sa nagpaniwang` | milk for someone slimming |
| `gatas para dili motaas ang timbang` | milk so weight does not go up |
| `gatas nga dili makadugang og timbang` | milk that won’t add weight |
| `gatas nga dili makataba para sa diet` | non-fattening milk for a diet |
| `light milk for weight loss` | (English) |
| `milk for dieting low calorie` | (English) |

| Product | Specs | Proposed | Why uncertain | Recommended (spec-strict) |
| --- | --- | --- | --- | --- |
| All drinking/powder/plant milks | various fat | **1** | Mapping “won’t fatten” to `fat=low` is a guess | **1** (do not invent 2) |
| Low-fat SKUs | `fat=low` | **1** | You may prefer **2** as a policy | Still **1** until you approve Rule E2 |

**Choose one rule:**

- **E1 (current, spec-strict):** no Grade 2; low-fat/skim = **1**.  
- **E2 (if you treat the claim as a low-fat request):** `fat=low` and `fat=non` cow milks = **2**; full cream = **1**.

These 15 are **not** genuine no-match. The catalog has milk; the **attribute is unsupported**.

---

## F. Diabetes — Glucerna only as 2

**Queries (Cebuano):** `gatas para sa may diabetes` (milk for someone with diabetes); `taas og sugar sa dugo` (high blood sugar); `diabetiko` / `mga diabetic`; `sakit sa asukal`; `lolo nga diabetic`; plus English equivalents.

| Product | Specs | Proposed | Recommended |
| --- | --- | --- | --- |
| Glucerna Diabetes Nutrition Milk 400g | diabetes in description; powder; `sugar=no_added` | **2** | **2** |
| Unsweetened / no-sugar-added milks | `sugar=unsweetened` or `no_added` | **1** | **1** |
| Sugar-free wafers | not milk | **0** | **0** |

**Rule:** disease/diabetes language → Glucerna **2** only. Do not promote unsweetened drinks to 2.

---

## G. Sugar wording (`walay asukal` / sugar-free / unsweetened)

**Queries:** `gatas nga walay asukal` (milk with no sugar); `dili tam-is` (not sweet); `sugar free milk`; `no sugar added`; `unsweetened`

| Product | Specs | Proposed | Recommended |
| --- | --- | --- | --- |
| Almond Breeze, Silk, Green Basket oat | `unsweetened` | **2** | **2** for “unsweetened” / `walay asukal` / `dili tam-is` |
| Nestlé NSA, Anlene, Glucerna | `no_added` | **2** | **2** for “no sugar added”; **1** for strict “unsweetened” |
| Glucerna | also “Sugar free” in description | **2** | **2** for “sugar free” |
| Nesquik / condensed | `sweetened` | **0** | **0** |

**Rule:** match the **sugar field / description**, not the word “milk” on other SKUs.

---

## H. Organic dairy vs organic oat

**Queries:** `gatas nga walay kemikal` (milk without chemicals); `walay pestisidyo`; `organic na fresh milk`; `usda organic milk`

| Product | Specs | Proposed | Recommended |
| --- | --- | --- | --- |
| Arla Organic, Horizon Organic, Organic Valley, Farm Fresh carabao (`Walay kemikal`) | `organic=true` dairy | **2** | **2** |
| Organic Valley | USDA in description | **2** on USDA query only | **2** USDA query; **1** other organic |
| Green Basket Organic Oat | organic **plant** | **1** | **1** |
| Organic brown rice / lettuce | not milk | **0** on milk queries | **0** |

---

## I. Kids / infant / UHT-for-kids — no exact SKU (not empty catalog)

| Query | English | Closest catalog | Proposed | Recommended |
| --- | --- | --- | --- | --- |
| `uht cow milk for kids` | UHT cow milk for children | Alaska Fresh Full Cream: **UHT** in description; no “kids” | all **1** for cow liquids; AF **1** | **1** on Alaska Fresh (fails “kids”). Not 2. |
| `gatas nga pang kids` | milk for kids | Bear Brand fortified powder — not labeled kids | powders/liquids **1** | **1**, no 2 |
| `Gatas para sa 1 year old` / `newborn` | formula ages | no formula SKU | **1** on powders | **1**, no 2 |
| `para sa bata pero dili pangbaby` | for a child, not infant | no growing-up milk | **1** | **1**, no 2 |

**Not genuine no-match:** milk exists. **Not too tight on 2:** specs never say kids/infant. Keep no Grade 2.

---

## J. Missing brand / flavor / probiotic / 3-in-1

| Query | English | Product | Specs | Proposed | Recommended |
| --- | --- | --- | --- | --- | --- |
| `Chuckie nga chocolate milk` | Chuckie brand | Nesquik chocolate 180 ml | different brand | Nesquik **1** | **1** |
| `gatas nga banana/strawberry flavor` | banana/strawberry milk | Nesquik | chocolate only | **1** | **1** |
| `gatas nga vanilla para sa adulto` | vanilla milk for adults | Anlene Gold | adult powder, **not vanilla** | Anlene **1** | **1** |
| `gatas nga probiotic` / `naay probiotics` | probiotic milk | none | no probiotic text | milks **1** | **1**, no 2 |
| `kape nga 3 in 1` | 3-in-1 coffee mix | Coffee-Mate; evaporada | creamer / cooking milk, not 3-in-1 | **1** | **1**, no 2 |

Genuine **brand/flavor/product** miss, with a substitute at **1**. Not over-strict.

---

## K. Pack and size (specs silent or not equal)

| Query | English | Catalog | Proposed | Recommended |
| --- | --- | --- | --- | --- |
| `gatas nga naa sa botelya` | milk in a bottle | no bottle in specs | liquids **1**, no 2 | **1** (spec-strict). **Restrictive vs real cartons/bottles.** |
| `Gatas nga naa sa karton` | milk in a carton | carton not in specs | same | **1**, no 2. Same caveat. |
| `Gatas nga naa sa lata` | milk in a tin/can | Evaporada & condensed = Canned Goods | those two **2**; powders **1** | **2** canned; powder tins **1** |
| `powdered cow milk in a tin` | powder in a tin | powders exist; tin not in specs | powders **1** | **1** |
| `Gatas nga thick creamer` | thick creamer | Evaporada **name/desc = creamer**; “thick” absent | AE/AC/CM **1** | **Recommend AE = 2** (creamer is in the SKU); AC/CM **1**; “thick” ignored |
| `Gatas nga 300 grams` | 300 g | Bear Brand **320 g** | powders **1** | **1** (320 ≠ 300) |
| `Gatas nga 680 grams` | 680 g | Anlene **600 g** | **1** | **1** |
| `Gatas nga 4.8 kilos` | 4.8 kg | Anlene `volume_ml=4500` is reconstituted drink, not 4.8 kg | **1** | **1**; do not 2 |

Botelya/karton are the main **over-strict** pair if you know PH fresh milk is boxed. Specs don’t say so → recommended stays **1** until you approve a “typical UHT carton = 2” rule.

---

## L. Brand / vegan / coffee (have Grade 2; confirm substitutes)

**Alaska nga gatas** (Alaska milk): all four Alaska SKUs **2**. Recommended: **2** on all four (brand-only query). Optional: drinking 2, evaporada/condensada 1.

**Nestlé nga fresh milk:** Nestlé Fresh 1L **2**; NSA **1**; skim/oat **0**. Recommended: keep.

**Mangita kog gatas para sa akong kape** (milk for my coffee): Evaporada **2** (description: cooking and coffee); cow liquids **1**; Coffee-Mate **1**. Recommended: keep (Coffee-Mate is not gatas).

**Gatas nga vegan pero dili chocolate:** Nestlé Vegan Oat + Green Basket (text “vegan”) **2**; Almond/Silk **1**; Nesquik **0**. Recommended: keep.

---

## The 43 queries with no Grade 2

| Type | Count | Verdict |
| ---: | ---: | --- |
| Genuine no-match (group A) | 9 | Correct all-0 |
| Health/weight claims (group E) | 15 | Milk exists; **attribute unsupported**. Choose E1 vs E2 |
| Kids/infant/UHT-kids (group I) | 5 | Milk exists; **kids/infant unsupported**. Keep no 2 |
| Flavor/brand/probiotic/3-in-1 (group J) | 6 | Exact product missing; substitute **1** is right |
| Pack/size (group K, no 2) | 8 | Tin/bottle/carton/grams: **1** is spec-strict; botelya/karton may be too tight; thick creamer: consider AE **2** |

**Over-strict vs catalog (only if you want a looser policy):** group E (health → low-fat 2), botelya/karton (boxed UHT 2), thick creamer (AE 2), UHT-for-kids (AF 2 if you drop “kids”).

**Not over-strict:** groups A, I (infant), J, gram sizes 300≠320.
