<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Search\SemanticRanker;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ExportSearchCatalog extends Command
{
    protected $signature = 'search:export-catalog {--path=ml/data/products.json}';

    protected $description = 'Export available products for training and evaluating the ML ranker';

    public function handle(): int
    {
        $products = Product::query()->available()->with('category')->orderBy('id')->get();
        $keys = self::keys($products);

        $rows = $products->map(fn (Product $product) => ['key' => $keys[$product->id]] + SemanticRanker::payload($product));

        $path = base_path($this->option('path'));
        file_put_contents($path, json_encode($rows->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("Exported {$rows->count()} products to {$path}");

        return self::SUCCESS;
    }

    /**
     * Stable product keys used in ml/data/qrels.csv: the name slug, with the
     * id appended when two products share a name.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, string>
     */
    public static function keys(Collection $products): array
    {
        $counts = $products->countBy(fn (Product $product) => Str::slug($product->name));

        return $products->mapWithKeys(function (Product $product) use ($counts) {
            $slug = Str::slug($product->name);

            return [$product->id => $counts[$slug] > 1 ? $slug.'-'.$product->id : $slug];
        })->all();
    }
}
