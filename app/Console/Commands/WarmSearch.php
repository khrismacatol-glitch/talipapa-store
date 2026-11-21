<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Search\SemanticRanker;
use Illuminate\Console\Command;

class WarmSearch extends Command
{
    protected $signature = 'search:warm';

    protected $description = 'Embed every available product in the ML service so the first search is fast';

    public function handle(SemanticRanker $ranker): int
    {
        $products = Product::query()->available()->with('category')->get();
        $started = microtime(true);

        if ($ranker->rank('gatas', $products, 1, 600) === null) {
            $this->error('ML service did not answer at '.config('semantic-search.url'));

            return self::FAILURE;
        }

        $this->info(sprintf('Embedded %d products in %.1fs', $products->count(), microtime(true) - $started));

        return self::SUCCESS;
    }
}
