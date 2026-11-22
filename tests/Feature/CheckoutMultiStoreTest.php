<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreProfile;
use App\Models\User;
use Database\Seeders\PsgcSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutMultiStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PsgcSeeder::class);
    }

    public function test_checkout_shows_delivery_fee_per_store(): void
    {
        [$user] = $this->mixedCartCustomer();

        $this->actingAs($user)
            ->get(route('customer.checkout.show'))
            ->assertOk()
            ->assertDontSee('All cart items must belong to the same store.')
            ->assertSee('× 2 stores')
            ->assertSee('₱100.00');
    }

    public function test_placing_order_splits_cart_and_charges_delivery_per_store(): void
    {
        [$user, $address, $first, $second] = $this->mixedCartCustomer();

        $this->actingAs($user)
            ->post(route('customer.checkout.process'), [
                'step' => 0,
                'name' => $user->name,
                'phone' => $user->phone ?? '09171234567',
                'address_id' => $address->id,
                'payment_method' => PaymentMethod::Cod->value,
                'confirm' => '1',
            ])
            ->assertRedirect(route('customer.orders.index'))
            ->assertSessionHas('success');

        $orders = Order::query()->where('customer_id', $user->id)->get();
        $this->assertCount(2, $orders);
        $this->assertEqualsCanonicalizing(
            [$first->store_profile_id, $second->store_profile_id],
            $orders->pluck('store_profile_id')->all(),
        );
        foreach ($orders as $order) {
            $this->assertEquals(50, (float) $order->delivery_fee);
            $this->assertEquals(1, $order->items()->count());
        }
        $this->assertEquals(100.0, (float) $orders->sum('delivery_fee'));
    }

    /**
     * @return array{0: User, 1: Address, 2: Product, 3: Product}
     */
    private function mixedCartCustomer(): array
    {
        $user = User::factory()->create([
            'role' => UserRole::Customer,
            'phone' => '09171234567',
        ]);
        $address = Address::query()->create([
            'user_id' => $user->id,
            'addressable_type' => null,
            'addressable_id' => null,
            'label' => 'Home',
            'region_code' => '130000000',
            'province_code' => '133900000',
            'city_code' => '133901000',
            'barangay_code' => '133901001',
            'street_address' => '123 Test Street',
            'is_default' => true,
        ]);
        $category = Category::factory()->create();
        $first = Product::factory()->create([
            'store_profile_id' => StoreProfile::factory()->approved()->create()->id,
            'category_id' => $category->id,
            'name' => 'Fresh Milk 1L',
            'price' => 98,
            'is_available' => true,
            'stock' => 10,
        ]);
        $second = Product::factory()->create([
            'store_profile_id' => StoreProfile::factory()->approved()->create()->id,
            'category_id' => $category->id,
            'name' => 'NAN OPTIPRO ONE 400g',
            'price' => 850,
            'is_available' => true,
            'stock' => 10,
        ]);

        $this->actingAs($user)->post(route('cart.store'), ['product_id' => $first->id, 'quantity' => 1]);
        $this->actingAs($user)->post(route('cart.store'), ['product_id' => $second->id, 'quantity' => 1]);

        return [$user, $address, $first, $second];
    }
}
