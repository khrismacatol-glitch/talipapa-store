# Grocery labeling inventory

Family counts below are **reporting heuristics** from product names.
They are **not** relevance labels and must not be copied into `qrels.csv`.

- Product rows: **768**
- Unique names: **706**
- Source: `ml/data/live_products.json (encoded catalog)`
- Current MySQL `available()` snapshot: `ml/data/grocery/products_mysql_available.json`

## Unique names by labeling family

| Family | Unique names |
|---|---:|
| bath_soap | 12 |
| canned | 33 |
| dishwashing | 5 |
| household | 28 |
| laundry_soap | 36 |
| milk | 12 |
| rice | 2 |
| rice_adjacent_not_table_rice | 2 |
| seafood | 31 |

## Store aisle (category field)

| Aisle | Rows |
|---|---:|
| Buy It Again | 600 |
| Dairy, Eggs & Cheese | 44 |
| Fruits & Vegetables | 40 |
| Beverages | 26 |
| Grains, Pasta & Sides | 23 |
| Cookies, Snacks & Candy | 23 |
| Bread & Bakery | 3 |
| Meat & Seafood | 3 |
| Canned Goods & Soups | 3 |
| Nutrition & Wellness | 2 |
| Frozen Foods | 1 |

## Gaps

- No listing name contains tinapa. Tinapa queries have no grade-2 SKU until you add smoked fish / tinapa.
- Store aisle 'Buy It Again' holds most encoded SKUs; aisle names are not reliable family labels.
- Inventory families can overlap (e.g. sardines in canned and seafood). Overlap is not a relevance grade.

## Queries in queries.csv

Total: **83**

Splits: train=43, val=16, test=24

Families: milk=12, canned=11, seafood=10, rice=10, laundry_soap=10, bath_soap=10, dishwashing=10, household=10

Languages: ceb=21, en=43, tl=19
