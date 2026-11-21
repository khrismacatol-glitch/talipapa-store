<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreProductBarcodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CategorySeeder::class);
    }

    public function test_approved_store_can_create_product_with_barcode(): void
    {
        $user = User::factory()->create(['role' => UserRole::StoreOwner]);
        $store = StoreProfile::factory()->approved()->create(['user_id' => $user->id]);
        $category = Category::factory()->create();

        $this->actingAs($user)
            ->post(route('store.products.store'), [
                'category_id' => $category->id,
                'name' => 'Canned Tuna',
                'barcode' => '4800123456789',
                'price' => 45,
                'stock' => 20,
                'is_available' => true,
            ])
            ->assertRedirect(route('store.products'));

        $this->assertDatabaseHas('products', [
            'store_profile_id' => $store->id,
            'name' => 'Canned Tuna',
            'barcode' => '4800123456789',
        ]);
    }

    public function test_barcode_must_be_unique_within_store(): void
    {
        $user = User::factory()->create(['role' => UserRole::StoreOwner]);
        $store = StoreProfile::factory()->approved()->create(['user_id' => $user->id]);
        $category = Category::factory()->create();
        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'barcode' => '4800123456789',
        ]);

        $this->actingAs($user)
            ->post(route('store.products.store'), [
                'category_id' => $category->id,
                'name' => 'Duplicate Barcode Product',
                'barcode' => '4800123456789',
                'price' => 30,
                'stock' => 5,
                'is_available' => true,
            ])
            ->assertSessionHasErrors('barcode');
    }

    public function test_store_can_search_products_by_name_or_description(): void
    {
        $user = User::factory()->create(['role' => UserRole::StoreOwner]);
        $store = StoreProfile::factory()->approved()->create(['user_id' => $user->id]);
        $category = Category::factory()->create();

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Lucky 7 Carne Norte 260g',
            'description' => 'Corned beef in a can.',
        ]);

        Product::factory()->create([
            'store_profile_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'John West Wild Pacific Red Salmon 213g',
            'description' => 'Canned salmon in brine.',
        ]);

        $this->actingAs($user)
            ->get(route('store.products', ['q' => 'carne norte']))
            ->assertOk()
            ->assertSee('Lucky 7 Carne Norte 260g')
            ->assertDontSee('John West Wild Pacific Red Salmon 213g');

        $this->actingAs($user)
            ->get(route('store.products', ['q' => 'canned salmon']))
            ->assertOk()
            ->assertSee('John West Wild Pacific Red Salmon 213g')
            ->assertDontSee('Lucky 7 Carne Norte 260g');
    }
}
