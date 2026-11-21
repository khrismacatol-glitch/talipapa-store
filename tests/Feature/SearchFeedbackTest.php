<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SearchEvent;
use App\Models\SearchSession;
use App\Models\StoreProfile;
use Database\Seeders\PsgcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Product $milk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PsgcSeeder::class);
        config(['search-feedback.enabled' => true, 'semantic-search.enabled' => false]);

        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create(['name' => 'Dairy']);
        $this->milk = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Magnolia Fresh Milk 1L',
            'description' => 'Fresh milk',
            'is_available' => true,
            'stock' => 10,
        ]);
    }

    public function test_product_search_records_session_and_impressions(): void
    {
        $this->get(route('products.index', ['q' => 'gatas nga fresh']))
            ->assertOk();

        $this->assertDatabaseCount('search_sessions', 1);
        $session = SearchSession::query()->first();
        $this->assertSame('gatas nga fresh', $session->query_norm);
        $this->assertDatabaseHas('search_impressions', [
            'search_session_id' => $session->id,
            'product_id' => $this->milk->id,
            'position' => 1,
        ]);
        $this->assertNotSame($this->milk->id > 0 ? 'email' : '', $session->session_key);
        $this->assertNull($session->user_hash);
    }

    public function test_click_and_cart_attach_to_search_session(): void
    {
        $this->get(route('products.index', ['q' => 'gatas nga fresh']))->assertOk();
        $token = SearchSession::query()->value('token');

        $this->post(route('search-feedback.click'), [
            'product_id' => $this->milk->id,
            'search_session' => $token,
        ])->assertNoContent();

        $this->post(route('cart.store'), [
            'product_id' => $this->milk->id,
            'quantity' => 1,
            'search_session' => $token,
        ])->assertRedirect();

        $this->assertDatabaseHas('search_events', ['product_id' => $this->milk->id, 'type' => 'click']);
        $this->assertDatabaseHas('search_events', ['product_id' => $this->milk->id, 'type' => 'cart']);
        $this->assertSame(2, SearchEvent::query()->count());
    }

    public function test_duplicate_cart_within_30_seconds_is_ignored(): void
    {
        $this->get(route('products.index', ['q' => 'gatas nga fresh']))->assertOk();
        $token = SearchSession::query()->value('token');

        $this->post(route('cart.store'), [
            'product_id' => $this->milk->id,
            'search_session' => $token,
        ]);
        $this->post(route('cart.store'), [
            'product_id' => $this->milk->id,
            'search_session' => $token,
        ]);

        $this->assertSame(1, SearchEvent::query()->where('type', 'cart')->count());
    }
}
