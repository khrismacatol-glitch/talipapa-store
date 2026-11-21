<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use Database\Seeders\PsgcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyNowCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PsgcSeeder::class);
    }

    public function test_product_cards_show_add_to_cart_and_buy(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create();
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Fresh Milk 1L',
            'is_available' => true,
            'stock' => 10,
        ]);

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Add to cart')
            ->assertSee('Buy');
    }

    public function test_buy_opens_cart(): void
    {
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Ariel Powder Detergent 1kg',
            'is_available' => true,
            'stock' => 10,
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Customer]))
            ->post(route('cart.store'), [
                'product_id' => $product->id,
                'quantity' => 1,
                'buy_now' => 1,
            ])
            ->assertRedirect(route('cart.index'));

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('Your Cart')
            ->assertSee('Ariel Powder Detergent 1kg');
    }

    public function test_checkout_without_step_is_the_one_page_checkout(): void
    {
        $user = User::factory()->create(['role' => UserRole::Customer]);
        $store = StoreProfile::factory()->approved()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'is_available' => true,
            'stock' => 10,
        ]);

        $this->actingAs($user)
            ->post(route('cart.store'), [
                'product_id' => $product->id,
                'quantity' => 1,
            ]);

        $this->actingAs($user)
            ->get(route('customer.checkout.show'))
            ->assertOk()
            ->assertDontSee('Step 1 of 5')
            ->assertSee('Cart')
            ->assertSee('Info')
            ->assertSee('Address')
            ->assertSee('Payment')
            ->assertSee('Confirm');
    }
}
