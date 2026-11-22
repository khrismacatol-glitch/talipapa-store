<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Services\Voc\QueryAttributeExtractor;
use App\Services\VoiceAssistant\ProductSearchService;
use Database\Seeders\PsgcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductSearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PsgcSeeder::class);
        $this->service = app(ProductSearchService::class);
    }

    public function test_unit_match_finds_coke_one_liter(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create();

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Coke 1L',
            'description' => 'Carbonated drink',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Coke 500ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        $results = $this->service->searchFromNlp([
            'original_text' => '1 liter coke',
            'keywords' => ['coke', 'coca cola', 'soda'],
            'units' => ['1L', '1000ml', '1 liter'],
        ]);

        $this->assertNotEmpty($results);
        $this->assertSame('Coke 1L', $results->first()->name);
    }

    public function test_dialect_catalog_query_keeps_bugas_and_adds_rice(): void
    {
        $this->assertSame('bugas rice', $this->service->dialectCatalogQuery('bugas'));
        $this->assertSame('delata canned', $this->service->dialectCatalogQuery('delata'));
        $bridged = $this->service->dialectCatalogQuery('tinapa');
        $this->assertStringContainsString('tinapa', strtolower($bridged));
        $this->assertStringContainsString('smoked fish', strtolower($bridged));
        $this->assertStringContainsString('sardines', strtolower($bridged));
        $freshRewrite = strtolower($this->service->dialectCatalogQuery('Gatas nga fresh'));
        $this->assertStringContainsString('gatas nga fresh milk', $freshRewrite);
        $this->assertStringNotContainsString('evaporated', $freshRewrite);
        $this->assertStringNotContainsString('condensada', $freshRewrite);

        foreach (['gatas', 'tanan gatas', 'tanang gatas'] as $broadMilk) {
            $rewritten = strtolower($this->service->dialectCatalogQuery($broadMilk));
            $this->assertStringContainsString('milk', $rewritten);
            $this->assertStringContainsString('condensada', $rewritten);
            $this->assertStringContainsString('evaporated', $rewritten);
        }

        $cowMilk = strtolower($this->service->dialectCatalogQuery('gatas gikan sa baka'));
        $this->assertStringContainsString('milk', $cowMilk);
        $this->assertStringNotContainsString('condensada', $cowMilk);
        $this->assertStringNotContainsString('evaporated', $cowMilk);
        $lowFat = strtolower($this->service->dialectCatalogQuery('gatas nga dili makatambok'));
        $this->assertStringContainsString('low fat', $lowFat);
        $this->assertSame('organic brown rice', $this->service->dialectCatalogQuery('organic brown rice'));

        $laundry = strtolower($this->service->dialectCatalogQuery('sabon panglaba'));
        $this->assertStringContainsString('laundry detergent', $laundry);
        $this->assertStringContainsString('ariel', $laundry);
        $this->assertStringContainsString('tide', $laundry);

        $sudan = strtolower($this->service->dialectCatalogQuery('sud-an'));
        $this->assertStringContainsString('ulam', $sudan);
        $this->assertStringContainsString('sardines', $sudan);
        $this->assertStringNotContainsString('ariel', $sudan);

        $drinks = strtolower($this->service->dialectCatalogQuery('mainom'));
        $this->assertStringContainsString('drinks', $drinks);
        $this->assertStringContainsString('soda', $drinks);
        $this->assertStringNotContainsString('ariel', $drinks);

        $fabcon = strtolower($this->service->dialectCatalogQuery('pahumot sa sanina'));
        $this->assertStringContainsString('fabric conditioner', $fabcon);
        $this->assertStringContainsString('downy', $fabcon);
        $this->assertStringContainsString('fabcon', $fabcon);

        $hair = strtolower($this->service->dialectCatalogQuery('mga para sa buhok'));
        $this->assertStringContainsString('shampoo', $hair);
        $this->assertStringNotContainsString('downy', $hair);

        $breakfast = strtolower($this->service->dialectCatalogQuery('mga para pamahaw'));
        $this->assertStringContainsString('breakfast', $breakfast);
        $this->assertStringContainsString('pandesal', $breakfast);
        $this->assertStringNotContainsString('mustela', $breakfast);

        $cannedFish = strtolower($this->service->dialectCatalogQuery('ISDA nga de lata'));
        $this->assertStringContainsString('canned fish', $cannedFish);
        $this->assertStringContainsString('sardines', $cannedFish);
        $this->assertStringContainsString('tuna', $cannedFish);

        $salad = strtolower($this->service->dialectCatalogQuery('Mga para pang-salad'));
        $this->assertStringContainsString('salad', $salad);
        $this->assertStringContainsString('mayonnaise', $salad);
        $this->assertStringNotContainsString('mustela', $salad);

        $choco = strtolower($this->service->dialectCatalogQuery('tsokolate'));
        $this->assertStringContainsString('chocolate', $choco);
        $this->assertStringContainsString('choco', $choco);
    }

    public function test_drinking_milk_queries_exclude_infant_formula(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Milk 1L',
            'description' => 'Pasteurized fresh milk.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Nestle Low Fat Milk 1000 ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'NAN Optipro Infant Milk 800g',
            'description' => 'Infant formula for 0-6 months.',
            'is_available' => true,
            'stock' => 10,
        ]);

        foreach (['fresh milk', 'presko na gatas'] as $query) {
            $nlp = $extractor->extract($query);
            $this->assertContains('infant_formula', $nlp['exclusions'], $query);
            $names = $this->service->searchFromNlp($nlp)->pluck('name');
            $this->assertTrue($names->contains('Fresh Milk 1L'), $query);
            $this->assertFalse($names->contains('NAN Optipro Infant Milk 800g'), $query);
        }

        $lowFat = $extractor->extract('gatas nga dili makataba');
        $this->assertContains('infant_formula', $lowFat['exclusions']);
        $lowFatNames = $this->service->searchFromNlp($lowFat)->pluck('name');
        $this->assertTrue($lowFatNames->contains('Nestle Low Fat Milk 1000 ml'));
        $this->assertFalse($lowFatNames->contains('NAN Optipro Infant Milk 800g'));
    }

    public function test_presko_nga_gatas_keeps_liquid_milk_drops_powder_drinks_and_yakult(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);
        $make = fn (string $name, array $specs = []) => Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'description' => $name,
            'specs' => $specs,
            'is_available' => true,
            'stock' => 10,
        ]);

        $make('Fresh Milk 1L', ['is_milk' => true, 'source' => 'cow', 'form' => 'liquid']);
        $make('Arla Organic Full Cream Milk 1 L', ['is_milk' => true, 'source' => 'cow', 'form' => 'liquid']);
        $make('Alaska Fortified Powdered Filled Milk 300g', ['is_milk' => true, 'source' => 'cow', 'form' => 'powder']);
        $make('Bear Brand Fortified Choco Milk Drink');
        $make('Diabetasol Vanilla Milk 600g');
        $make('Selecta Banana Flavored Milk Drink 200 ml');
        $make('Yakult Original Cultured Milk 80ml');
        $make('Birch Tree Fortified Milk');
        $make('Rebisco Hansel Milk Sandwich');
        $make('Nescafe Creamy White Twin Pack 58g');
        $make("Palmolive Naturals Shampoo Intensive Moisture 15ml");

        $names = $this->service->searchFromNlp($extractor->extract('presko nga gatas'))->pluck('name');

        $this->assertTrue($names->contains('Fresh Milk 1L'));
        $this->assertTrue($names->contains('Arla Organic Full Cream Milk 1 L'));
        $this->assertFalse($names->contains('Alaska Fortified Powdered Filled Milk 300g'));
        $this->assertFalse($names->contains('Bear Brand Fortified Choco Milk Drink'));
        $this->assertFalse($names->contains('Diabetasol Vanilla Milk 600g'));
        $this->assertFalse($names->contains('Selecta Banana Flavored Milk Drink 200 ml'));
        $this->assertFalse($names->contains('Yakult Original Cultured Milk 80ml'));
        $this->assertFalse($names->contains('Birch Tree Fortified Milk'));
        $this->assertFalse($names->contains('Rebisco Hansel Milk Sandwich'));
        $this->assertFalse($names->contains('Nescafe Creamy White Twin Pack 58g'));
        $this->assertFalse($names->contains("Palmolive Naturals Shampoo Intensive Moisture 15ml"));
    }

    public function test_gatas_nga_pangdiet_keeps_low_fat_not_condensada(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Nestle Low Fat Milk 1000 ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Evaporada Evaporated Creamer 370g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Condensada Sweetened Condensed Creamer 300g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Crema-Asada 2-in-1 Sweetened Thick Creamer 300g',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('gatas nga pangdiet');
        $this->assertSame('low fat milk', $nlp['product']);
        $this->assertContains('infant_formula', $nlp['exclusions']);
        $this->assertContains('cooking_milk', $nlp['exclusions']);
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Nestle Low Fat Milk 1000 ml'));
        $this->assertFalse($names->contains('Alaska Evaporada Evaporated Creamer 370g'));
        $this->assertFalse($names->contains('Alaska Condensada Sweetened Condensed Creamer 300g'));
        $this->assertFalse($names->contains('Alaska Crema-Asada 2-in-1 Sweetened Thick Creamer 300g'));
    }

    public function test_gatas_wala_kaayoy_asukal_drops_sweetened_creamers(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Anlene Gold No Added Sugar Milk Powder 600g',
            'description' => 'High calcium adult milk powder with no added sugar, low fat.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Condensada Sweetened Condensed Creamer 300g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Evaporada Evaporated Creamer 370g',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('gatas nga wala kaayoy asukal');
        $this->assertContains('sugar', $nlp['exclusions']);
        $this->assertContains('cooking_milk', $nlp['exclusions']);
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Anlene Gold No Added Sugar Milk Powder 600g'));
        $this->assertFalse($names->contains('Alaska Condensada Sweetened Condensed Creamer 300g'));
        $this->assertFalse($names->contains('Alaska Evaporada Evaporated Creamer 370g'));
    }

    public function test_gatas_pwede_maluto_keeps_cooking_milk_not_drinks(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Angel Evaporada Liquid Creamer 365ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Angel Condensada Sweetened Condensed Creamer 168g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Dutch Mill UHT Yoghurt Drink Strawberry 180ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Yakult Original Cultured Milk 80ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Chuckie Nestle My Chocolatey Buddy Milk Drink 110mL',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Anlene Gold 5X Plain Milk Powder 300g',
            'description' => 'Adult nutrition milk powder.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Ensure Gold Vanilla 1.6kg',
            'description' => 'Adult nutritional powder milk drink.',
            'is_available' => true,
            'stock' => 10,
        ]);

        foreach (['gatas pwede maluto', 'gatas pangdessert', 'gatas nga pangluto'] as $query) {
            $nlp = $extractor->extract($query);
            $names = $this->service->searchFromNlp($nlp)->pluck('name');
            $this->assertTrue($names->contains('Angel Evaporada Liquid Creamer 365ml'), $query);
            $this->assertTrue($names->contains('Angel Condensada Sweetened Condensed Creamer 168g'), $query);
            $this->assertFalse($names->contains('Dutch Mill UHT Yoghurt Drink Strawberry 180ml'), $query);
            $this->assertFalse($names->contains('Yakult Original Cultured Milk 80ml'), $query);
            $this->assertFalse($names->contains('Chuckie Nestle My Chocolatey Buddy Milk Drink 110mL'), $query);
            $this->assertFalse($names->contains('Anlene Gold 5X Plain Milk Powder 300g'), $query);
            $this->assertFalse($names->contains('Ensure Gold Vanilla 1.6kg'), $query);
        }
    }

    public function test_condensed_nga_gatas_keeps_condensada_not_evaporada(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Carnation Condensada 388g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Angel Kremdensada Sweetened Thick Milk 410mL',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Crema-Asada 2-in-1 Sweetened Thick Creamer 300g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Evaporada Evaporated Creamer 370g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alpine Evaporated Full Cream Milk 360 mL',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('condensed nga gatas');
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Carnation Condensada 388g'));
        $this->assertTrue($names->contains('Angel Kremdensada Sweetened Thick Milk 410mL'));
        $this->assertTrue($names->contains('Alaska Crema-Asada 2-in-1 Sweetened Thick Creamer 300g'));
        $this->assertFalse($names->contains('Alaska Evaporada Evaporated Creamer 370g'));
        $this->assertFalse($names->contains('Alpine Evaporated Full Cream Milk 360 mL'));
    }

    public function test_evap_nga_gatas_keeps_evaporada_not_condensada(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Evaporada Evaporated Creamer 370g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alpine Evaporated Full Cream Milk 360 mL',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Carnation Condensada 388g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Angel Kremdensada Sweetened Thick Milk 410mL',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Crema-Asada 2-in-1 Sweetened Thick Creamer 300g',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('evap nga gatas');
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Alaska Evaporada Evaporated Creamer 370g'));
        $this->assertTrue($names->contains('Alpine Evaporated Full Cream Milk 360 mL'));
        $this->assertFalse($names->contains('Carnation Condensada 388g'));
        $this->assertFalse($names->contains('Angel Kremdensada Sweetened Thick Milk 410mL'));
        $this->assertFalse($names->contains('Alaska Crema-Asada 2-in-1 Sweetened Thick Creamer 300g'));
    }

    public function test_pangluto_ranks_cook_milk_first_not_coconut(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Evaporada Evaporated Creamer 370g',
            'description' => 'Evaporated filled milk creamer.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Nestle All Purpose Creamer 300ml',
            'description' => 'All purpose creamer for cooking and baking.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'UFC Gata Coconut Cream 400ml',
            'description' => 'Coconut cream for ginataan.',
            'is_available' => true,
            'stock' => 10,
        ]);

        $rewrite = strtolower($this->service->dialectCatalogQuery('gatas nga pangluto'));
        $this->assertStringContainsString('cook', $rewrite);
        $this->assertStringContainsString('cooking', $rewrite);
        $this->assertStringNotContainsString('coconut', $rewrite);

        $nlp = $extractor->extract('gatas nga pangluto');
        $names = $this->service->searchFromNlp($nlp, null, 50)->pluck('name')->values();
        $this->assertTrue($names->contains('Nestle All Purpose Creamer 300ml'));
        $this->assertTrue($names->contains('Alaska Evaporada Evaporated Creamer 370g'));
        $this->assertFalse($names->contains('UFC Gata Coconut Cream 400ml'));
        $this->assertSame('Nestle All Purpose Creamer 300ml', $names->first());
    }

    public function test_pangluto_keeps_milk_with_cook_in_description(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $dairy = Category::factory()->create(['name' => 'Dairy']);
        $noodles = Category::factory()->create(['name' => 'Noodles']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $dairy->id,
            'name' => 'Magnolia Fresh Milk 1L',
            'description' => 'UHT full cream milk for cooking and baking.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $dairy->id,
            'name' => 'Alaska Evaporada Evaporated Creamer 370g',
            'description' => 'Evaporated filled milk creamer.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $noodles->id,
            'name' => 'Lucky Me Pancit Canton 80g',
            'description' => 'Instant noodles for cooking with soy sauce.',
            'is_available' => true,
            'stock' => 10,
        ]);

        $rewrite = strtolower($this->service->dialectCatalogQuery('pangluto'));
        $this->assertStringContainsString('cook', $rewrite);
        $this->assertStringContainsString('cooking', $rewrite);

        foreach (['pangluto', 'gatas nga pangluto'] as $query) {
            $nlp = $extractor->extract($query);
            $names = $this->service->searchFromNlp($nlp, null, 50)->pluck('name')->values();
            $this->assertTrue($names->contains('Magnolia Fresh Milk 1L'), $query);
            $this->assertTrue($names->contains('Alaska Evaporada Evaporated Creamer 370g'), $query);
            $this->assertFalse($names->contains('Lucky Me Pancit Canton 80g'), $query);
            $this->assertSame('Magnolia Fresh Milk 1L', $names->first(), $query);
        }
    }

    public function test_panggata_keeps_coconut_gata_not_dairy_creamer(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Evaporada Evaporated Creamer 370g',
            'description' => 'Evaporated filled milk creamer.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Nestle All Purpose Creamer 300ml',
            'description' => 'All purpose creamer for cooking and baking.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'UFC Gata Coconut Cream 400ml',
            'description' => 'Coconut cream for ginataan.',
            'is_available' => true,
            'stock' => 10,
        ]);

        $rewrite = strtolower($this->service->dialectCatalogQuery('gatas panggata'));
        $this->assertStringContainsString('coconut', $rewrite);
        $this->assertStringContainsString('gata', $rewrite);
        $this->assertStringNotContainsString('cook', $rewrite);

        foreach (['gatas panggata', 'panggata', 'gata'] as $query) {
            $nlp = $extractor->extract($query);
            $this->assertSame('coconut milk', $nlp['product'], $query);
            $names = $this->service->searchFromNlp($nlp, null, 50)->pluck('name')->values();
            $this->assertTrue($names->contains('UFC Gata Coconut Cream 400ml'), $query);
            $this->assertFalse($names->contains('Nestle All Purpose Creamer 300ml'), $query);
            $this->assertFalse($names->contains('Alaska Evaporada Evaporated Creamer 370g'), $query);
            $this->assertSame('UFC Gata Coconut Cream 400ml', $names->first(), $query);
        }
    }

    public function test_gatas_naay_probiotic_keeps_cultured_drinks_not_condensada(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Yakult Original Cultured Milk 80ml',
            'description' => 'Sweet fermented milk drink containing live Lactobacillus casei Shirota.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Dutch Mill Delight Probiotic Drink 400ml',
            'description' => 'Sweet and tangy cultured milk beverage.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Dutch Mill UHT Yoghurt Drink Strawberry 180ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Condensada Sweetened Condensed Creamer 300g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Evaporada Evaporated Creamer 370g',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('gatas nga naay probiotic');
        $this->assertContains('cooking_milk', $nlp['exclusions']);
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Yakult Original Cultured Milk 80ml'));
        $this->assertTrue($names->contains('Dutch Mill Delight Probiotic Drink 400ml'));
        $this->assertTrue($names->contains('Dutch Mill UHT Yoghurt Drink Strawberry 180ml'));
        $this->assertFalse($names->contains('Alaska Condensada Sweetened Condensed Creamer 300g'));
        $this->assertFalse($names->contains('Alaska Evaporada Evaporated Creamer 370g'));
    }

    public function test_gatas_nga_mainom_keeps_drinking_milk_not_creamer_formula_or_bath(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $dairy = Category::factory()->create(['name' => 'Dairy']);
        $care = Category::factory()->create(['name' => 'Baby Care']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $dairy->id,
            'name' => 'Nestle Low Fat Milk 1000 ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $dairy->id,
            'name' => 'Selecta Banana Flavored Milk Drink 200 ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $dairy->id,
            'name' => 'Nescafe Creamy White Twin Pack 58g',
            'description' => 'Coffee creamer.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $dairy->id,
            'name' => 'S-26 Gold One Infant Formula Milk Powder 400g',
            'description' => 'Infant formula for 0-6 months.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $care->id,
            'name' => "Johnson's Baby Bath Milk + Rice Pump 1000ml",
            'description' => 'Baby bath milk.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $care->id,
            'name' => "Pond's White Beauty Instabright Tone Up Milk Cream",
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('gatas nga mainom');
        $this->assertSame('milk', $nlp['product']);
        $this->assertContains('infant_formula', $nlp['exclusions']);
        $this->assertContains('cooking_milk', $nlp['exclusions']);
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Nestle Low Fat Milk 1000 ml'));
        $this->assertTrue($names->contains('Selecta Banana Flavored Milk Drink 200 ml'));
        $this->assertFalse($names->contains('Nescafe Creamy White Twin Pack 58g'));
        $this->assertFalse($names->contains('S-26 Gold One Infant Formula Milk Powder 400g'));
        $this->assertFalse($names->contains("Johnson's Baby Bath Milk + Rice Pump 1000ml"));
        $this->assertFalse($names->contains("Pond's White Beauty Instabright Tone Up Milk Cream"));
    }

    public function test_infant_queries_prefer_zero_to_six_month_milk_not_anlene(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'NAN Optipro Infant Milk 800g',
            'description' => 'Infant formula for 0-6 months.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Anlene Gold 5X 600g',
            'description' => 'Adult nutrition milk powder.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Milk 1L',
            'description' => 'Pasteurized fresh milk.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Lactum 3+ Growing-Up Milk 1.2kg',
            'description' => 'Growing-up milk for 3 years old.',
            'is_available' => true,
            'stock' => 10,
        ]);

        foreach (['gatas para sa infant', 'gatas para sa bay'] as $query) {
            $nlp = $extractor->extract($query);
            $this->assertNotContains('infant_formula', $nlp['exclusions'], $query);
            $names = $this->service->searchFromNlp($nlp)->pluck('name');
            $this->assertTrue($names->contains('NAN Optipro Infant Milk 800g'), $query);
            $this->assertFalse($names->contains('Anlene Gold 5X 600g'), $query);
            $this->assertFalse($names->contains('Fresh Milk 1L'), $query);
            $this->assertFalse($names->contains('Lactum 3+ Growing-Up Milk 1.2kg'), $query);
            $this->assertSame('NAN Optipro Infant Milk 800g', $names->first(), $query);
        }
    }

    public function test_bata_queries_include_infant_and_kids_milk_not_anlene(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'NAN Optipro Infant Milk 800g',
            'description' => 'Infant formula for 0-6 months.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'BEAR BRAND JR Milk Supplement For 1-3 Years Old 1.15kg',
            'description' => 'Milk supplement for 1-3 years old.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Anlene Gold 5X 600g',
            'description' => 'Adult nutrition milk powder.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Milk 1L',
            'description' => 'Pasteurized fresh milk.',
            'is_available' => true,
            'stock' => 10,
        ]);

        foreach (['gatas para sa bata', 'gatas nga pangbata'] as $query) {
            $nlp = $extractor->extract($query);
            $names = $this->service->searchFromNlp($nlp)->pluck('name');
            $this->assertTrue($names->contains('NAN Optipro Infant Milk 800g'), $query);
            $this->assertTrue($names->contains('BEAR BRAND JR Milk Supplement For 1-3 Years Old 1.15kg'), $query);
            $this->assertFalse($names->contains('Anlene Gold 5X 600g'), $query);
            $this->assertFalse($names->contains('Fresh Milk 1L'), $query);
        }
    }

    public function test_pangbata_drinking_milk_excludes_baby_bath_and_wipes(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $care = Category::factory()->create(['name' => 'Baby Care']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'NAN OPTIPRO ONE 400g for 0-6 Months Infant Milk',
            'description' => 'Infant formula for 0-6 months.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $care->id,
            'name' => "Johnson's Baby Bath Milk + Rice Pump 1000ml",
            'description' => "Johnson's Milk + Rice Bath. Perfect for babies. It has the no more tear formula.",
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $care->id,
            'name' => 'Baby First Wipes Unscented 60s X 2',
            'description' => 'Gentle clean for the whole family especially for babies. Sensitive premature, infant, and newborn skin.',
            'is_available' => true,
            'stock' => 10,
        ]);

        foreach (['gatas nga pangbata', 'gatas mainom pangbata'] as $query) {
            $nlp = $extractor->extract($query);
            $names = $this->service->searchFromNlp($nlp)->pluck('name');
            $this->assertTrue($names->contains('NAN OPTIPRO ONE 400g for 0-6 Months Infant Milk'), $query);
            $this->assertFalse($names->contains("Johnson's Baby Bath Milk + Rice Pump 1000ml"), $query);
            $this->assertFalse($names->contains('Baby First Wipes Unscented 60s X 2'), $query);
        }
    }

    public function test_elderly_queries_exclude_bear_brand_junior_and_rank_anlene(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Anlene Gold 5X Plain Milk Powder 300g',
            'description' => 'Adult nutrition milk powder.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'BEAR BRAND JR Milk Supplement For 1-3 Years Old 1.15kg',
            'description' => 'Milk supplement for 1-3 years old.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Milk 1L',
            'description' => 'Pasteurized fresh milk.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Ensure Gold Vanilla 1.6kg',
            'description' => 'Adult nutritional powder milk drink with protein, vitamins and minerals to help support the nutritional needs of older adults.',
            'is_available' => true,
            'stock' => 10,
        ]);

        foreach (['gatas para sa tigulang', 'gatas para senior', 'gatas pangsenior'] as $query) {
            $nlp = $extractor->extract($query);
            $names = $this->service->searchFromNlp($nlp)->pluck('name');

            $this->assertTrue($names->contains('Anlene Gold 5X Plain Milk Powder 300g'), $query);
            $this->assertTrue($names->contains('Ensure Gold Vanilla 1.6kg'), $query);
            $this->assertFalse($names->contains('BEAR BRAND JR Milk Supplement For 1-3 Years Old 1.15kg'), $query);
            $this->assertContains($names->first(), [
                'Anlene Gold 5X Plain Milk Powder 300g',
                'Ensure Gold Vanilla 1.6kg',
            ], $query);
        }
    }

    public function test_bare_gatas_keeps_all_milk_products(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Milk 1L',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Condensada Sweetened Condensed Creamer 370g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alaska Crema-Asada 2-in-1 Sweetened Thick Creamer 300g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Angel Kremdensada Sweetened Thick Creamer 410mL',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Alpine Evaporated Full Cream Milk 360 mL',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'NAN Optipro Infant Milk 800g',
            'description' => 'Infant formula for 0-6 months.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Anlene Gold 5X Milk Powder 600g',
            'description' => 'Adult nutrition milk powder.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Ensure Gold Vanilla 1.6kg',
            'description' => 'Adult nutritional powder milk drink with protein, vitamins and minerals to help support the nutritional needs of older adults.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Yakult Original Cultured Milk 80ml',
            'description' => 'Sweet fermented milk drink containing live Lactobacillus casei Shirota bacteria.',
            'is_available' => true,
            'stock' => 10,
        ]);

        foreach (['gatas', 'tanan gatas', 'all milk'] as $query) {
            $nlp = $extractor->extract($query);
            $this->assertNotContains('cooking_milk', $nlp['exclusions'], $query);
            $this->assertNotContains('infant_formula', $nlp['exclusions'], $query);
            $this->assertNotContains('adult_milk', $nlp['exclusions'], $query);
            $names = $this->service->searchFromNlp($nlp, null, 50)->pluck('name');
            $this->assertTrue($names->contains('Fresh Milk 1L'), $query);
            $this->assertTrue($names->contains('Alaska Condensada Sweetened Condensed Creamer 370g'), $query);
            $this->assertTrue($names->contains('Alaska Crema-Asada 2-in-1 Sweetened Thick Creamer 300g'), $query);
            $this->assertTrue($names->contains('Angel Kremdensada Sweetened Thick Creamer 410mL'), $query);
            $this->assertTrue($names->contains('Alpine Evaporated Full Cream Milk 360 mL'), $query);
            $this->assertTrue($names->contains('NAN Optipro Infant Milk 800g'), $query);
            $this->assertTrue($names->contains('Anlene Gold 5X Milk Powder 600g'), $query);
            $this->assertTrue($names->contains('Ensure Gold Vanilla 1.6kg'), $query);
            $this->assertTrue($names->contains('Yakult Original Cultured Milk 80ml'), $query);
        }

        $cooking = $extractor->extract('condensada');
        $this->assertSame('condensed milk', $cooking['product']);
        $this->assertNotContains('cooking_milk', $cooking['exclusions']);
        $cookingNames = $this->service->searchFromNlp($cooking)->pluck('name');
        $this->assertTrue($cookingNames->contains('Alaska Condensada Sweetened Condensed Creamer 370g'));

        $english = $extractor->extract('milk');
        $this->assertNotContains('cooking_milk', $english['exclusions']);
        $englishNames = $this->service->searchFromNlp($english)->pluck('name');
        $this->assertTrue($englishNames->contains('Fresh Milk 1L'));
        $this->assertTrue($englishNames->contains('Alaska Condensada Sweetened Condensed Creamer 370g'));

        $specific = $extractor->extract('gatas nga fresh');
        $this->assertContains('cooking_milk', $specific['exclusions']);
        $specificNames = $this->service->searchFromNlp($specific)->pluck('name');
        $this->assertTrue($specificNames->contains('Fresh Milk 1L'));
        $this->assertFalse($specificNames->contains('Alpine Evaporated Full Cream Milk 360 mL'));
        $this->assertFalse($specificNames->contains('Alaska Condensada Sweetened Condensed Creamer 370g'));
    }

    public function test_sud_an_finds_viand_not_detergent(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $food = Category::factory()->create(['name' => 'Canned']);
        $household = Category::factory()->create(['name' => 'Household']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $food->id,
            'name' => 'Mega Sardines Tomato Sauce 155g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $household->id,
            'name' => 'Ariel Detergent Powder Sunrise Fresh 66g',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('sud-an');
        $this->assertSame('ulam', $nlp['product']);
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Mega Sardines Tomato Sauce 155g'));
        $this->assertFalse($names->contains('Ariel Detergent Powder Sunrise Fresh 66g'));
    }

    public function test_mainom_finds_drinks_not_detergent(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $drinks = Category::factory()->create(['name' => 'Beverages']);
        $household = Category::factory()->create(['name' => 'Household']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $drinks->id,
            'name' => 'Coke 1L',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $household->id,
            'name' => 'Ariel Powder Detergent 1kg',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('mainom');
        $this->assertSame('drinks', $nlp['product']);
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Coke 1L'));
        $this->assertFalse($names->contains('Ariel Powder Detergent 1kg'));
    }

    public function test_pahumot_sa_sanina_finds_fabcon_not_sinigang(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $household = Category::factory()->create(['name' => 'Household']);
        $food = Category::factory()->create(['name' => 'Seasoning']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $household->id,
            'name' => 'Downy Fabcon Fabric Conditioner Passion 20ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $food->id,
            'name' => 'Knorr Sinigang Sa Gabi Mix',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('pahumot sa sanina');
        $this->assertSame('fabric conditioner', $nlp['product']);
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Downy Fabcon Fabric Conditioner Passion 20ml'));
        $this->assertFalse($names->contains('Knorr Sinigang Sa Gabi Mix'));
    }

    public function test_para_sa_buhok_finds_shampoo_not_fabcon(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $personal = Category::factory()->create(['name' => 'Personal Care']);
        $household = Category::factory()->create(['name' => 'Household']);
        $food = Category::factory()->create(['name' => 'Seasoning']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $personal->id,
            'name' => 'Cream Silk Hair Shampoo 12ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $household->id,
            'name' => 'Downy Fabcon Fabric Conditioner Passion 20ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $food->id,
            'name' => 'Knorr Sinigang Sa Gabi Mix',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('mga para sa buhok');
        $this->assertSame('shampoo', $nlp['product']);
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Cream Silk Hair Shampoo 12ml'));
        $this->assertFalse($names->contains('Downy Fabcon Fabric Conditioner Passion 20ml'));
        $this->assertFalse($names->contains('Knorr Sinigang Sa Gabi Mix'));
    }

    public function test_pamahaw_finds_breakfast_not_cleansing_gel(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $food = Category::factory()->create(['name' => 'Bakery']);
        $personal = Category::factory()->create(['name' => 'Personal Care']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $food->id,
            'name' => 'Pandesal 10 pcs',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $personal->id,
            'name' => 'Mustela Gentle Cleansing Gel 750 Ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('mga para pamahaw');
        $this->assertSame('breakfast', $nlp['product']);
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Pandesal 10 pcs'));
        $this->assertFalse($names->contains('Mustela Gentle Cleansing Gel 750 Ml'));
    }

    public function test_gatas_pang_snacks_keeps_cookies_not_drinking_milk(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $snacks = Category::factory()->create(['name' => 'Cookies, Snacks & Candy']);
        $dairy = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $snacks->id,
            'name' => 'Kokola Kukis Kelapa Coconut Cookies 60g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $dairy->id,
            'name' => 'Fresh Milk 1L',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $dairy->id,
            'name' => 'Lactum Milk for 6 to 12 Months Plain 1.15kg Box',
            'description' => 'Infant formula.',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('gatas pang snacks');
        $this->assertSame('snacks', $nlp['product']);
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Kokola Kukis Kelapa Coconut Cookies 60g'));
        $this->assertFalse($names->contains('Fresh Milk 1L'));
        $this->assertFalse($names->contains('Lactum Milk for 6 to 12 Months Plain 1.15kg Box'));
    }

    public function test_tsokolate_keeps_chocolate_not_chili_or_cinnamon(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $snacks = Category::factory()->create(['name' => 'Snacks']);
        $spices = Category::factory()->create(['name' => 'Spices']);
        $canned = Category::factory()->create(['name' => 'Canned']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $snacks->id,
            'name' => 'Stickman Wafer Stick Chocolate Jumbo 130s',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $snacks->id,
            'name' => 'Birch Tree Fortified Powdered Chocolate 33g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $spices->id,
            'name' => 'McCormick Ground Cinnamon 30g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $canned->id,
            'name' => 'Fresca Tuna Flakes Hot And Spicy 175g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $snacks->id,
            'name' => 'Lucky Me Instant Hot Chili Pancit Canton 80g',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('tsokolate');
        $this->assertSame('chocolate', $nlp['product']);
        $names = $this->service->searchFromNlp($nlp, null, 50)->pluck('name');
        $this->assertTrue($names->contains('Stickman Wafer Stick Chocolate Jumbo 130s'));
        $this->assertTrue($names->contains('Birch Tree Fortified Powdered Chocolate 33g'));
        $this->assertFalse($names->contains('McCormick Ground Cinnamon 30g'));
        $this->assertFalse($names->contains('Fresca Tuna Flakes Hot And Spicy 175g'));
        $this->assertFalse($names->contains('Lucky Me Instant Hot Chili Pancit Canton 80g'));
    }

    public function test_isda_nga_de_lata_finds_canned_fish_not_fruit(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $canned = Category::factory()->create(['name' => 'Canned Goods']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $canned->id,
            'name' => 'Mega Sardines in Tomato Sauce 155g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $canned->id,
            'name' => 'Century Tuna Flakes in Oil 155g',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $canned->id,
            'name' => 'Del Monte Fiesta Fruit Cocktail In Extra Light',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $canned->id,
            'name' => 'Lucky 7 Carne Norte',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('ISDA nga de lata');
        $this->assertSame('canned fish', $nlp['product']);
        $names = $this->service->searchFromNlp($nlp)->pluck('name');
        $this->assertTrue($names->contains('Mega Sardines in Tomato Sauce 155g'));
        $this->assertTrue($names->contains('Century Tuna Flakes in Oil 155g'));
        $this->assertFalse($names->contains('Del Monte Fiesta Fruit Cocktail In Extra Light'));
        $this->assertFalse($names->contains('Lucky 7 Carne Norte'));
    }

    public function test_pang_salad_finds_mayo_not_ice_cream(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $condiments = Category::factory()->create(['name' => 'Condiments']);
        $frozen = Category::factory()->create(['name' => 'Frozen']);
        $personal = Category::factory()->create(['name' => 'Personal Care']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $condiments->id,
            'name' => "Lady's Choice Real Mayonnaise 220ml",
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $frozen->id,
            'name' => 'Selecta 3in1 Choco Keso Ube',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $personal->id,
            'name' => 'Mustela Gentle Cleansing Gel 750 Ml',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $condiments->id,
            'name' => 'Del Monte Fiesta Fruit Cocktail In Extra Light',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $condiments->id,
            'name' => 'Alaska Crema-Asada 2-in-1 Sweetened Thick Creamer',
            'description' => 'Thick, sweetened creamy milk product. Commonly used for fruit salad, mango float, pastillas, and other Filipino desserts.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $condiments->id,
            'name' => 'Carnation Condensada 388 g',
            'description' => 'Sweetened condensed milk creamer for desserts, baking and drinks.',
            'is_available' => true,
            'stock' => 10,
        ]);

        foreach (['Mga para pang-salad', 'mga pangsalad'] as $query) {
            $nlp = $extractor->extract($query);
            $this->assertSame('salad', $nlp['product'], $query);
            $names = $this->service->searchFromNlp($nlp)->pluck('name');
            $this->assertTrue($names->contains("Lady's Choice Real Mayonnaise 220ml"), $query);
            $this->assertTrue($names->contains('Del Monte Fiesta Fruit Cocktail In Extra Light'), $query);
            $this->assertTrue($names->contains('Alaska Crema-Asada 2-in-1 Sweetened Thick Creamer'), $query);
            $this->assertTrue($names->contains('Carnation Condensada 388 g'), $query);
            $this->assertFalse($names->contains('Selecta 3in1 Choco Keso Ube'), $query);
            $this->assertFalse($names->contains('Mustela Gentle Cleansing Gel 750 Ml'), $query);
        }
    }

    public function test_sabon_panglaba_finds_laundry_detergent_brands(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Household']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Ariel Powder Detergent 1kg',
            'description' => 'Laundry detergent.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Tide Bar 480g',
            'description' => 'Laundry soap.',
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Safeguard Bath Soap',
            'description' => 'Personal care soap.',
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('sabon panglaba');
        $this->assertSame('laundry detergent', $nlp['product']);
        $results = $this->service->searchFromNlp($nlp);
        $names = $results->pluck('name');

        $this->assertTrue($names->contains('Ariel Powder Detergent 1kg'));
        $this->assertTrue($names->contains('Tide Bar 480g'));
        $this->assertFalse($names->contains('Safeguard Bath Soap'));
    }

    public function test_synonym_bugas_finds_rice_product(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Grains']);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Premium Rice 5kg',
            'is_available' => true,
            'stock' => 5,
        ]);

        $results = $this->service->searchFromNlp([
            'original_text' => 'palit kog bugas',
            'keywords' => $this->service->expandTerms(['bugas']),
            'units' => [],
        ]);

        $this->assertTrue($results->contains(fn (Product $p) => str_contains(strtolower($p->name), 'rice')));
    }

    public function test_normalize_units_from_litro(): void
    {
        $units = $this->service->normalizeUnitsFromText('1 litro nga coke');

        $this->assertContains('1L', $units);
    }

    public function test_cheap_tuyo_ranks_lower_priced_soy_sauce(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Condiments']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Premium Soy Sauce 1L',
            'description' => 'Large bottle of soy sauce',
            'price' => 95,
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Silver Swan Soy Sauce 200ml',
            'description' => 'Everyday soy sauce',
            'price' => 25,
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Lucky Me Pancit Canton',
            'description' => 'Seasoning packet includes soy sauce and oil.',
            'price' => 15,
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('barato na tuyo');
        $results = $this->service->searchFromNlp($nlp);

        $this->assertNotEmpty($results);
        $this->assertSame('Silver Swan Soy Sauce 200ml', $results->first()->name);
        $this->assertFalse($results->pluck('name')->contains('Lucky Me Pancit Canton'));
    }

    public function test_non_cow_milk_excludes_fresh_cow_milk(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Milk 1L',
            'description' => 'Full-cream fresh cow milk.',
            'price' => 98,
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Nestle Vegan Oat Milk 300ml',
            'description' => 'Plant-based vegan oat milk. Dairy-free, not from cow.',
            'price' => 89,
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('gatas na dili sa baka');
        $results = $this->service->searchFromNlp($nlp);
        $names = $results->pluck('name');

        $this->assertTrue($names->contains('Nestle Vegan Oat Milk 300ml'));
        $this->assertFalse($names->contains('Fresh Milk 1L'));
    }

    public function test_vegan_nestle_300ml_ranks_plant_milk_over_cow_milk(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $extractor = app(QueryAttributeExtractor::class);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Milk 1L',
            'description' => 'Full-cream fresh cow milk.',
            'price' => 98,
            'is_available' => true,
            'stock' => 10,
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Nestle Vegan Oat Milk 300ml',
            'description' => 'Plant-based vegan oat milk. Dairy-free, not from cow.',
            'price' => 89,
            'is_available' => true,
            'stock' => 10,
        ]);

        $nlp = $extractor->extract('gatas na vegan 300 ml nestle');
        $results = $this->service->searchFromNlp($nlp);

        $this->assertNotEmpty($results);
        $this->assertSame('Nestle Vegan Oat Milk 300ml', $results->first()->name);
        $this->assertFalse($results->pluck('name')->contains('Fresh Milk 1L'));
    }
}
