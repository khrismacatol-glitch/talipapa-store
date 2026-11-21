<?php

/*
| Milk catalog used by MilkCatalogSeeder and exported to ml/data/products.json
| for training and evaluating the semantic ranker.
|
| specs:
|   is_milk  drinking milk (fresh, UHT, powdered, plant). Condensed milk,
|            creamer, milk tea, cheese, etc. are false.
|   source   cow | carabao | goat | plant | null
|   fat      full | low | non | null
|   sugar    regular (no added sugar label, natural lactose only)
|            | no_added (labelled "no sugar added" / diabetic)
|            | unsweetened (plant milk with zero sugar)
|            | sweetened | null
|   organic  certified organic
|   form     liquid | powder | null
*/

$milk = fn (string $source, string $fat, string $sugar, bool $organic, int $volumeMl, ?string $brand = null, string $form = 'liquid') => [
    'is_milk' => true,
    'source' => $source,
    'fat' => $fat,
    'sugar' => $sugar,
    'organic' => $organic,
    'volume_ml' => $volumeMl,
    'brand' => $brand,
    'form' => $form,
];

$other = fn (?string $sugar = null, bool $organic = false, ?string $fat = null, ?string $brand = null) => [
    'is_milk' => false,
    'source' => null,
    'fat' => $fat,
    'sugar' => $sugar,
    'organic' => $organic,
    'volume_ml' => null,
    'brand' => $brand,
    'form' => null,
];

$store1 = 'store@repomart.test';
$store2 = 'store2@repomart.test';
$store3 = 'store3@repomart.test';

return [

    // Cow's milk (full cream / regular).
    ['store' => $store2, 'category_slug' => 'dairy-eggs-cheese', 'price' => 105.00,
        'name' => 'Magnolia Fresh Milk 1L',
        'description' => 'Pasteurized full cream fresh milk from local dairy cows.',
        'specs' => $milk('cow', 'full', 'regular', false, 1000, 'magnolia')],
    ['store' => $store1, 'category_slug' => 'dairy-eggs-cheese', 'price' => 99.00,
        'name' => 'Alaska Fresh Full Cream Milk 1L',
        'description' => 'Rich and creamy 100% fresh cow milk, UHT processed.',
        'specs' => $milk('cow', 'full', 'regular', false, 1000, 'alaska')],
    ['store' => $store2, 'category_slug' => 'dairy-eggs-cheese', 'price' => 112.00,
        'name' => 'Nestle Fresh Milk 1L',
        'description' => 'Pure fresh milk from cows. Natural source of calcium and protein.',
        'specs' => $milk('cow', 'full', 'regular', false, 1000, 'nestle')],
    ['store' => $store1, 'category_slug' => 'dairy-eggs-cheese', 'price' => 168.00,
        'name' => 'Bear Brand Fortified Powdered Milk 320g',
        'description' => 'Powdered milk drink made from cow milk, fortified with iron and zinc.',
        'specs' => $milk('cow', 'full', 'regular', false, 2400, 'bear brand', 'powder')],
    ['store' => $store3, 'category_slug' => 'dairy-eggs-cheese', 'price' => 135.00,
        'name' => 'Meiji Whole Milk 946ml',
        'description' => 'Japanese style whole milk from Holstein cows.',
        'specs' => $milk('cow', 'full', 'regular', false, 946, 'meiji')],

    // Other animal milks: still milk, but not from a cow.
    ['store' => $store1, 'category_slug' => 'dairy-eggs-cheese', 'price' => 95.00,
        'name' => 'Fresh Carabao Milk 500ml',
        'description' => 'Gatas sa kalabaw from a Nueva Ecija dairy cooperative.',
        'specs' => $milk('carabao', 'full', 'regular', false, 500)],
    ['store' => $store2, 'category_slug' => 'dairy-eggs-cheese', 'price' => 120.00,
        'name' => 'Fresh Goat Milk 500ml',
        'description' => 'Gatas sa kanding. Easy to digest goat milk.',
        'specs' => $milk('goat', 'full', 'regular', false, 500)],

    // Low fat / non-fat.
    ['store' => $store2, 'category_slug' => 'dairy-eggs-cheese', 'price' => 108.00,
        'name' => 'Magnolia Low Fat Milk 1L',
        'description' => 'Fresh cow milk with reduced fat, only 1.5% fat.',
        'specs' => $milk('cow', 'low', 'regular', false, 1000, 'magnolia')],
    ['store' => $store1, 'category_slug' => 'dairy-eggs-cheese', 'price' => 115.00,
        'name' => 'Nestle Non-Fat Skim Milk 1L',
        'description' => 'Fat-free skim milk, 0% fat. Same calcium as regular milk.',
        'specs' => $milk('cow', 'non', 'regular', false, 1000, 'nestle')],
    ['store' => $store3, 'category_slug' => 'dairy-eggs-cheese', 'price' => 245.00,
        'name' => 'Anchor Skim Milk Powder 400g',
        'description' => 'New Zealand skimmed milk powder, 99% fat free.',
        'specs' => $milk('cow', 'non', 'regular', false, 3200, 'anchor', 'powder')],
    ['store' => $store1, 'category_slug' => 'dairy-eggs-cheese', 'price' => 104.00,
        'name' => 'Alaska Low Fat Milk 1L',
        'description' => 'Light milk with less fat for weight watchers.',
        'specs' => $milk('cow', 'low', 'regular', false, 1000, 'alaska')],
    ['store' => $store2, 'category_slug' => 'dairy-eggs-cheese', 'price' => 155.00,
        'name' => 'Arla Lactose Free Semi-Skimmed Milk 1L',
        'description' => 'Semi-skimmed cow milk, 1.5% fat, lactose free.',
        'specs' => $milk('cow', 'low', 'regular', false, 1000, 'arla')],

    // No added sugar / unsweetened (for diabetics).
    ['store' => $store2, 'category_slug' => 'nutrition-wellness', 'price' => 389.00,
        'name' => 'Anlene Gold No Added Sugar Milk Powder 600g',
        'description' => 'High calcium adult milk powder with no added sugar, low fat.',
        'specs' => $milk('cow', 'low', 'no_added', false, 4500, 'anlene', 'powder')],
    ['store' => $store1, 'category_slug' => 'nutrition-wellness', 'price' => 675.00,
        'name' => 'Glucerna Diabetes Nutrition Milk 400g',
        'description' => 'Diabetes-specific milk formula with slow-release carbohydrates. Sugar free, helps manage blood sugar.',
        'specs' => $milk('cow', 'low', 'no_added', false, 1800, 'glucerna', 'powder')],
    ['store' => $store2, 'category_slug' => 'beverages', 'price' => 185.00,
        'name' => 'Almond Breeze Unsweetened Almond Milk 946ml',
        'description' => 'Plant-based almond milk with zero sugar, only 30 calories per glass.',
        'specs' => $milk('plant', 'low', 'unsweetened', false, 946, 'almond breeze')],
    ['store' => $store3, 'category_slug' => 'beverages', 'price' => 165.00,
        'name' => 'Silk Unsweetened Soy Milk 946ml',
        'description' => 'Dairy-free soy milk with no sugar, good source of protein.',
        'specs' => $milk('plant', 'low', 'unsweetened', false, 946, 'silk')],
    ['store' => $store1, 'category_slug' => 'dairy-eggs-cheese', 'price' => 118.00,
        'name' => 'Nestle Fresh Milk No Sugar Added 1L',
        'description' => 'Fresh cow milk with no sugar added. Suitable for people watching their sugar intake.',
        'specs' => $milk('cow', 'full', 'no_added', false, 1000, 'nestle')],

    // Organic.
    ['store' => $store2, 'category_slug' => 'dairy-eggs-cheese', 'price' => 295.00,
        'name' => 'Organic Valley Whole Milk 1L',
        'description' => 'USDA certified organic whole milk from pasture-raised cows.',
        'specs' => $milk('cow', 'full', 'regular', true, 1000, 'organic valley')],
    ['store' => $store2, 'category_slug' => 'dairy-eggs-cheese', 'price' => 275.00,
        'name' => 'Arla Organic Whole Milk 1L',
        'description' => 'Certified organic milk. No pesticides, no artificial fertilizer, no antibiotics.',
        'specs' => $milk('cow', 'full', 'regular', true, 1000, 'arla')],
    ['store' => $store3, 'category_slug' => 'dairy-eggs-cheese', 'price' => 310.00,
        'name' => 'Horizon Organic Low Fat Milk 946ml',
        'description' => 'Certified organic 1% low fat milk.',
        'specs' => $milk('cow', 'low', 'regular', true, 946, 'horizon')],
    ['store' => $store2, 'category_slug' => 'beverages', 'price' => 198.00,
        'name' => 'Green Basket Organic Unsweetened Oat Milk 1L',
        'description' => 'Organic oat drink with no added sugar. Vegan and dairy-free.',
        'specs' => $milk('plant', 'low', 'unsweetened', true, 1000, 'green basket')],
    ['store' => $store1, 'category_slug' => 'dairy-eggs-cheese', 'price' => 150.00,
        'name' => 'Farm Fresh Organic Carabao Milk 500ml',
        'description' => 'Organic carabao milk from grass-fed carabaos in Bohol. Walay kemikal.',
        'specs' => $milk('carabao', 'full', 'regular', true, 500)],

    // Sweetened milk drink: milk, but wrong for diabetics.
    ['store' => $store3, 'category_slug' => 'beverages', 'price' => 38.00,
        'name' => 'Nesquik Chocolate Milk Drink 180ml',
        'description' => 'Chocolate flavored cow milk drink. Sweetened.',
        'specs' => $milk('cow', 'full', 'sweetened', false, 180, 'nesquik')],

    // Negative samples: not milk, several share words with milk queries.
    ['store' => $store1, 'category_slug' => 'canned-goods-soups', 'price' => 62.00,
        'name' => 'Alaska Sweetened Condensed Milk 300ml',
        'description' => 'Sweetened condensed creamer for desserts, leche flan and halo-halo.',
        'specs' => $other('sweetened', brand: 'alaska')],
    ['store' => $store1, 'category_slug' => 'canned-goods-soups', 'price' => 42.00,
        'name' => 'Alaska Evaporada Creamer 370ml',
        'description' => 'Evaporated filled milk creamer for cooking and coffee.',
        'specs' => $other('regular', brand: 'alaska')],
    ['store' => $store3, 'category_slug' => 'cookies-snacks-candy', 'price' => 125.00,
        'name' => 'Cadbury Dairy Milk Chocolate Bar 165g',
        'description' => 'Milk chocolate bar.',
        'specs' => $other('sweetened', brand: 'cadbury')],
    ['store' => $store3, 'category_slug' => 'beverages', 'price' => 89.00,
        'name' => 'Wintermelon Milk Tea 500ml',
        'description' => 'Ready-to-drink milk tea with tapioca pearls.',
        'specs' => $other('sweetened')],
    ['store' => $store1, 'category_slug' => 'canned-goods-soups', 'price' => 45.00,
        'name' => 'UFC Coconut Milk (Gata) 400ml',
        'description' => 'Gata sa lubi for ginataan and curry. Coconut cream for cooking.',
        'specs' => $other(brand: 'ufc')],
    ['store' => $store3, 'category_slug' => 'beverages', 'price' => 155.00,
        'name' => 'Coffee-Mate Original Creamer 450g',
        'description' => 'Non-dairy coffee creamer powder.',
        'specs' => $other(brand: 'coffee-mate')],
    ['store' => $store1, 'category_slug' => 'dairy-eggs-cheese', 'price' => 85.00,
        'name' => 'Eden Cheese 165g',
        'description' => 'Processed cheddar cheese from milk.',
        'specs' => $other(brand: 'eden')],
    ['store' => $store2, 'category_slug' => 'dairy-eggs-cheese', 'price' => 145.00,
        'name' => 'Magnolia Butter Salted 225g',
        'description' => 'Creamy butter made from cow cream.',
        'specs' => $other(fat: 'full', brand: 'magnolia')],
    ['store' => $store3, 'category_slug' => 'frozen-foods', 'price' => 299.00,
        'name' => 'Selecta Cookies and Cream Ice Cream 1.4L',
        'description' => 'Ice cream made with fresh milk.',
        'specs' => $other('sweetened', brand: 'selecta')],
    ['store' => $store2, 'category_slug' => 'grains-pasta-sides', 'price' => 210.00,
        'name' => 'Organic Brown Rice 2kg',
        'description' => 'Certified organic unpolished brown rice.',
        'specs' => $other(organic: true)],
    ['store' => $store2, 'category_slug' => 'cookies-snacks-candy', 'price' => 78.00,
        'name' => 'Sugar-Free Wafers for Diabetics 150g',
        'description' => 'Vanilla wafers sweetened with stevia. No sugar added.',
        'specs' => $other('no_added')],
    ['store' => $store3, 'category_slug' => 'cookies-snacks-candy', 'price' => 58.00,
        'name' => 'Low Fat Whole Wheat Crackers 250g',
        'description' => 'Baked crackers with less fat.',
        'specs' => $other(fat: 'low')],
    ['store' => $store1, 'category_slug' => 'meat-seafood', 'price' => 260.00,
        'name' => 'Beef Tapa (Karne sa Baka) 500g',
        'description' => 'Cured beef from cow, ready to fry.',
        'specs' => $other()],
    ['store' => $store2, 'category_slug' => 'fruits-vegetables', 'price' => 140.00,
        'name' => 'Organic Lettuce 250g',
        'description' => 'Pesticide-free organic romaine lettuce.',
        'specs' => $other(organic: true)],

];
