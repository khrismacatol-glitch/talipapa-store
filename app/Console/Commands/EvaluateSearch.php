<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Voc\QueryAttributeExtractor;
use App\Services\VoiceAssistant\ProductSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class EvaluateSearch extends Command
{
    protected $signature = 'search:evaluate
        {--split=test : Query split from ml/data/queries.csv}
        {--show : Print the top 5 products for the four required queries}';

    protected $description = 'Run milk test queries through ProductSearchService with keyword and ML ranking and report P@5, R@5, NDCG@5';

    private const REQUIRED = ['Gatas sa baka', 'Gatas nga dili makataba', 'Gatas para sa mga diabetic', 'Gatas na organic'];

    public function handle(QueryAttributeExtractor $extractor): int
    {
        $queries = $this->queries();
        $qrels = $this->qrels();
        $products = Product::query()->available()->orderBy('id')->get();
        $keys = ExportSearchCatalog::keys($products);
        $milkIds = $products->filter(fn (Product $p) => (bool) ($p->specs['is_milk'] ?? false))->pluck('id')->all();

        if (config('semantic-search.enabled') === false) {
            $this->warn('SEMANTIC_SEARCH_ENABLED is false; enabling it for the semantic run.');
        }

        $this->call('search:warm');

        $runs = [];
        $summary = [];

        foreach (['keyword' => false, 'semantic' => true] as $mode => $enabled) {
            config(['semantic-search.enabled' => $enabled]);
            $search = app(ProductSearchService::class);
            $rows = [];

            foreach ($queries as $query) {
                $results = $search->searchFromNlp($extractor->extract($query), null, 20);
                $runs[$mode][$query] = $results->map(fn (Product $p) => $keys[$p->id] ?? (string) $p->id)->values()->all();
                $rows[] = $this->metrics($results, $keys, $qrels[$query] ?? [], $milkIds);
            }

            $summary[] = [$mode, ...array_map(
                fn (string $metric) => number_format(collect($rows)->avg($metric), 3),
                ['P@5', 'R@5', 'NDCG@5', 'Leak@5'],
            )];
        }

        $path = base_path('ml/reports/laravel_runs.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($runs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info(count($queries).' milk queries ('.$this->option('split').' split), '.$products->count().' products');
        $this->table(['Mode', 'P@5', 'R@5', 'NDCG@5', 'Leak@5'], $summary);
        $this->line("Runs written to {$path}");

        if ($this->option('show')) {
            $names = $products->pluck('name', 'id');
            $byKey = array_flip($keys);

            foreach (self::REQUIRED as $query) {
                foreach (['keyword', 'semantic'] as $mode) {
                    $top = array_map(fn (string $key) => $names[$byKey[$key] ?? 0] ?? $key, array_slice($runs[$mode][$query] ?? [], 0, 5));
                    $this->line("<comment>{$query}</comment> [{$mode}]");
                    foreach ($top as $i => $name) {
                        $this->line('  '.($i + 1).'. '.$name);
                    }
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Product>  $results
     * @param  array<int, string>  $keys
     * @param  array<string, int>  $grades
     * @param  array<int, int>  $milkIds
     * @return array<string, float>
     */
    private function metrics(Collection $results, array $keys, array $grades, array $milkIds): array
    {
        $top = $results->take(5)->values();
        $gains = $top->map(fn (Product $p) => $grades[$keys[$p->id] ?? ''] ?? 0)->all();
        $relevant = count(array_filter($grades, fn (int $g) => $g >= 2));
        $hits = count(array_filter($gains, fn (int $g) => $g >= 2));

        $dcg = fn (array $g) => array_sum(array_map(fn ($gain, $i) => (2 ** $gain - 1) / log($i + 2, 2), $g, array_keys($g)));
        $ideal = array_values($grades);
        rsort($ideal);
        $idcg = $dcg(array_slice($ideal, 0, 5));

        return [
            'P@5' => $hits / 5,
            'R@5' => $relevant ? $hits / $relevant : 0.0,
            'NDCG@5' => $idcg ? $dcg($gains) / $idcg : 0.0,
            'Leak@5' => $top->isEmpty() ? 0.0 : $top->reject(fn (Product $p) => in_array($p->id, $milkIds, true))->count() / $top->count(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function queries(): array
    {
        $rows = array_map('str_getcsv', file(base_path('ml/data/queries.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        array_shift($rows);

        return array_values(array_map(
            fn (array $row) => $row[0],
            array_filter($rows, fn (array $row) => $row[2] === $this->option('split') && $row[1] !== 'other'),
        ));
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function qrels(): array
    {
        $qrels = [];
        $rows = array_map('str_getcsv', file(base_path('ml/data/qrels.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        array_shift($rows);

        foreach ($rows as [$query, $key, $grade]) {
            $qrels[$query][$key] = (int) $grade;
        }

        return $qrels;
    }
}
