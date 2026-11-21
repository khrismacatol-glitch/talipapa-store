# Milk search evaluation (held-out test queries)

24 milk test queries (34 test queries in total). Relevant = grade 2. cR@5 = recall against min(5, |relevant|). Leak@5 = share of the top 5 that are not milk.

Intent classifier accuracy: 0.853. Milk vs non-milk routing accuracy: 0.853.

## Macro average, all milk test queries

| System | P@5 | R@5 | cR@5 | NDCG@5 | MRR | MAP | Leak@5 |
|---|---:|---:|---:|---:|---:|---:|---:|
| keyword | 0.500 | 0.308 | 0.500 | 0.657 | 0.600 | 0.532 | 0.014 |
| labse_cosine | 0.650 | 0.428 | 0.650 | 0.779 | 1.000 | 0.697 | 0.067 |
| labse_cosine_gate | 0.658 | 0.435 | 0.658 | 0.808 | 1.000 | 0.733 | 0.000 |
| ltr_text_only | 1.000 | 0.645 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| ltr_full | 1.000 | 0.645 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| laravel_semantic | 1.000 | 0.645 | 1.000 | 1.000 | 1.000 | 0.999 | 0.000 |

## Intent: cow

| System | P@5 | R@5 | cR@5 | NDCG@5 | MRR | MAP | Leak@5 |
|---|---:|---:|---:|---:|---:|---:|---:|
| keyword | 0.933 | 0.275 | 0.933 | 0.929 | 1.000 | 0.741 | 0.000 |
| labse_cosine | 0.700 | 0.206 | 0.700 | 0.826 | 1.000 | 0.750 | 0.000 |
| labse_cosine_gate | 0.700 | 0.206 | 0.700 | 0.826 | 1.000 | 0.780 | 0.000 |
| ltr_text_only | 1.000 | 0.294 | 1.000 | 1.000 | 1.000 | 0.999 | 0.000 |
| ltr_full | 1.000 | 0.294 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| laravel_semantic | 1.000 | 0.294 | 1.000 | 1.000 | 1.000 | 0.995 | 0.000 |

## Intent: low_fat

| System | P@5 | R@5 | cR@5 | NDCG@5 | MRR | MAP | Leak@5 |
|---|---:|---:|---:|---:|---:|---:|---:|
| keyword | 0.167 | 0.076 | 0.167 | 0.444 | 0.259 | 0.382 | 0.000 |
| labse_cosine | 0.600 | 0.273 | 0.600 | 0.790 | 1.000 | 0.626 | 0.000 |
| labse_cosine_gate | 0.600 | 0.273 | 0.600 | 0.790 | 1.000 | 0.635 | 0.000 |
| ltr_text_only | 1.000 | 0.455 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| ltr_full | 1.000 | 0.455 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| laravel_semantic | 1.000 | 0.455 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |

## Intent: diabetic

| System | P@5 | R@5 | cR@5 | NDCG@5 | MRR | MAP | Leak@5 |
|---|---:|---:|---:|---:|---:|---:|---:|
| keyword | 0.100 | 0.083 | 0.100 | 0.379 | 0.298 | 0.184 | 0.056 |
| labse_cosine | 0.400 | 0.333 | 0.400 | 0.559 | 1.000 | 0.464 | 0.233 |
| labse_cosine_gate | 0.433 | 0.361 | 0.433 | 0.659 | 1.000 | 0.554 | 0.000 |
| ltr_text_only | 1.000 | 0.833 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| ltr_full | 1.000 | 0.833 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| laravel_semantic | 1.000 | 0.833 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |

## Intent: organic

| System | P@5 | R@5 | cR@5 | NDCG@5 | MRR | MAP | Leak@5 |
|---|---:|---:|---:|---:|---:|---:|---:|
| keyword | 0.800 | 0.800 | 0.800 | 0.874 | 0.842 | 0.822 | 0.000 |
| labse_cosine | 0.900 | 0.900 | 0.900 | 0.943 | 1.000 | 0.946 | 0.033 |
| labse_cosine_gate | 0.900 | 0.900 | 0.900 | 0.956 | 1.000 | 0.963 | 0.000 |
| ltr_text_only | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| ltr_full | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| laravel_semantic | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |

## Required Cebuano queries

### Gatas sa baka

| System | P@5 | R@5 | cR@5 | NDCG@5 | MRR | MAP | Leak@5 |
|---|---:|---:|---:|---:|---:|---:|---:|
| keyword | 0.800 | 0.235 | 0.800 | 0.786 | 1.000 | 0.769 | 0.000 |
| labse_cosine | 0.600 | 0.176 | 0.600 | 0.771 | 1.000 | 0.745 | 0.000 |
| labse_cosine_gate | 0.600 | 0.176 | 0.600 | 0.771 | 1.000 | 0.792 | 0.000 |
| ltr_text_only | 1.000 | 0.294 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| ltr_full | 1.000 | 0.294 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| laravel_semantic | 1.000 | 0.294 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |

Top 5 (keyword): 1. Fresh Milk 1L; 2. Nestle Vegan Oat Milk 300ml; 3. Magnolia Fresh Milk 1L; 4. Alaska Fresh Full Cream Milk 1L; 5. Nestle Fresh Milk 1L

Top 5 (ltr_full): 1. Anlene Gold No Added Sugar Milk Powder 600g; 2. Glucerna Diabetes Nutrition Milk 400g; 3. Magnolia Low Fat Milk 1L; 4. Horizon Organic Low Fat Milk 946ml; 5. Nestle Non-Fat Skim Milk 1L

### Gatas nga dili makataba

| System | P@5 | R@5 | cR@5 | NDCG@5 | MRR | MAP | Leak@5 |
|---|---:|---:|---:|---:|---:|---:|---:|
| keyword | 0.000 | 0.000 | 0.000 | 0.333 | 0.111 | 0.293 | 0.000 |
| labse_cosine | 0.400 | 0.182 | 0.400 | 0.702 | 1.000 | 0.550 | 0.000 |
| labse_cosine_gate | 0.400 | 0.182 | 0.400 | 0.702 | 1.000 | 0.553 | 0.000 |
| ltr_text_only | 1.000 | 0.455 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| ltr_full | 1.000 | 0.455 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| laravel_semantic | 1.000 | 0.455 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |

Top 5 (keyword): 1. Fresh Milk 1L; 2. Nestle Vegan Oat Milk 300ml; 3. Magnolia Fresh Milk 1L; 4. Alaska Fresh Full Cream Milk 1L; 5. Nestle Fresh Milk 1L

Top 5 (ltr_full): 1. Anlene Gold No Added Sugar Milk Powder 600g; 2. Glucerna Diabetes Nutrition Milk 400g; 3. Green Basket Organic Unsweetened Oat Milk 1L; 4. Almond Breeze Unsweetened Almond Milk 946ml; 5. Silk Unsweetened Soy Milk 946ml

### Gatas para sa mga diabetic

| System | P@5 | R@5 | cR@5 | NDCG@5 | MRR | MAP | Leak@5 |
|---|---:|---:|---:|---:|---:|---:|---:|
| keyword | 0.000 | 0.000 | 0.000 | 0.333 | 0.071 | 0.151 | 0.000 |
| labse_cosine | 0.400 | 0.333 | 0.400 | 0.557 | 1.000 | 0.431 | 0.200 |
| labse_cosine_gate | 0.400 | 0.333 | 0.400 | 0.653 | 1.000 | 0.532 | 0.000 |
| ltr_text_only | 1.000 | 0.833 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| ltr_full | 1.000 | 0.833 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| laravel_semantic | 1.000 | 0.833 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |

Top 5 (keyword): 1. Fresh Milk 1L; 2. Nestle Vegan Oat Milk 300ml; 3. Magnolia Fresh Milk 1L; 4. Alaska Fresh Full Cream Milk 1L; 5. Nestle Fresh Milk 1L

Top 5 (ltr_full): 1. Glucerna Diabetes Nutrition Milk 400g; 2. Anlene Gold No Added Sugar Milk Powder 600g; 3. Green Basket Organic Unsweetened Oat Milk 1L; 4. Almond Breeze Unsweetened Almond Milk 946ml; 5. Silk Unsweetened Soy Milk 946ml

### Gatas na organic

| System | P@5 | R@5 | cR@5 | NDCG@5 | MRR | MAP | Leak@5 |
|---|---:|---:|---:|---:|---:|---:|---:|
| keyword | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| labse_cosine | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| labse_cosine_gate | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| ltr_text_only | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| ltr_full | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |
| laravel_semantic | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 1.000 | 0.000 |

Top 5 (keyword): 1. Organic Valley Whole Milk 1L; 2. Arla Organic Whole Milk 1L; 3. Horizon Organic Low Fat Milk 946ml; 4. Farm Fresh Organic Carabao Milk 500ml; 5. Green Basket Organic Unsweetened Oat Milk 1L

Top 5 (ltr_full): 1. Horizon Organic Low Fat Milk 946ml; 2. Arla Organic Whole Milk 1L; 3. Organic Valley Whole Milk 1L; 4. Farm Fresh Organic Carabao Milk 500ml; 5. Green Basket Organic Unsweetened Oat Milk 1L
