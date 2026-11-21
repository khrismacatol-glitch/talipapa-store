<?php

namespace App\Services\Search;

use App\Console\Commands\ExportSearchCatalog;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class HumanTrainLabelService
{
    public const INTENTS = ['cow', 'low_fat', 'diabetic', 'organic', 'powder', 'other'];

    public const MAX_QUERIES = 20;

    public const MAX_PRODUCTS = 400;

    /**
     * @return array<int, string>
     */
    public function queriesFrom(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $queries = [];
        foreach ($lines as $line) {
            $line = RankingLabService::stripListNumber($line);
            if ($line === '') {
                continue;
            }
            $key = mb_strtolower($line);
            $queries[$key] = $line;
            if (count($queries) >= self::MAX_QUERIES) {
                break;
            }
        }

        return array_values($queries);
    }

    /**
     * @return Collection<int, Product>
     */
    public function catalogProducts(string $scope = 'all', string $contains = '', ?int $categoryId = null): Collection
    {
        $query = Product::query()
            ->available()
            ->with('category')
            ->orderBy('id');

        $needles = $this->containsNeedles($contains);
        if ($scope === 'contains' && $needles !== []) {
            $query->where(function ($outer) use ($needles) {
                foreach ($needles as $needle) {
                    $like = '%'.addcslashes($needle, '%_\\').'%';
                    $outer->orWhere('name', 'like', $like)
                        ->orWhere('description', 'like', $like);
                }
            });
        }

        if ($scope === 'category' && $categoryId !== null && $categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        return $query->limit(self::MAX_PRODUCTS)->get();
    }

    /**
     * @return array<int, string>
     */
    public function containsNeedles(string $contains): array
    {
        $parts = preg_split('/[,;]+/', $contains) ?: [];
        $needles = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $needles[] = $part;
        }

        return array_values(array_unique($needles));
    }

    /**
     * @param  array<int, string>  $queries
     * @return array<int, array<int, string>>
     */
    public function judgmentRows(array $queries, Collection $products): array
    {
        $keys = ExportSearchCatalog::keys($products);
        $rows = [];
        foreach ($queries as $query) {
            foreach ($products as $product) {
                $rows[] = [
                    $query,
                    (string) $product->id,
                    (string) ($keys[$product->id] ?? ''),
                    (string) $product->name,
                    (string) ($product->description ?? ''),
                    (string) ($product->category?->name ?? ''),
                    '',
                    '',
                    'train',
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function judgmentHeaders(): array
    {
        return ['query', 'product_id', 'product_key', 'name', 'description', 'category', 'grade', 'intent', 'split'];
    }

    /**
     * @return array{qrels: int, queries: int, skipped: int, errors: array<int, string>}
     */
    public function storeUpload(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return ['qrels' => 0, 'queries' => 0, 'skipped' => 0, 'errors' => ['Could not read the upload.']];
        }

        $header = fgetcsv($handle) ?: [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $index = array_flip($header);
        $errors = [];
        if (! isset($index['query'], $index['product_key'], $index['grade'])) {
            fclose($handle);

            return ['qrels' => 0, 'queries' => 0, 'skipped' => 0, 'errors' => ['CSV must include query, product_key, and grade columns.']];
        }

        $qrels = [];
        $queries = [];
        $skipped = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $query = trim((string) ($row[$index['query']] ?? ''));
            $key = trim((string) ($row[$index['product_key']] ?? ''));
            $gradeRaw = trim((string) ($row[$index['grade']] ?? ''));
            if ($query === '' || $key === '' || $gradeRaw === '') {
                $skipped++;
                continue;
            }
            if (! in_array($gradeRaw, ['0', '1', '2'], true)) {
                $errors[] = 'Invalid grade "'.$gradeRaw.'" for '.$query.' / '.$key.' (use 0, 1, or 2).';
                continue;
            }
            $qrels[] = [
                'query' => $query,
                'product_key' => $key,
                'grade' => (int) $gradeRaw,
            ];
            $intent = strtolower(trim((string) ($row[$index['intent']] ?? '')));
            if ($intent === '') {
                $intent = 'cow';
            }
            if (! in_array($intent, self::INTENTS, true)) {
                $errors[] = 'Invalid intent "'.$intent.'" for '.$query.'.';
                continue;
            }
            $split = strtolower(trim((string) ($row[$index['split']] ?? 'train')));
            if (! in_array($split, ['train', 'test'], true)) {
                $split = 'train';
            }
            $queries[mb_strtolower($query)] = [
                'query' => $query,
                'intent' => $intent,
                'split' => $split,
                'lang' => 'ceb',
            ];
        }
        fclose($handle);

        $dir = $this->humanDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->writeCsv($this->humanQrelsPath(), ['query', 'product_key', 'grade'], $qrels);
        $this->writeCsv($this->humanQueriesPath(), ['query', 'intent', 'split', 'lang'], array_values($queries));

        return [
            'qrels' => count($qrels),
            'queries' => count($queries),
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    public function applyToTrainingFiles(): array
    {
        $humanQrels = $this->humanQrelsPath();
        $humanQueries = $this->humanQueriesPath();
        if (! is_readable($humanQrels)) {
            return ['ok' => false, 'message' => 'Upload a graded CSV first.'];
        }

        $qrelsDest = $this->qrelsPath();
        if (is_file($qrelsDest)) {
            copy($qrelsDest, $this->humanDir().DIRECTORY_SEPARATOR.'qrels.backup.csv');
        }
        copy($humanQrels, $qrelsDest);

        $added = 0;
        if (is_readable($humanQueries)) {
            $existing = $this->readQuerySet($this->queriesPath());
            $incoming = $this->readDictRows($humanQueries);
            $out = $this->readDictRows($this->queriesPath());
            foreach ($incoming as $row) {
                $key = mb_strtolower((string) ($row['query'] ?? ''));
                if ($key === '' || isset($existing[$key])) {
                    continue;
                }
                $out[] = [
                    'query' => $row['query'],
                    'intent' => $row['intent'] ?? 'cow',
                    'split' => $row['split'] ?? 'train',
                    'lang' => $row['lang'] ?? 'ceb',
                ];
                $existing[$key] = true;
                $added++;
            }
            $this->writeCsv($this->queriesPath(), ['query', 'intent', 'split', 'lang'], $out);
        }

        return [
            'ok' => true,
            'message' => 'Wrote training qrels (previous file backed up as qrels.backup.csv). Appended '.$added.' new queries.',
            'qrels' => $qrelsDest,
        ];
    }

    public function status(): array
    {
        return [
            'human_qrels' => is_file($this->humanQrelsPath()) ? $this->countDataRows($this->humanQrelsPath()) : 0,
            'human_queries' => is_file($this->humanQueriesPath()) ? $this->countDataRows($this->humanQueriesPath()) : 0,
            'training_qrels' => is_file($this->qrelsPath()) ? $this->countDataRows($this->qrelsPath()) : 0,
            'catalog_count' => Product::query()->available()->count(),
            'export_cap' => self::MAX_PRODUCTS,
            'human_qrels_path' => $this->humanQrelsPath(),
            'qrels_path' => $this->qrelsPath(),
        ];
    }

    public function humanDir(): string
    {
        return rtrim((string) config('ranking-lab.human_dir', base_path('ml/data/human')), '/\\');
    }

    public function humanQrelsPath(): string
    {
        return $this->humanDir().DIRECTORY_SEPARATOR.'qrels.csv';
    }

    public function humanQueriesPath(): string
    {
        return $this->humanDir().DIRECTORY_SEPARATOR.'queries.csv';
    }

    public function qrelsPath(): string
    {
        return (string) config('ranking-lab.qrels_path', base_path('ml/data/qrels.csv'));
    }

    public function queriesPath(): string
    {
        return (string) config('ranking-lab.queries_path', base_path('ml/data/queries.csv'));
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            fputcsv($handle, $line);
        }
        fclose($handle);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readDictRows(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }
        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle) ?: [];
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[(string) $header] = (string) ($row[$i] ?? '');
            }
            $rows[] = $assoc;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return array<string, true>
     */
    private function readQuerySet(string $path): array
    {
        $set = [];
        foreach ($this->readDictRows($path) as $row) {
            $key = mb_strtolower(trim((string) ($row['query'] ?? '')));
            if ($key !== '') {
                $set[$key] = true;
            }
        }

        return $set;
    }

    private function countDataRows(string $path): int
    {
        return max(0, count($this->readDictRows($path)));
    }
}
