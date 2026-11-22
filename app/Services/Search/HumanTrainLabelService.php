<?php

namespace App\Services\Search;

use App\Console\Commands\ExportSearchCatalog;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class HumanTrainLabelService
{
    public const INTENTS = ['cow', 'low_fat', 'diabetic', 'organic', 'powder', 'other'];

    public const MAX_QUERIES = 200;

    public const MAX_PRODUCTS = 400;

    public const EXPAND_CONTAINS = 'milk';

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
    /**
     * @return array<int, array{query: string, intent: string, split: string, lang: string}>
     */
    public function trainQueriesFromFile(): array
    {
        $rows = [];
        foreach ($this->readDictRows($this->queriesPath()) as $row) {
            $query = RankingLabService::stripListNumber((string) ($row['query'] ?? ''));
            $split = strtolower(trim((string) ($row['split'] ?? 'train')));
            if ($query === '' || $split === 'test') {
                continue;
            }
            $rows[mb_strtolower($query)] = [
                'query' => $query,
                'intent' => strtolower(trim((string) ($row['intent'] ?? 'cow'))) ?: 'cow',
                'split' => 'train',
                'lang' => (string) ($row['lang'] ?? 'ceb'),
            ];
            if (count($rows) >= self::MAX_QUERIES) {
                break;
            }
        }

        return array_values($rows);
    }

    public function judgmentRows(array $queries, Collection $products): array
    {
        $keys = ExportSearchCatalog::keys($products);
        $grades = $this->qrelsMap($this->qrelsPath());
        $meta = $this->queryMetaMap();
        $rows = [];
        foreach ($queries as $query) {
            $qKey = mb_strtolower($query);
            $intent = $meta[$qKey]['intent'] ?? '';
            $split = $meta[$qKey]['split'] ?? 'train';
            foreach ($products as $product) {
                $productKey = (string) ($keys[$product->id] ?? '');
                $rows[] = [
                    $query,
                    (string) $product->id,
                    $productKey,
                    (string) $product->name,
                    (string) ($product->description ?? ''),
                    (string) ($product->category?->name ?? ''),
                    $grades[$this->pairKey($query, $productKey)] ?? '',
                    $intent,
                    $split === 'test' ? 'test' : 'train',
                ];
            }
        }

        return $rows;
    }

    /**
     * Current training queries as a judgment CSV: every milk catalog SKU per query,
     * plus any extra SKUs already in qrels. Existing 0/1/2 grades are filled in.
     *
     * @return array<int, array<int, string>>
     */
    public function existingJudgmentRows(): array
    {
        $all = Product::query()->available()->with('category')->orderBy('id')->get();
        $keys = ExportSearchCatalog::keys($all);
        $byKey = [];
        foreach ($all as $product) {
            $key = (string) ($keys[$product->id] ?? '');
            if ($key !== '') {
                $byKey[$key] = $product;
            }
        }
        $milk = $this->catalogProducts('contains', self::EXPAND_CONTAINS);
        $milkKeys = ExportSearchCatalog::keys($milk);
        $grades = $this->qrelsKeyed($this->qrelsPath());
        $queries = [];
        foreach ($grades as $row) {
            $query = trim((string) ($row['query'] ?? ''));
            if ($query !== '') {
                $queries[mb_strtolower($query)] = $query;
            }
        }
        $meta = $this->queryMetaMap();
        $rows = [];
        foreach ($queries as $qKey => $query) {
            $seen = [];
            foreach ($milk as $product) {
                $productKey = (string) ($milkKeys[$product->id] ?? '');
                if ($productKey === '') {
                    continue;
                }
                $seen[strtolower($productKey)] = true;
                $rows[] = $this->judgmentRow(
                    $query,
                    $product,
                    $productKey,
                    (string) ($grades[$this->pairKey($query, $productKey)]['grade'] ?? ''),
                    $meta[$qKey] ?? null,
                );
            }
            foreach ($grades as $row) {
                if (mb_strtolower(trim((string) ($row['query'] ?? ''))) !== $qKey) {
                    continue;
                }
                $productKey = trim((string) ($row['product_key'] ?? ''));
                if ($productKey === '' || isset($seen[strtolower($productKey)])) {
                    continue;
                }
                $seen[strtolower($productKey)] = true;
                $product = $byKey[$productKey] ?? null;
                $rows[] = $this->judgmentRow(
                    $query,
                    $product,
                    $productKey,
                    (string) ($row['grade'] ?? ''),
                    $meta[$qKey] ?? null,
                );
            }
        }

        return $rows;
    }

    /**
     * @param  array{intent?: string, split?: string}|null  $meta
     * @return array<int, string>
     */
    private function judgmentRow(string $query, ?Product $product, string $productKey, string $grade, ?array $meta): array
    {
        return [
            $query,
            $product ? (string) $product->id : '',
            $productKey,
            (string) ($product?->name ?? ''),
            (string) ($product?->description ?? ''),
            (string) ($product?->category?->name ?? ''),
            $grade,
            $meta['intent'] ?? 'cow',
            $meta['split'] ?? 'train',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function judgmentHeaders(): array
    {
        return ['query', 'product_id', 'product_key', 'name', 'description', 'category', 'grade', 'intent', 'split'];
    }

    /**
     * @return array{qrels: int, queries: int, incoming: int, skipped: int, errors: array<int, string>}
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

        $qrels = $this->qrelsKeyed($this->humanQrelsPath());
        $queries = [];
        foreach ($this->readDictRows($this->humanQueriesPath()) as $row) {
            $q = mb_strtolower(trim((string) ($row['query'] ?? '')));
            if ($q !== '') {
                $queries[$q] = [
                    'query' => (string) ($row['query'] ?? ''),
                    'intent' => (string) ($row['intent'] ?? 'cow'),
                    'split' => (string) ($row['split'] ?? 'train'),
                    'lang' => (string) ($row['lang'] ?? 'ceb'),
                ];
            }
        }
        $skipped = 0;
        $incoming = 0;
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
            $incoming++;
            $qrels[$this->pairKey($query, $key)] = [
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
        $this->writeCsv($this->humanQrelsPath(), ['query', 'product_key', 'grade'], array_values($qrels));
        $this->writeCsv($this->humanQueriesPath(), ['query', 'intent', 'split', 'lang'], array_values($queries));

        return [
            'qrels' => count($qrels),
            'queries' => count($queries),
            'incoming' => $incoming,
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

        $merged = $this->qrelsKeyed($qrelsDest);
        $updated = 0;
        $addedPairs = 0;
        foreach ($this->readDictRows($humanQrels) as $row) {
            $query = trim((string) ($row['query'] ?? ''));
            $key = trim((string) ($row['product_key'] ?? ''));
            $grade = trim((string) ($row['grade'] ?? ''));
            if ($query === '' || $key === '' || $grade === '') {
                continue;
            }
            $pair = $this->pairKey($query, $key);
            if (isset($merged[$pair])) {
                if ((string) $merged[$pair]['grade'] !== $grade) {
                    $updated++;
                }
            } else {
                $addedPairs++;
            }
            $merged[$pair] = [
                'query' => $query,
                'product_key' => $key,
                'grade' => (int) $grade,
            ];
        }
        $this->writeCsv($qrelsDest, ['query', 'product_key', 'grade'], array_values($merged));

        $addedQueries = 0;
        if (is_readable($humanQueries)) {
            $out = [];
            foreach ($this->readDictRows($this->queriesPath()) as $row) {
                $key = mb_strtolower(trim((string) ($row['query'] ?? '')));
                if ($key !== '') {
                    $out[$key] = $row;
                }
            }
            foreach ($this->readDictRows($humanQueries) as $row) {
                $key = mb_strtolower(trim((string) ($row['query'] ?? '')));
                if ($key === '') {
                    continue;
                }
                if (! isset($out[$key])) {
                    $addedQueries++;
                }
                $out[$key] = [
                    'query' => $row['query'] ?? '',
                    'intent' => $row['intent'] ?? 'cow',
                    'split' => $row['split'] ?? 'train',
                    'lang' => $row['lang'] ?? 'ceb',
                ];
            }
            $this->writeCsv($this->queriesPath(), ['query', 'intent', 'split', 'lang'], array_values($out));
        }

        return [
            'ok' => true,
            'message' => 'Merged into training qrels (backup qrels.backup.csv): '.$addedPairs.' new pairs, '.$updated.' updated grades, '.$addedQueries.' new queries. Other queries were kept.',
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
            'milk_sku_count' => $this->catalogProducts('contains', self::EXPAND_CONTAINS)->count(),
            'export_cap' => self::MAX_PRODUCTS,
            'human_qrels_path' => $this->humanQrelsPath(),
            'qrels_path' => $this->qrelsPath(),
            'latest_candidate' => $this->latestCandidateDir(),
        ];
    }

    public function modelsDir(): string
    {
        return rtrim((string) config('ranking-lab.models_dir', base_path('ml/models')), '/\\');
    }

    public function latestCandidateDir(): ?string
    {
        $root = $this->modelsDir().DIRECTORY_SEPARATOR.'candidates';
        if (! is_dir($root)) {
            return null;
        }
        $dirs = [];
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, 'grocery-')) {
                continue;
            }
            $dir = $root.DIRECTORY_SEPARATOR.$name;
            if (is_dir($dir) && is_file($dir.DIRECTORY_SEPARATOR.'ranker.txt')) {
                $dirs[] = $dir;
            }
        }
        rsort($dirs);

        return $dirs[0] ?? null;
    }

    /**
     * @return array{ok: bool, message: string, archive?: string, candidate?: string}
     */
    public function promoteLatestCandidate(): array
    {
        $candidate = $this->latestCandidateDir();
        if ($candidate === null) {
            return ['ok' => false, 'message' => 'No candidate with ranker.txt under ml/models/candidates. Train first.'];
        }

        $live = $this->modelsDir();
        $stamp = now()->utc()->format('Ymd\\THis\\Z');
        $archive = $live.DIRECTORY_SEPARATOR.'archive'.DIRECTORY_SEPARATOR.'before-'.$stamp;
        $this->copyModelArtifacts($live, $archive);
        $this->copyModelArtifacts($candidate, $live);

        return [
            'ok' => true,
            'message' => 'Copied '.basename($candidate).' into ml/models (backup archive/before-'.$stamp.'). Restart uvicorn on port 8088 to load it. Search still uses the old process until that restart.',
            'archive' => $archive,
            'candidate' => $candidate,
        ];
    }

    private function copyModelArtifacts(string $from, string $to): void
    {
        if (! is_dir($to)) {
            mkdir($to, 0777, true);
        }
        foreach ([
            'ranker.txt',
            'ranker_text_only.txt',
            'intent_classifier.joblib',
            'product_milk_classifier.joblib',
            'intent_prototypes.npy',
            'meta.json',
            'feedback_index.npz',
        ] as $name) {
            $src = $from.DIRECTORY_SEPARATOR.$name;
            if (is_file($src)) {
                copy($src, $to.DIRECTORY_SEPARATOR.$name);
            }
        }
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
     * @return array<string, array{query: string, product_key: string, grade: int}>
     */
    private function qrelsKeyed(string $path): array
    {
        $map = [];
        foreach ($this->readDictRows($path) as $row) {
            $query = trim((string) ($row['query'] ?? ''));
            $key = trim((string) ($row['product_key'] ?? ''));
            $grade = trim((string) ($row['grade'] ?? ''));
            if ($query === '' || $key === '' || $grade === '') {
                continue;
            }
            $map[$this->pairKey($query, $key)] = [
                'query' => $query,
                'product_key' => $key,
                'grade' => (int) $grade,
            ];
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function qrelsMap(string $path): array
    {
        $map = [];
        foreach ($this->qrelsKeyed($path) as $pair => $row) {
            $map[$pair] = (string) $row['grade'];
        }

        return $map;
    }

    /**
     * @return array<string, array{intent: string, split: string}>
     */
    private function queryMetaMap(): array
    {
        $map = [];
        foreach ($this->readDictRows($this->queriesPath()) as $row) {
            $query = mb_strtolower(trim((string) ($row['query'] ?? '')));
            if ($query === '') {
                continue;
            }
            $map[$query] = [
                'intent' => strtolower(trim((string) ($row['intent'] ?? 'cow'))) ?: 'cow',
                'split' => strtolower(trim((string) ($row['split'] ?? 'train'))) ?: 'train',
            ];
        }

        return $map;
    }

    private function pairKey(string $query, string $productKey): string
    {
        return mb_strtolower(trim($query))."\0".strtolower(trim($productKey));
    }

    private function countDataRows(string $path): int
    {
        return max(0, count($this->readDictRows($path)));
    }
}
