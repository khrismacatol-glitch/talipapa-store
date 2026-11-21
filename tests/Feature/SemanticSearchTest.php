<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use App\Services\VoiceAssistant\VoiceNlpService;
use App\Services\VoiceAssistant\VoiceReplyService;
use Database\Seeders\PsgcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SemanticSearchTest extends TestCase
{
    use RefreshDatabase;

    private Product $fullCream;

    private Product $lowFat;

    private Product $organic;

    private Product $condensed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PsgcSeeder::class);

        config([
            'semantic-search.enabled' => true,
            'semantic-search.url' => 'http://ml.test',
            'semantic-search.show_scores' => false,
            'voice-assistant.enabled' => true,
            'voice-assistant.openai_api_key' => 'test-key',
        ]);

        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $make = fn (string $name, array $specs) => Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'description' => $name,
            'specs' => $specs,
            'is_available' => true,
            'stock' => 10,
        ]);

        $this->fullCream = $make('Magnolia Fresh Milk 1L', ['is_milk' => true, 'source' => 'cow', 'fat' => 'full']);
        $this->lowFat = $make('Magnolia Low Fat Milk 1L', ['is_milk' => true, 'source' => 'cow', 'fat' => 'low']);
        $this->organic = $make('Arla Organic Whole Milk 1L', ['is_milk' => true, 'source' => 'cow', 'organic' => true]);
        $this->condensed = $make('Alaska Sweetened Condensed Milk 300ml', ['is_milk' => false, 'sugar' => 'sweetened']);
    }

    /**
     * @param  array<int, Product>  $ranked
     */
    private function fakeRanker(array $ranked, bool $isMilkQuery = true): void
    {
        Http::fake([
            'ml.test/rank' => Http::response([
                'milk_query' => $isMilkQuery ? 0.95 : 0.1,
                'is_milk_query' => $isMilkQuery,
                'mode' => 'grocery_ltr',
                'intents' => ['low_fat' => 0.8, 'cow' => 0.1],
                'results' => array_map(fn (Product $p) => [
                    'id' => $p->id,
                    'score' => 1.0,
                    'p_milk' => 0.9,
                    'parts' => [
                        'labse' => 0.81,
                        'bm25' => 2.4,
                        'lambdamart' => 0.55,
                        'extra' => 0.1,
                        'total' => 1.0,
                    ],
                ], $ranked),
            ]),
        ]);
    }

    public function test_product_search_uses_ml_ranking_order(): void
    {
        $this->fakeRanker([$this->lowFat, $this->fullCream]);

        $this->get(route('products.index', ['q' => 'Gatas nga dili makataba']))
            ->assertOk()
            ->assertSee('Magnolia Low Fat Milk 1L')
            ->assertDontSee('LaBSE')
            ->assertDontSee('Alaska Sweetened Condensed Milk 300ml');

        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === 'http://ml.test/rank'
                && str_starts_with($request['query'], 'Gatas nga dili makataba')
                && str_contains(strtolower($request['query']), 'milk')
                && count($request['products']) === 4
                && collect($request['products'])->firstWhere('name', 'Magnolia Low Fat Milk 1L')['specs']['fat'] === 'low';
        });
    }

    public function test_rank_scores_are_visible_when_debug_enabled(): void
    {
        config(['semantic-search.show_scores' => true]);
        $this->fakeRanker([$this->lowFat, $this->fullCream]);

        $this->get(route('products.index', ['q' => 'Gatas nga dili makataba']))
            ->assertOk()
            ->assertSee('LaBSE')
            ->assertSee('BM25')
            ->assertSee('LTR')
            ->assertSee('Total');
    }

    public function test_search_falls_back_to_keywords_when_service_is_down(): void
    {
        Http::fake(['ml.test/*' => Http::failedConnection()]);

        $this->get(route('products.index', ['q' => 'organic milk']))
            ->assertOk()
            ->assertSee('Arla Organic Whole Milk 1L');
    }

    public function test_non_milk_query_uses_ml_ranking_order(): void
    {
        $this->fakeRanker([$this->condensed, $this->fullCream], isMilkQuery: false);

        $this->get(route('products.index', ['q' => 'condensed milk']))
            ->assertOk()
            ->assertSeeInOrder(['Alaska Sweetened Condensed Milk 300ml', 'Magnolia Fresh Milk 1L']);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://ml.test/rank'
            && str_contains(strtolower($request['query']), 'condensed milk'));
    }

    public function test_cebuano_bugas_is_ranked_with_english_catalog_term(): void
    {
        $rice = Product::factory()->create([
            'store_profile_id' => $this->fullCream->store_profile_id,
            'category_id' => $this->fullCream->category_id,
            'name' => 'Premium Rice 5kg',
            'description' => 'Well milled rice',
            'is_available' => true,
            'stock' => 10,
        ]);
        $this->fakeRanker([$rice, $this->fullCream], isMilkQuery: false);

        $this->get(route('products.index', ['q' => 'bugas']))
            ->assertOk()
            ->assertSee('Premium Rice 5kg');

        Http::assertSent(function (HttpRequest $request) {
            $q = strtolower((string) $request['query']);

            return $request->url() === 'http://ml.test/rank'
                && str_contains($q, 'bugas')
                && str_contains($q, 'rice');
        });
    }

    public function test_search_skips_ml_service_when_disabled(): void
    {
        config(['semantic-search.enabled' => false]);
        Http::fake();

        $this->get(route('products.index', ['q' => 'organic milk']))->assertOk();

        Http::assertNothingSent();
    }

    public function test_voice_add_to_cart_adds_top_ml_ranked_product(): void
    {
        $this->fakeRanker([$this->organic, $this->fullCream]);

        $this->mock(VoiceNlpService::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andReturn([
                'original_text' => 'gatas na organic, ibutang sa cart',
                'language' => 'Mixed',
                'keywords' => ['milk', 'organic'],
                'units' => [],
                'intent' => 'add_to_cart',
                'quantity' => 2,
                'checkout' => null,
                'product' => 'milk',
                'attributes' => [],
            ]);
        });
        $this->mock(VoiceReplyService::class, fn ($mock) => $mock->shouldReceive('generate')->andReturn('Added.'));

        $this->actingAs(User::factory()->create())
            ->postJson(route('voice-assistant.process'), ['message' => 'gatas na organic, ibutang sa cart'])
            ->assertOk()
            ->assertJsonPath('products.0.name', 'Arla Organic Whole Milk 1L')
            ->assertJsonPath('action.success', true);

        $this->assertDatabaseHas('cart_items', ['product_id' => $this->organic->id, 'quantity' => 2]);
    }
}
