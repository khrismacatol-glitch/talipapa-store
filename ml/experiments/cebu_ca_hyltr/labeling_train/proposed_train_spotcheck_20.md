# Manual spot-check: 20 provisional training queries

Grades are **not** changed. Official training sheet, val (51), test (100), models, and Monday retrain are untouched.

G2 = exact product + stated attributes. G1 = same class or genuine substitute that fails an attribute. Specs are from the frozen 42-SKU catalog.

---

### 1. Milk — generic cow  
**`gusto ko og gatas nga gikan sa baka`** — I want milk from a cow. (#1)

| | Products | Specs used |
| --- | --- | --- |
| **G2 (16)** | Unflavored cow drinking milks + cow powders except Glucerna | `source=cow`, `is_milk=true`; form not required |
| **G1 (7)** | Carabao/goat; Glucerna; Nesquik; evaporada; condensada | Wrong animal; diabetes formula; chocolate; not drinking gatas |

**Why:** Generic cow’s milk may be liquid or powder. Glucerna is cow powder but a specialized diabetes formula → **1**. Nesquik is flavored cow drink → **1**. Plant drinks **0**.

---

### 2. Milk — size  
**`pure cow milk 1 liter`** (#3)

| | Products | Specs used |
| --- | --- | --- |
| **G2 (11)** | Alaska/Magnolia/Nestlé/Arla/Fresh Milk/Organic Valley 1L cow liquids | `source=cow`, `volume_ml=1000`, not powder |
| **G1 (9)** | Meiji 946 ml; Horizon 946 ml; carabao/goat 500 ml; cow powders | 946 ≠ 1000; wrong animal; form=powder |

**Why:** Explicit 1 liter requires exact 1000 ml. Reconstituted powder `volume_ml` is not pack size.

---

### 3. Milk — plant source  
**`soya milk`** (#119)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | Silk Unsweetened Soy Milk 946ml | `source=plant`; soy in name/description |
| **G1** | Almond Breeze; Green Basket oat; Nestlé oat | Other plant drinks, not soy |

**Why:** Product class is soy drink, not “any plant milk.” Goat/oat are not G2.

---

### 4. Brand  
**`Bear Brand nga gatas`** (#120)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | Bear Brand Fortified Powdered Milk 320g | `brand=bear brand`, `form=powder` |
| **G1** | Anchor, Anlene, Glucerna | Other cow powders |

**Why:** Brand + powdered milk. Liquids **0**.

---

### 5. Brand + product type (not brand alone)  
**`Alaska nga gatas`** (#143)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | Alaska Fresh Full Cream 1L; Alaska Low Fat 1L | `brand=alaska` and `is_milk=true` |
| **G1** | Alaska Evaporada; Alaska Condensada; other cow milks; UFC gata | Same brand but `is_milk=false` creamers; other brands |

**Why:** Evaporada description is “filled milk creamer”; condensada is sweetened condensed creamer — not drinking gatas.

---

### 6. Brand + “fresh milk”  
**`Nestlé nga fresh milk`** (#145)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | Nestlé Fresh Milk 1L; Nestlé Fresh Milk No Sugar Added 1L | `brand=nestle` and name contains “fresh milk” |
| **G1** | Other cow drinking milks and cow powders | Same class, other brand |

**Why:** Nestlé skim and Nestlé vegan oat are **0** (not named fresh milk; oat is plant).

---

### 7. Negation — cooking, not condensed  
**`Gatas nga pangluto pero dili condensed`** — cooking milk but not condensed. (#163)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | Alaska Evaporada Creamer 370ml | Description: cooking and coffee; not condensed |
| **G1** | Cow drinking liquids; UFC gata | Related cooking/pouring milk |
| **0** | Alaska Sweetened Condensed Milk 300ml | Excluded by `dili condensed` |

---

### 8. Negation — vegan, not chocolate  
**`Gatas nga vegan pero dili chocolate`** (#166)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | Nestlé Vegan Oat 300ml; Green Basket Organic Unsweetened Oat 1L | “vegan” in name/description |
| **G1** | Almond Breeze; Silk | Plant/dairy-free, vegan **not** in specs |
| **0** | Nesquik; Cadbury | Chocolate / not a vegan drink |

---

### 9. Negation — cow, not plant  
**`real cow milk not plant based`** (#10)

| | Products | Specs used |
| --- | --- | --- |
| **G2 (16)** | Same unflavored cow liquids + regular cow powders as #1 | `source=cow` |
| **G1** | Carabao/goat; Glucerna; Nesquik; evaporada; condensada | Wrong animal / formula / flavor / creamer |
| **0** | All `source=plant`; UFC gata | Excluded by “not plant based” |

---

### 10. Sugar — Cebuano “no sugar”  
**`gatas nga walay asukal`** — milk with no sugar. (#28)

| | Products | Specs used |
| --- | --- | --- |
| **G2 (4)** | Almond Breeze (`sugar=unsweetened`, “zero sugar”); Silk (`unsweetened`, “no sugar”); Green Basket (`unsweetened`); Glucerna (description: “Sugar free”) | Unsweetened **or** sugar-free text |
| **G1** | Nestlé NSA; Anlene (`sugar=no_added`); other cow milks | No-added is not the same claim |

---

### 11. Sugar — English unsweetened  
**`unsweetened milk`** (#36)

| | Products | Specs used |
| --- | --- | --- |
| **G2 (3)** | Almond Breeze; Silk; Green Basket oat | `sugar=unsweetened` only |
| **G1** | Glucerna, Nestlé NSA, Anlene (`no_added`); other milks | Different sugar field |

**Why:** Unsweetened ≠ no-added-sugar ≠ sugar-free.

---

### 12. Fat — non-fat  
**`gatas nga walay tambok`** — milk with no fat. (#12)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | Nestlé Non-Fat Skim 1L (`fat=non`); Anchor Skim Powder (`fat=non`, “99% fat free”) | `fat=non` |
| **G1** | Low-fat cow liquids (`fat=low`); Anlene/Glucerna (low); plant `fat=low` | Low-fat is not non-fat |

---

### 13. Fat — low-fat  
**`gatas nga gamay ra og tambok`** — milk with little fat. (#13)

| | Products | Specs used |
| --- | --- | --- |
| **G2 (4)** | Alaska LF; Magnolia LF 1.5%; Arla LF 1.5%; Horizon 1% | `fat=low`, cow drinking |
| **G1** | Nestlé skim + Anchor (`fat=non`); Anlene/Glucerna; plant low-fat | Non-fat kept distinct |

---

### 14. Health — diabetes (supported)  
**`gatas para sa may diabetes`** — milk for someone with diabetes. (#29)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | Glucerna Diabetes Nutrition Milk 400g | Description: “Diabetes-specific milk formula… helps manage blood sugar.” `sugar=no_added` |
| **G1** | Unsweetened/no-added milks and other cow milks | Not diabetes-specific nutrition |
| **0** | Sugar-free wafers | Biscuits, not gatas |

---

### 15. Health — weight (E1, no inference)  
**`Gatas nga dili makataba`** — milk that will not make you fat. (#74)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | *none* | No diet / “won’t fatten” field |
| **G1 (21)** | All drinking/powder/plant milks | Related milk only |

**Why:** Do not map the claim onto `fat=low`.

---

### 16. Non-milk grocery  
**`organic brown rice`** (#67)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | Organic Brown Rice 2kg | Rice; `organic=true` |
| **G1** | Jasmine Rice 5kg | Rice, not organic |
| **0** | Organic milks and lettuce | Shared adjective `organic` only |

---

### 17. Non-milk grocery  
**`low fat crackers`** (#68)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | Low Fat Whole Wheat Crackers 250g | Crackers; `fat=low` |
| **G1** | none | |
| **0** | Low-fat milks | Wrong product class |

---

### 18. Non-milk grocery  
**`tapa sa baka`** — beef tapa. (#70)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | Beef Tapa (Karne sa Baka) 500g | Cured beef; category Meat |
| **0** | All cow **milks** | `baka` here is beef, not milk |

---

### 19. Non-milk — coconut gata  
**`gata sa lubi`** — coconut cream. (#63)

| | Products | Specs used |
| --- | --- | --- |
| **G2** | UFC Coconut Milk (Gata) 400ml | Description: “Gata sa lubi…”; `is_milk=false` |
| **0** | Dairy gatas | Different product (`lubi` = coconut) |

---

### 20. No-match (Cebuano trap)  
**`presko nga isda`** — fresh **fish**. (#107)

| | Products | Specs used |
| --- | --- | --- |
| **G2 / G1** | *none* (all 42 are **0**) | `isda` = fish; no fish SKU |

**Why:** Do not score fresh milks on `presko`. Same all-0 pattern as `toyo` (soy sauce ≠ Silk soy milk).

---

Coverage: milk (1–3), brand (4–6), negation (7–9), sugar (10–11), fat (12–13), health (14–15), non-milk grocery (16–19), no-match (20).
