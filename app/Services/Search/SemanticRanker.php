<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SemanticRanker
{
    public function enabled(): bool
    {
        return (bool) config('semantic-search.enabled');
    }

    /**
     * Rank products for a query. Returns null when the service is unavailable
     * so callers fall back to keyword search. Milk and non-milk queries both
     * use the ML service (LambdaMART vs LaBSE+BM25).
     *
     * @param  Collection<int, Product>  $products
     * @return array{milk_query: float, intents: array<string, float>, mode: string, model_version: string, ids: array<int, int>, scores: array<int, array<string, mixed>>}|null
     */
    public function rank(string $query, Collection $products, ?int $topK = null, ?float $timeout = null): ?array
    {
        try {
            $response = Http::baseUrl((string) config('semantic-search.url'))
                ->timeout($timeout ?? (float) config('semantic-search.timeout', 3))
                ->acceptJson()
                ->post('/rank', [
                    'query' => $query,
                    'top_k' => $topK,
                    'products' => $products->map(fn (Product $product) => self::payload($product))->values()->all(),
                ]);
        } catch (Throwable $e) {
            Log::warning('Semantic search unavailable: '.$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $rows = (array) $response->json('results', []);
        $ids = array_map('intval', array_column($rows, 'id'));
        if ($ids === []) {
            return null;
        }

        $scores = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $scores[$id] = is_array($row['parts'] ?? null) ? $row['parts'] : ['total' => $row['score'] ?? null];
            }
        }

        return [
            'milk_query' => (float) $response->json('milk_query'),
            'intents' => (array) $response->json('intents', []),
            'mode' => (string) $response->json('mode', ''),
            'model_version' => (string) $response->json('model_version', 'legacy'),
            'ids' => $ids,
            'scores' => $scores,
        ];
    }

    /**
     * @return array{id: int, name: string, description: ?string, category: ?string, specs: ?array}
     */
    public static function payload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category?->name,
            'specs' => $product->specs,
        ];
    }
}
