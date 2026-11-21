<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\StoreProfile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$path = $argv[1] ?? (getenv('USERPROFILE').DIRECTORY_SEPARATOR.'Downloads'.DIRECTORY_SEPARATOR.'products.sql');
if (! is_file($path)) {
    $path = base_path('products.sql');
}
if (! is_file($path)) {
    fwrite(STDERR, "SQL file not found: {$path}\n");
    exit(1);
}

$store = StoreProfile::query()->where('store_name', 'QuickStop Pantry')->first();
if (! $store) {
    fwrite(STDERR, "QuickStop Pantry store not found.\n");
    exit(1);
}

$categories = Category::query()->get()->keyBy(fn (Category $c) => Str::lower($c->name));
$fallback = Category::query()->where('slug', 'paper-cleaning-home')->first()
    ?? Category::query()->where('slug', 'buy-it-again')->first()
    ?? Category::query()->first();

$typeMap = [
    'pasta' => 'grains-pasta-sides',
    'noodle' => 'grains-pasta-sides',
    'rice' => 'grains-pasta-sides',
    'seasoning' => 'condiments-spice-bake',
    'spice' => 'condiments-spice-bake',
    'coffee' => 'beverages',
    'soda' => 'beverages',
    'juice' => 'beverages',
    'water' => 'beverages',
    'tea' => 'beverages',
    'personal care' => 'personal-care-health',
    'shampoo' => 'personal-care-health',
    'toothpaste' => 'personal-care-health',
    'hair' => 'personal-care-health',
    'canned' => 'canned-goods-soups',
    'chips' => 'cookies-snacks-candy',
    'candy' => 'cookies-snacks-candy',
    'chocolate' => 'cookies-snacks-candy',
    'cracker' => 'cookies-snacks-candy',
    'cookie' => 'cookies-snacks-candy',
    'snack' => 'cookies-snacks-candy',
    'laundry' => 'paper-cleaning-home',
    'home & garden' => 'paper-cleaning-home',
    'household' => 'paper-cleaning-home',
    'floor' => 'paper-cleaning-home',
    'dish' => 'paper-cleaning-home',
    'milk' => 'dairy-eggs-cheese',
    'dairy' => 'dairy-eggs-cheese',
    'yogurt' => 'dairy-eggs-cheese',
    'ice cream' => 'frozen-foods',
    'frozen' => 'frozen-foods',
    'meat' => 'meat-seafood',
    'seafood' => 'meat-seafood',
    'baby' => 'baby-care',
    'cereal' => 'breakfast-cereal',
    'oatmeal' => 'breakfast-cereal',
    'bread' => 'bread-bakery',
    'cake' => 'bread-bakery',
];

function mapCategory(?string $type, $categories, $fallback, $typeMap): int
{
    $type = Str::lower(trim((string) $type));
    if ($type !== '' && isset($categories[$type])) {
        return (int) $categories[$type]->id;
    }
    foreach ($typeMap as $needle => $slug) {
        if ($type !== '' && str_contains($type, $needle)) {
            $hit = Category::query()->where('slug', $slug)->first();
            if ($hit) {
                return (int) $hit->id;
            }
        }
    }

    return (int) $fallback->id;
}

function parseSqlRows(string $sql): array
{
    $rows = [];
    if (! preg_match_all('/INSERT INTO `products` \(([^)]+)\) VALUES\s*(.*?);/is', $sql, $blocks, PREG_SET_ORDER)) {
        return $rows;
    }
    foreach ($blocks as $block) {
        $cols = array_map(fn ($c) => trim($c, " `\n\r\t"), explode(',', $block[1]));
        $values = $block[2];
        $len = strlen($values);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && $values[$i] !== '(') {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $i++;
            $fields = [];
            $current = '';
            $inString = false;
            $escape = false;
            while ($i < $len) {
                $ch = $values[$i];
                if ($inString) {
                    if ($escape) {
                        $current .= $ch;
                        $escape = false;
                    } elseif ($ch === '\\') {
                        $current .= $ch;
                        $escape = true;
                    } elseif ($ch === "'") {
                        if ($i + 1 < $len && $values[$i + 1] === "'") {
                            $current .= "'";
                            $i++;
                        } else {
                            $inString = false;
                        }
                    } else {
                        $current .= $ch;
                    }
                } else {
                    if ($ch === "'") {
                        $inString = true;
                    } elseif ($ch === ',') {
                        $fields[] = trim($current);
                        $current = '';
                    } elseif ($ch === ')') {
                        $fields[] = trim($current);
                        $i++;
                        break;
                    } else {
                        $current .= $ch;
                    }
                }
                $i++;
            }
            if (count($fields) === count($cols)) {
                $row = [];
                foreach ($cols as $idx => $col) {
                    $raw = $fields[$idx];
                    $row[$col] = strtoupper($raw) === 'NULL' ? null : (str_starts_with($raw, "'") ? stripcslashes(substr($raw, 1, -1)) : $raw);
                }
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

$sql = file_get_contents($path);
$rows = parseSqlRows($sql);
$created = 0;
$updated = 0;
$skipped = 0;

foreach ($rows as $row) {
    $name = trim((string) ($row['name'] ?? ''));
    if ($name === '') {
        $skipped++;
        continue;
    }
    $ean = $row['ean'] ?? null;
    $barcode = is_string($ean) && $ean !== '' ? substr($ean, 0, 64) : null;
    $image = $row['image'] ?? $row['image_path'] ?? 'img/placeholders/product.svg';
    if (! is_string($image) || $image === '') {
        $image = 'img/placeholders/product.svg';
    }
    $attrs = [
        'category_id' => mapCategory($row['product_type'] ?? null, $categories, $fallback, $typeMap),
        'description' => $row['description'] ?? null,
        'price' => $row['price'] ?? 0,
        'stock' => max(0, (int) ($row['stock'] ?? 0)),
        'image_path' => $image,
        'is_available' => true,
    ];
    $match = ['store_profile_id' => $store->id, 'name' => $name];
    if ($barcode) {
        $existingBarcode = Product::query()
            ->where('store_profile_id', $store->id)
            ->where('barcode', $barcode)
            ->where('name', '!=', $name)
            ->exists();
        if (! $existingBarcode) {
            $attrs['barcode'] = $barcode;
        }
    }
    $product = Product::query()->updateOrCreate($match, $attrs);
    $product->wasRecentlyCreated ? $created++ : $updated++;
}

echo "file={$path}\nstore={$store->store_name} id={$store->id}\nrows=".count($rows)." created={$created} updated={$updated} skipped={$skipped}\n";
