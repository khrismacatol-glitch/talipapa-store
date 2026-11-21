# Product-first labeling rules (approved A–L; provisional sheet only)

**Status:** rules approved with refinements A–L. Applied to the **provisional** train sheet only. Official `labeling_sheet_train_wide.csv` stays blank until final approval.

## Grade meanings

| Grade | Meaning |
| ---: | --- |
| **2** | This SKU **is** the requested product **and** matches the stated attributes (brand, form, size, fat, sugar, source, exclusion). |
| **1** | Same **product class** (or a close substitute a shopper might still consider), but it **fails** a stated attribute. |
| **0** | Different product class, or irrelevant. |

Never assign **2** only because the query and the SKU share an adjective (`fresh`, `presko`, `organic`, `low fat`, `sugar-free`, `powder`, `milk` in “dairy milk chocolate”).

---

## Step 1 — Name the requested product class

Before looking at fat/organic/size, decide **what object** the shopper wants.

| Class | Typical queries | Catalog SKUs that can be 2 |
| --- | --- | --- |
| Cow’s milk (generic) | `gatas sa baka`, `regular dairy milk` | Cow **liquids and powders** (`source=cow`). Require liquid only if the query says fresh/liquid/1 liter/`mainom`. |
| Powdered milk | `pulbos`, `powdered milk` | Anchor, Anlene, Bear Brand, Glucerna |
| Plant drink | `almond`, `soya`, `oat`, `vegan`, `gikan sa tanom` | Almond Breeze, Silk, Green Basket oat, Nestlé oat |
| Cooking dairy | `condensada`, `evaporated`, `pangluto`, dessert names | Alaska condensed / evaporada |
| Coconut **gata** | `gata sa lubi`, `gata` | UFC Coconut Milk only |
| Creamer (not gatas) | `coffee creamer` | Coffee-Mate (query must ask for creamer, not `gatas`) |
| Grocery exact | butter, keso, tapa, rice, crackers, wafers, milk tea | That one SKU only |
| No class in catalog | `toyo`, `itlog`, `isda`, `sabon`, `coke`, `yogurt`, `Chuckie` | **All 0** (optional 1 only if you later decide a near-substitute) |

**Cebuano (do not English-keyword):**

| Word | Means | Not |
| --- | --- | --- |
| `isda` | fish | fresh milk (`presko`) |
| `toyo` | soy sauce | Silk soy milk |
| `gata` / `lubi` | coconut cream | dairy gatas |
| `karne sa baka` / `tapa` | beef | cow **milk** |
| `keso` | cheese | milk |
| `bugas` | rice | — |
| `dili` / `hindi` / `pero dili` | exclusion | ignore the rest of the query |

If Step 1 says “fish” or “soy sauce” or “cola”, **stop**. All 42 cells are **0**. Do not continue to attributes.

---

## Step 2 — Match attributes only inside that class

Only SKUs that passed Step 1 may receive **2** or **1**.

| Attribute in query | Grade 2 only if specs/description match | Else |
| --- | --- | --- |
| Brand (`Bear Brand`, `Alaska`, `Nestlé`, `Anlene`) | Brand **and** requested product type (`Alaska nga gatas` = Alaska drinking milk, not evaporada/condensada; `Nestlé nga fresh milk` = Nestlé products named fresh milk) | Other types of the same brand **1** or **0** |
| Form powder vs liquid | Require `form` only when the query specifies powder, liquid, fresh, or drinkable | Generic cow’s milk: cow powder may be **2** |
| Size `1 liter` / `1 litro` | `volume_ml=1000` | 946 ml **1**; 180 ml / 500 ml / reconstituted powder ml **0 or 1**, never 2 |
| Size `300 grams` etc. | Pack mass in **name** (320 g, 400 g, 600 g) — **not** `volume_ml` reconstituted | No exact pack → **no 2**; nearest powder **1** |
| Fat `skim` / `walay tambok` / `non-fat` | `fat=non` | `fat=low` → **1**; `fat=full` → **0 or 1** |
| Fat `low fat` / `gamay ang tambok` / `semi skimmed` | `fat=low` | `fat=non` is **1** (keep distinct); full cream **0 or 1** |
| Unsweetened | `sugar=unsweetened` | `no_added` **1**; Glucerna sugar-free text **1** |
| No sugar added / `walay dugang nga asukal` | `sugar=no_added` | unsweetened **1** |
| Sugar-free | “sugar free” in that SKU’s name/description (Glucerna) | unsweetened / no_added **1**; wafers are not milk |
| `walay asukal` / `dili tam-is` | unsweetened **or** sugar-free text | `no_added` **1** |
| Organic (generic) / walay kemikal | `organic=true` milk, including organic **plant** drinks | Non-organic milk **1** |
| Organic **fresh** / organic **cow** milk | organic dairy; plant organic is **not** 2 | Organic oat **1** |
| Source cow / baka | `source=cow` | Carabao/goat **1**; plant **0 or 1** |
| Holstein / UHT / pasteurized / Japanese | Text in **that SKU’s** description | Other cow liquids **1** |
| Chocolate / banana / strawberry / vanilla | Flavor in **name/description** | Nesquik is chocolate only; missing flavor → **no 2** |
| Lactose-free | Arla lactose-free | Other milk **1** |
| Diabetic | Glucerna if the query is disease/formula; unsweetened drinks are **1** unless you judge otherwise | Wafers are **biscuits**, not gatas |

**Intended use** (only if the query is a use, not a product name):

- `leche flan` / `halo-halo` / `mango float` / `fruit salad` → condensed **2**; evaporada **1**; drinking milk **1 or 0**
- `sopas` / `pangluto` → evaporada **2**; condensed **1** unless excluded; gata **1** if cooking coconut is plausible
- `cereal` / `mainom` / `diretso imnon` → drinking liquids **2**; powder **1**; condensed **0 or 1**
- `para sa kape` asking **gatas** → drinking milk **2**; evaporada is creamer (`is_milk=false`) **1**; Coffee-Mate **1**, not 2
- Health/weight (`dili makataba`, diet, niwang): **E1** — no Grade 2; do not infer low-fat benefits

---

## Step 3 — Apply exclusions (`dili` / `hindi` / `pero dili` / `not`)

The excluded attribute **cannot** be Grade 2.

| Query | Exclude from 2 |
| --- | --- |
| `dili powdered` / `dili fresh` / `dili condensed` / `dili full cream` / `dili low fat` / `dili chocolate` / `not plant based` / `dili pangbaby` | Any SKU whose specs match the excluded term |

Excluded SKUs become **0** if they fail the class, or **1** if they are still the right class but have the forbidden attribute.

---

## What the rejected draft did wrong (anti-examples)

| Query | Wrong (draft) | Product-first |
| --- | --- | --- |
| `presko nga isda` | 2 on fresh **milk** because `presko` | Class = fish → **all 0** |
| `organic brown rice` | 2 on organic **milk** | Class = rice → Organic Brown Rice **2** |
| `low fat crackers` | 2 on low-fat **milk** | Class = crackers → that SKU **2** |
| `soya milk` | 2 on goat and oat | Class = soy drink → Silk **2** |
| `pure cow milk 1 liter` | 2 on powder and 180 ml | Class = cow drinking milk **and** 1000 ml |
| `dili makapatambok` | 2 on every milk including full cream | Class = milk; fat claim is **judgment**, not 25×2 |

---

## Worked examples (for approval)

**A. `Bear Brand nga gatas`**  
Class = powdered milk + brand Bear Brand.  
2: Bear Brand Fortified Powdered Milk 320g.  
1: other cow powders.  
0: liquids, rice, tapa.

**B. `fresh milk ng baka 1 litro`**  
Class = cow drinking milk + 1 L.  
2: 1000 ml cow liquids (Alaska Fresh, Magnolia Fresh, Nestlé Fresh, Fresh Milk 1L, …).  
1: 946 ml cow; carabao/goat 500 ml.  
0: powders (not “fresh 1 litro”), Nesquik 180 ml, gata, rice.

**C. `Gatas nga pangluto pero dili condensed`**  
Class = cooking dairy; exclude condensed.  
2: Alaska Evaporada.  
1: drinking milk / UFC gata (judgment).  
0: Alaska condensed.

**D. `Chuckie nga chocolate milk`**  
Class = Chuckie brand chocolate milk. No SKU.  
2: none.  
1: Nesquik (other chocolate milk).  
0: Cadbury bar (not a drink), unflavored milk.

**E. `toyo`**  
Class = soy sauce. No SKU.  
**All 0.** Silk is soy **milk**.

---

## After you approve these rules

1. Build a **new** proposed sheet (not overwriting the blank original): product class per query, then grades.  
2. A separate **ambiguous list** (no auto-2): health claims (`dili makataba`, diet, niwang), UHT+kids, 1 L vs 946 ml, Alaska’s four SKUs, Nestlé Fresh vs NSA vs skim, Glucerna vs unsweetened, botelya/karton/lata, kape vs Coffee-Mate, 300 g vs 320 g, cow vs carabao on generic gatas.  
3. You still approve grades before any train copy or LambdaMART.

Val, test, production `ranker.txt`, and Monday 03:00 UTC `search:retrain` stay unchanged.
