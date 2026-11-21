# Train-query review vs 42-SKU experimental catalog

**Status:** review only. No grades written. No retraining. Production `ml/models/` not used.

**Definition used here (proposed, not labels):**
- **Likely Grade 2:** at least one SKU would satisfy the shopper on name + `products.json` specs/description.
- **Related-only:** no exact SKU; at least one SKU is the same family or a near miss (proposed 1, not 2).
- **No-match:** nothing in the 42 should be 2 or 1 (proposed all 0).
- **Needs judgment:** 2 vs 1 (or 1 vs 0) is not forced by specs. These still appear in a primary bucket.

Train rows in `splits.csv`: **171**. Long-sheet grades for train: still blank.

## Catalog shorthand

| Code | Product (from `products.json`) | Specs that matter |
| --- | --- | --- |
| AF | Alaska Fresh Full Cream Milk 1L | cow, full, 1000 ml, UHT |
| AL | Alaska Low Fat Milk 1L | cow, low, 1000 ml |
| AE | Alaska Evaporada Creamer 370ml | `is_milk` false; cooking/coffee creamer |
| AC | Alaska Sweetened Condensed Milk 300ml | `is_milk` false; sweetened; leche flan/halo-halo |
| AB | Almond Breeze Unsweetened Almond Milk 946ml | plant, unsweetened, 30 cal |
| AN | Anchor Skim Milk Powder 400g | cow, powder, fat non, NZ, 400 g / 3200 ml recon. |
| AG | Anlene Gold No Added Sugar Milk Powder 600g | cow, powder, low fat, no_added, adult calcium, 600 g |
| ARL | Arla Lactose Free Semi-Skimmed Milk 1L | cow, low, 1.5% fat, lactose free |
| ARO | Arla Organic Whole Milk 1L | cow, full, organic |
| BB | Bear Brand Fortified Powdered Milk 320g | cow, powder, iron+zinc, 320 g |
| BT | Beef Tapa (Karne sa Baka) 500g | not milk |
| CAD | Cadbury Dairy Milk Chocolate Bar 165g | not milk |
| CM | Coffee-Mate Original Creamer 450g | not milk; non-dairy creamer |
| ED | Eden Cheese 165g | not milk |
| FF | Farm Fresh Organic Carabao Milk 500ml | carabao, organic, Bohol |
| FC | Fresh Carabao Milk 500ml | carabao, Nueva Ecija |
| FG | Fresh Goat Milk 500ml | goat |
| FM | Fresh Milk 1L | cow, full, 1000 ml, no brand |
| GL | Glucerna Diabetes Nutrition Milk 400g | cow, powder, diabetic, no_added, 400 g |
| GB | Green Basket Organic Unsweetened Oat Milk 1L | plant, organic, unsweetened, vegan |
| HZ | Horizon Organic Low Fat Milk 946ml | cow, low, organic, 1%, 946 ml |
| JR | Jasmine Rice 5kg | distractor |
| CR | Low Fat Whole Wheat Crackers 250g | not milk, low fat |
| MB | Magnolia Butter Salted 225g | not milk |
| MF | Magnolia Fresh Milk 1L | cow, full, pasteurized |
| ML | Magnolia Low Fat Milk 1L | cow, low, 1.5% |
| MJ | Meiji Whole Milk 946ml | cow, full, Holstein / Japanese style |
| NQ | Nesquik Chocolate Milk Drink 180ml | cow, sweetened, chocolate, 180 ml |
| NF | Nestle Fresh Milk 1L | cow, full |
| NN | Nestle Fresh Milk No Sugar Added 1L | cow, full, no_added |
| NS | Nestle Non-Fat Skim Milk 1L | cow, fat non, 0% |
| NO | Nestle Vegan Oat Milk 300ml | plant, vegan, 300 ml, sugar regular |
| OB | Organic Brown Rice 2kg | not milk, organic |
| OL | Organic Lettuce 250g | not milk, organic |
| OV | Organic Valley Whole Milk 1L | cow, full, organic, USDA / pasture |
| PD | Pandesal (12 pcs) | distractor |
| SE | Selecta Cookies and Cream Ice Cream 1.4L | not milk; made with milk |
| SK | Silk Unsweetened Soy Milk 946ml | plant, unsweetened |
| SW | Sugar-Free Wafers for Diabetics 150g | not milk |
| UFC | UFC Coconut Milk (Gata) 400ml | not dairy milk |
| WT | Wintermelon Milk Tea 500ml | not milk |
| TM | Fresh Tomatoes 1kg | distractor |

**D** = cow drinking liquids: AF AL ARL ARO HZ MF ML MJ NF NN NS OV FM  
**F** = full-cream cow drinking: AF ARO MF MJ NF NN OV FM  
**L** = reduced-fat cow: AL ARL HZ ML NS  
**P** = cow powders: AN AG BB GL  
**V** = plant drinks: AB GB NO SK  

Nesquik is cow milk but flavored; carabao/goat are milk but not cow.

---

## Counts (proposed)

| Proposed category | Queries | Share of 171 |
| --- | ---: | ---: |
| Likely ≥1 Grade 2 | 146 | 85.4% |
| Related-only (no 2, some 1) | 16 | 9.4% |
| Genuine no-match (all 0) | 9 | 5.3% |
| **Needs manual judgment** (overlap) | **48** | 28.1% |

---

## Genuine no-match (9)

No SKU in the 42 is the product the shopper named.

| # | Query | Why all 0 |
| ---: | --- | --- |
| 59 | `toyo` | Soy sauce. Silk is soy *milk*, not toyo. |
| 60 | `itlog` | No eggs. |
| 66 | `yogurt` | No yogurt. |
| 71 | `sabon` | No soap. |
| 72 | `sardinas` | No sardines. |
| 73 | `asukal` | No sugar pack. SW is wafers, not sugar. |
| 86 | `1 litro nga coke` | No cola. WT is milk tea, not Coke. |
| 107 | `presko nga isda` | No fish. |
| 108 | `asin ug asukal` | No salt or sugar SKUs. |

---

## Related-only (16)

| # | Query | Why no 2 | Proposed 1 (not 2) |
| ---: | --- | --- | --- |
| 109 | `kape nga 3 in 1` | No 3-in-1 mix | CM (creamer only); AE |
| 112 | `gatas nga probiotic` | No SKU mentions probiotic | D as weak dairy drinks |
| 113 | `gatas nga pang kids` | No growing-up / Nido / Chuckie kids formula | BB (fortified family powder) |
| 116 | `gatas nga naa sa botelya` | No bottle pack in specs (1L/946 ml cartons, tins, 180 ml drink) | D as liquid milk |
| 134 | `Gatas para sa 1 year old` | No 1-year formula | P |
| 135 | `Gatas para sa newborn` | No infant formula | P (still wrong age) |
| 147 | `Chuckie nga chocolate milk` | No Chuckie SKU | NQ |
| 149 | `Gatas nga banana flavor` | No banana milk | NQ (flavored milk only) |
| 150 | `Gatas nga strawberry flavor` | No strawberry milk | NQ |
| 151 | `Gatas nga vanilla para sa adulto` | No vanilla milk; AG is adult but not vanilla; SW is vanilla wafers | AG, SW |
| 161 | `Kanang gatas nga naay probiotics` | Same as 112 | D |
| 164 | `Gatas nga para sa bata pero dili pangbaby` | No growing-up milk; BB not specified as 1+ | P |
| 169 | `Gatas nga 300 grams` | No 300 g pack; BB is **320 g**; NO is 300 **ml** | BB |
| 170 | `Gatas nga 680 grams` | No 680 g; AG is 600 g | AG |
| 171 | `Gatas nga 4.8 kilos` | No 4.8 kg bag; AG `volume_ml` 4500 is reconstituted drink, not 4.8 kg | none clear; do not treat 4500 ml as 4.8 kg |

---

## Likely ≥1 Grade 2 (146)

Potential Grade 2 SKUs are **proposals for the judge**, not filled cells.

### Cow / drinking (no extra constraint)

Potential 2 = **D** (optionally NQ as 1, not 2, unless flavor is asked). Carabao/goat/plant = 1 or 0 (judgment).

| # | Query | Potential Grade 2 |
| ---: | --- | --- |
| 1 | `gusto ko og gatas nga gikan sa baka` | D |
| 2 | `puro nga gatas sa among baka` | D |
| 6 | `regular dairy milk` | D |
| 91 | `palit ko og gatas nga gikan sa baka` | D |
| 111 | `gatas nga mainom dretso` | D |
| 114 | `gatas nga mainom` | D |
| 123 | `Gatas nga liquid` | D + NQ (liquid) |
| 125 | `Gatas nga pwede imnon kada adlaw` | D |
| 152 | `Naa moy gatas nga fresh?` | F + AL ML HZ (fresh/UHT liquids; not P) |
| 158 | `Kanang gatas nga pwede diretso imnon` | D |
| 162 | `Gatas nga fresh pero dili powdered` | D (not P) |

### Volume / process / breed / pack

| # | Query | Potential Grade 2 | Judgment |
| ---: | --- | --- | --- |
| 3 | `pure cow milk 1 liter` | AF AL ARL ARO MF ML NF NN NS OV FM (1000 ml cow) | HZ/MJ/AB/SK are 946 ml |
| 9 | `fresh milk ng baka 1 litro` | same 1000 ml cow drinking | 946 ml = 1? |
| 4 | `whole milk from cows` | F | vs L |
| 5 | `full cream fresh milk` | F | |
| 7 | `uht cow milk for kids` | **AF** (description: UHT) | “for kids” not in specs |
| 11 | `holstein cow fresh milk` | **MJ** (Holstein in description) | other F = 1? |
| 10 | `real cow milk not plant based` | D (not V, not UFC) | carabao/goat |
| 115 | `gatas nga fresh` | D liquids | vs UHT vs pasteurized |
| 126 | `Gatas nga naa sa karton` | boxed liquids D + V + NQ | vs lata |
| 127 | `Gatas nga naa sa lata` | AE AC + P (tin) | BB/AN/AG/GL vs canned cooking |
| 167 | `Gatas nga fresh pero dili low fat` | F | |

### Powder

| # | Query | Potential Grade 2 |
| ---: | --- | --- |
| 8 | `powdered cow milk` | P (all cow powder) |
| 55 | `gatas nga pulbos` | P |
| 56 | `gatas na pulbos` | P |
| 57 | `gatas sa pulbos` | P |
| 58 | `powdered cow milk in a tin` | P |

### Low fat / skim / diet

Potential 2 = **L + NS + AN** (skim powder). AG is low-fat powder. Plant unsweetened is low fat but not “gatas sa baka”.

| # | Query | Potential Grade 2 |
| ---: | --- | --- |
| 12 | `gatas nga walay tambok` | NS AN (0% / 99% fat free) | L may be 1 (still has fat) |
| 13 | `gatas nga gamay ra og tambok` | L + AG | vs NS |
| 15 | `gatas para sa nag diet` | L NS AN AB | health-claim |
| 16 | `gatas para sa gusto mo niwang` | L NS AN AB | |
| 17 | `skim nga gatas` | NS AN | |
| 18 | `non fat milk` | NS AN | |
| 19 | `fat free milk` | NS AN | |
| 20 | `reduced fat milk` | L | |
| 21 | `light milk for weight loss` | L NS AL (“Light milk”) | |
| 22 | `milk for dieting low calorie` | AB (30 cal) + L NS | |
| 23 | `semi skimmed milk` | ARL ML (1.5% in description) | |
| 24 | `gatas na walang taba` | NS AN | |
| 25 | `gatas para sa nagda-diet` | L NS | |
| 27 | `low fat na gatas para pumayat` | L | |
| 74 | `Gatas nga dili makataba` | L NS | claim vs fat spec |
| 75 | `gatas nga dili kaayo makataba` | L | |
| 76 | `gatas nga dili makataba para sa diet` | L NS | |
| 77 | `gatas dili makataba` | L NS | |
| 78 | `gatas na hindi makataba` | L NS | |
| 92 | `gatas nga gamay ang tambok` | L | |
| 93 | `gatas para dili motaas ang timbang` | L NS | |
| 94 | `non-fat nga gatas` | NS AN | |
| 95 | `gatas nga gaan ra ang tambok para sa diet` | L | |
| 96 | `skim milk nga walay tambok` | NS AN | |
| 97 | `gatas para sa nagpaniwang` | L NS | |
| 98 | `gatas nga dili makadugang og timbang` | L NS | |
| 124 | `Gatas nga non-fat` | NS AN | |
| 155 | `Naa moy gatas nga dili kaayo tambok?` | L | |
| 165 | `Gatas nga gikan sa baka pero dili full cream` | L NS AN (cow, not full) | |

### Diabetic / sugar

Clear 2 when the query names the product type that exists: **GL** for diabetes powder; **AB SK GB** unsweetened plant; **NN AG** no_added; **SW** only if the query is biscuits.

| # | Query | Potential Grade 2 |
| ---: | --- | --- |
| 28 | `gatas nga walay asukal` | NN AG GL AB SK GB |
| 29 | `gatas para sa may diabetes` | **GL** | other unsweetened = 1? |
| 30 | `gatas para sa taas og sugar sa dugo` | GL | |
| 31 | `gatas nga dili tam-is` | AB SK GB NN AG GL | NQ/AC = 0 |
| 32 | `gatas para sa diabetiko` | GL | |
| 33 | `gatas nga no sugar added` | NN AG GL GB | AB/SK unsweetened vs no_added |
| 34 | `sugar free milk` | GL AB SK GB | NN is no_added not “sugar free” |
| 35 | `no sugar added milk` | NN AG GL | |
| 36 | `unsweetened milk` | AB SK GB | |
| 37 | `diabetic milk powder` | **GL** | |
| 38 | `milk safe for diabetes` | GL | medical claim |
| 39 | `milk for people with high blood sugar` | GL | |
| 40 | `zero sugar almond milk` | **AB** | |
| 41 | `unsweetened soy milk` | **SK** | |
| 42 | `gatas na walang asukal` | NN AG GL AB SK GB | |
| 43 | `gatas para sa may diabetes na lolo` | GL (and maybe AG adult) | |
| 44 | `gatas na hindi matamis` | AB SK GB NN | |
| 45 | `gatas para sa mataas ang blood sugar` | GL | |
| 46 | `sugar free na gatas` | GL AB SK GB | |
| 47 | `gatas para sa may sakit sa asukal` | GL | |
| 79 | `Gatas para sa mga diabetic` | GL | |
| 80 | `gatas para sa diabetic` | GL | |
| 81 | `gatas para sa mga may diabetes` | GL | |
| 82 | `gatas para sa mga diabetic nga walay asukal` | GL | |
| 83 | `diabetic nga gatas` | GL | |
| 84 | `milk for diabetics` | GL | |
| 99 | `gatas para sa naay diabetes` | GL | |
| 100 | `gatas nga walay dugang nga asukal` | NN AG GL | |
| 101 | `mangita ko og gatas para sa diabetic` | GL | |
| 102 | `gatas nga dili makataas sa sugar` | GL | |
| 103 | `sugar free nga gatas` | GL AB SK GB | |
| 104 | `gatas para sa lolo nga diabetic` | GL AG | |
| 105 | `unsweetened nga gatas` | AB SK GB | |
| 106 | `gatas nga ubos ang asukal` | NN AG GL AB SK GB | |

### Organic / chemical-free

Organic milk SKUs: **ARO HZ GB OV FF**. Farm Fresh says “Walay kemikal”. OV says USDA.

| # | Query | Potential Grade 2 |
| ---: | --- | --- |
| 48 | `milk from organic farm` | ARO HZ GB OV FF |
| 49 | `milk without pesticides and chemicals` | ARO HZ OV FF GB | “walay kemikal” copy |
| 50 | `gatas nga walay kemikal` | FF (+ organic set) | |
| 51 | `natural nga gatas walay pestisidyo` | ARO HZ OV FF | |
| 52 | `gatas na walang kemikal` | FF + organic milks | |
| 53 | `natural at organic na fresh milk` | ARO HZ OV FF | GB is oat |
| 54 | `usda organic milk` | **OV** | other organic = 1 |
| 85 | `organic na fresh milk` | ARO HZ OV FF | |

### Brand / named SKU

| # | Query | Potential Grade 2 |
| ---: | --- | --- |
| 120 | `Bear Brand nga gatas` | **BB** |
| 143 | `Alaska nga gatas` | AF AL AE AC (brand match; which 2 is judgment) |
| 144 | `Anchor nga gatas` | **AN** |
| 145 | `Nestlé nga fresh milk` | **NF** (NN if “fresh” includes NSA) |
| 146 | `Anlene nga gatas` | **AG** |

### Plant / vegan / lactose / flavor / coffee

| # | Query | Potential Grade 2 |
| ---: | --- | --- |
| 119 | `soya milk` | **SK** |
| 122 | `Gatas nga walay lactose` | **ARL** |
| 117 | `naay flavor nga gatas` | **NQ** |
| 131 | `Gatas nga gikan sa tanom` | V |
| 148 | `Gatas nga chocolate flavor` | **NQ** |
| 156 | `Gusto kog gatas nga chocolate` | **NQ** |
| 160 | `Naa moy gatas nga dili gikan sa hayop?` | V (not UFC unless judge treats gata as plant milk) |
| 166 | `Gatas nga vegan pero dili chocolate` | GB NO (vegan in text); AB SK dairy-free | NQ = 0 |
| 153 | `Mangita kog gatas para sa akong kape` | D + AE CM | drinking vs creamer |

### Cooking / dessert

AC description names leche flan and halo-halo. AE is evaporated cooking/coffee. UFC is gata.

| # | Query | Potential Grade 2 |
| ---: | --- | --- |
| 64 | `condensed milk` | **AC** |
| 110 | `condensada nga gatas` | **AC** |
| 118 | `pangluto nga gatas` | AE AC UFC | D as 1 |
| 121 | `Gatas para sa dessert pero dili fresh milk` | **AC** (SE = ice cream, 1) |
| 128 | `Gatas nga evaporated` | **AE** |
| 129 | `Gatas nga condensed` | **AC** |
| 130 | `Gatas nga thick creamer` | AE AC CM | |
| 136 | `Gatas para sa mango float` | **AC** | AE 1 |
| 137 | `Gatas para sa fruit salad` | **AC** | |
| 138 | `Gatas para sa leche flan` | **AC** | |
| 139 | `Gatas para sa halo-halo` | **AC** | |
| 140 | `Gatas para sa champorado` | AC or D | which 2 |
| 141 | `Gatas para sa sopas` | AE AF/FM | |
| 142 | `Gatas para sa cereal` | D | |
| 154 | `Kanang gatas nga panghimog dessert` | AC | |
| 159 | `Mangita kog gatas nga pangluto og sopas` | AE | D 1 |
| 163 | `Gatas nga pangluto pero dili condensed` | **AE** (UFC if ginataan; not AC) | |
| 168 | `Gatas para sa dessert nga tam-is ug creamy` | **AC** | |

### Adult powder / age (with a catalog adult SKU)

| # | Query | Potential Grade 2 |
| ---: | --- | --- |
| 132 | `Gatas para sa tigulang` | **AG** | GL 1 |
| 133 | `Gatas para sa edaran` | **AG** | |
| 157 | `Naa moy gatas nga pang tigulang?` | **AG** | |

### Grocery (non-gatas) with an exact SKU

| # | Query | Potential Grade 2 |
| ---: | --- | --- |
| 61 | `butter` | **MB** |
| 62 | `milk tea` | **WT** |
| 63 | `gata sa lubi` | **UFC** |
| 65 | `coffee creamer` | **CM** | AE 1 |
| 67 | `organic brown rice` | **OB** | JR 1 (rice, not brown/organic) |
| 68 | `low fat crackers` | **CR** | |
| 69 | `karne sa baka` | **BT** | |
| 70 | `tapa sa baka` | **BT** | |
| 87 | `karne sa baka para sa sinugba` | **BT** | |
| 88 | `biskwit para sa diabetic` | **SW** | |
| 89 | `milk tea nga wintermelon` | **WT** | |
| 90 | `keso nga eden` | **ED** | |

Health-claim variants counted under low-fat with Grade 2 = L/NS, but flagged for judgment: 14 `gatas nga dili makapatambok`, 26 `gatas na hindi nakakataba`.

---

## Needs manual judgment (48)

Do not copy this list into the sheet. Decide 2 vs 1 using shopper intent.

**Fat vs “won’t make you fat”:** 12, 14, 15, 16, 21, 22, 26, 74–78, 93, 97, 98  
**Diabetes vs unsweetened vs Glucerna-only:** 29, 30, 34, 38, 39, 43, 82, 102, 104  
**Organic / kemikal / USDA:** 50, 54  
**Cow vs carabao/goat/plant on generic gatas:** 1, 2, 6, 10  
**UHT / kids / Holstein / 1 liter vs 946 ml:** 3, 7, 9, 11  
**Alaska brand (4 Alaska SKUs):** 143  
**Nestlé fresh vs no-sugar-added:** 145  
**Cooking: champorado, sopas, kape, thick creamer, dessert vs ice cream:** 118, 121, 130, 140, 141, 153, 163  
**Packaging botelya / lata / karton:** 116, 126, 127  
**Kids / vanilla adult / size grams:** 7, 113, 151, 169–171  
**Vegan vs dairy-free vs UFC gata:** 160, 166  
**Lactose-free uniqueness:** 122 is clear (ARL only) but other “diet” milks may steal rank if labeled 2  

---

## Suitability for LambdaMART (train only)

**Yes, as a labeled LTR pool — after you grade it. Not as auto-qrels.**

Why it can work on 42 SKUs:
- 146/171 queries have at least one catalog hit, so most groups can have a 2 vs 0 contrast.
- Attribute slices are real in specs: fat, powder, organic, plant, lactose-free, diabetic powder, cooking vs drinking, brands.
- 9 hard negatives (soap, eggs, toyo, …) teach the ranker not to force a milk SKU.

Why it is not “ready to train” today:
- All 171 train grades are still empty.
- This review is **not** a substitute for your 2/1/0 cells.
- If too many generic cow queries get 2 on all of **D**, LambdaMART gets little within-milk signal (that is what val/test already stress).
- All-zero groups (the 9 no-matches) add no pairwise preference; keep them for documentation or give them explicit 0s and accept no LTR gain from those queries.
- Related-only (16) must not be auto-promoted to 2 (Nido/Chuckie/300 g/botelya).

**Recommendation:** keep the 171-query train split. Label it on the wide sheet the same way as val/test. Prefer strict 2s (exact attribute/brand/form). Then retrain **only** under `ml/experiments/cebu_ca_hyltr/`. Do not write `ml/models/ranker.txt`. Do not run Stage 2 weight search on test. Do not promote.

Val (51) and test (100) stay frozen for that comparison.
