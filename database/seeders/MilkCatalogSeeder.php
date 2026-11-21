<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class MilkCatalogSeeder extends Seeder
{
    /**
     * Specs for milk products that DemoDataSeeder already creates.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DEMO_SPECS = [
        'Fresh Milk 1L' => ['is_milk' => true, 'source' => 'cow', 'fat' => 'full', 'sugar' => 'regular', 'organic' => false, 'volume_ml' => 1000, 'brand' => null, 'form' => 'liquid'],
        'Nestle Vegan Oat Milk 300ml' => ['is_milk' => true, 'source' => 'plant', 'fat' => null, 'sugar' => 'regular', 'organic' => false, 'volume_ml' => 300, 'brand' => 'nestle', 'form' => 'liquid'],
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return require database_path('data/milk_catalog.php');
    }

    public function run(): void
    {
        $categories = Category::query()->get()->keyBy('slug');
        $stores = User::query()
            ->with('storeProfile')
            ->whereIn('email', array_unique(array_column(self::catalog(), 'store')))
            ->get()
            ->keyBy('email');

        foreach (self::catalog() as $row) {
            $store = $stores[$row['store']]->storeProfile ?? null;
            $category = $categories[$row['category_slug']] ?? null;

            if (! $store || ! $category) {
                continue;
            }

            Product::updateOrCreate(
                [
                    'store_profile_id' => $store->id,
                    'name' => $row['name'],
                ],
                [
                    'category_id' => $category->id,
                    'description' => $row['description'],
                    'specs' => $row['specs'],
                    'price' => $row['price'],
                    'stock' => 40,
                    'image_path' => 'img/placeholders/product.svg',
                    'is_available' => true,
                ]
            );
        }

        foreach (self::DEMO_SPECS as $name => $specs) {
            Product::query()->where('name', $name)->update(['specs' => json_encode($specs)]);
        }
    }
}
