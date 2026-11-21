<?php

namespace App\Services\Search;

use App\Models\Product;
use App\Services\Voc\QueryAttributeExtractor;
use App\Services\VoiceAssistant\ProductSearchService;
use Illuminate\Support\Str;

class RankingLabService
{
    /** @var array<string, string> */
    public const TECHNOLOGIES = [
        'total' => 'Live total',
        'labse' => 'LaBSE',
        'bm25' => 'BM25',
        'extra' => 'Leftover BM25',
        'lambdamart' => 'LambdaMART',
        'php_keyword' => 'VOC keyword',
    ];

    /** Exact labels from storage/app/eval (not VOC). */
    /** @var array<int, string> */
    public const FAMILIES = [
        'Drinking / cow',
        'Non-cow',
        'Low fat',
        'No sugar',
        'Organic',
        'Powder',
        'Flavored',
        'Infant',
        'Kids',
        'Adult / senior',
        'Condensed',
        'Evaporated',
        'Cooking',
        'Probiotic',
        'Coconut / gata',
        'Other',
    ];

    /** @var array<string, array{query_id: string, query: string, gold_family: ?string, second_grader_family: ?string, status: string}> */
    private array $goldByQuery = [];

    /** @var array<int, array{family: string, secondary: array<int, string>, name: string}> */
    private array $productFamilies = [];

    /** @var array<string, mixed> */
    private array $evalDebug = [];

    private ?string $goldResolvedPath = null;

    private ?string $familyResolvedPath = null;

    public function __construct(
        private readonly QueryAttributeExtractor $extractor,
        private readonly ProductSearchService $productSearch,
    ) {}

    public static function stripListNumber(string $query): string
    {
        $query = trim($query);

        return trim((string) preg_replace('/^\s*\d+[.)]\s*/u', '', $query));
    }

    /**
     * @param  array<int, string>  $queries
     * @return array<string, mixed>
     */
    public function evaluate(array $queries, int $limit = 32): array
    {
        $this->loadEvalFiles();

        $runs = [];
        $unlabeled = [];
        $nExcluded = 0;

        foreach ($queries as $query) {
            $cleaned = self::stripListNumber((string) $query);
            if ($cleaned === '') {
                continue;
            }
            $run = $this->evaluateOne($cleaned, $limit);
            $runs[] = $run;
            if (($run['status'] ?? null) === 'no_relevant_item') {
                $nExcluded++;
            } elseif (($run['gold'] ?? null) === null) {
                $unlabeled[] = $cleaned;
            }
        }

        $matrixLabels = $this->matrixLabels($runs);
        $eval = $this->evalDebug;
        $eval['gold_matched'] = count($runs) - count($unlabeled) - $nExcluded;
        $eval['unlabeled'] = $unlabeled;
        $eval['n_excluded'] = $nExcluded;
        $eval['unmapped_products'] = $this->uniqueUnmapped($runs);

        return [
            'technologies' => self::TECHNOLOGIES,
            'classes' => $this->classMap($matrixLabels),
            'runs' => $runs,
            'matrices' => $this->confusionMatrices($runs, $matrixLabels),
            'unlabeled' => $unlabeled,
            'n_excluded' => $nExcluded,
            'gold_path' => $this->goldFile(),
            'family_path' => $this->familyFile(),
            'gold_sha256' => $this->fileSha256($this->goldFile()),
            'family_sha256' => $this->fileSha256($this->familyFile()),
            'kappa' => $this->graderKappa(),
            'eval' => $eval,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function describeEvalFiles(): array
    {
        $this->loadEvalFiles();

        return $this->evalDebug;
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluateOne(string $query, int $limit = 32): array
    {
        $this->loadEvalFiles();
        $query = self::stripListNumber($query);
        $goldRow = $this->goldRowFor($query);

        $nlp = $this->extractor->extract($query);
        $products = $this->productSearch->searchFromNlp($nlp, null, $limit);
        $gold = $goldRow['gold_family'] ?? null;
        $status = $goldRow['status'] ?? null;
        $rows = [];
        $nUnmapped = 0;

        foreach ($products->values() as $index => $product) {
            /** @var Product $product */
            $parts = is_array($product->rank_scores ?? null) ? $product->rank_scores : [];
            $parts['php_keyword'] = round($this->productSearch->keywordScore($product, $nlp), 4);
            if (! array_key_exists('total', $parts) || $parts['total'] === null) {
                $parts['total'] = $parts['php_keyword'];
            }

            $mapping = $this->productFamilies[(int) $product->id] ?? null;
            if ($mapping === null) {
                $nUnmapped++;
            }
            $primary = $mapping['family'] ?? 'Unmapped';
            $secondary = $mapping['secondary'] ?? [];

            $rows[] = [
                'live_rank' => $index + 1,
                'id' => $product->id,
                'name' => $product->name,
                'store' => $product->store?->store_name,
                'category' => $product->category?->name,
                'price' => (float) $product->price,
                'url' => route('products.show', $product),
                'scores' => $parts,
                'family' => $primary,
                'secondary_families' => $secondary,
                'predicted' => $this->predictedFamily($gold, $primary, $secondary),
            ];
        }

        $rows = $this->withTechnologyRanks($rows);
        $top1 = [];
        $top1Ids = [];
        $top1Correct = [];
        foreach (array_keys(self::TECHNOLOGIES) as $tech) {
            $winner = collect($rows)->first(fn (array $row) => ($row['ranks'][$tech] ?? null) === 1);
            $primary = $winner['family'] ?? 'Unmapped';
            $secondary = $winner['secondary_families'] ?? [];
            $predicted = $this->predictedFamily($gold, $primary, $secondary);
            $top1[$tech] = $predicted;
            $top1Ids[$tech] = $winner['id'] ?? null;
            $top1Correct[$tech] = $gold !== null && $this->familyMatches($gold, $primary, $secondary);
        }

        return [
            'query' => $query,
            'query_id' => $goldRow['query_id'] ?? null,
            'catalog_query' => $this->productSearch->dialectCatalogQuery($query),
            'gold' => $gold,
            'status' => $status,
            'excluded' => $status === 'no_relevant_item',
            'n_unmapped' => $nUnmapped,
            'top1' => $top1,
            'top1_ids' => $top1Ids,
            'top1_correct' => $top1Correct,
            'nlp' => [
                'product' => $nlp['product'] ?? null,
                'brand' => $nlp['brand'] ?? null,
                'dietary' => $nlp['dietary'] ?? [],
                'exclusions' => $nlp['exclusions'] ?? [],
                'keywords' => $nlp['keywords'] ?? [],
                'units' => $nlp['units'] ?? [],
                'language' => $nlp['language'] ?? null,
            ],
            'ranker' => $this->productSearch->lastRankMeta,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $runs
     * @param  array<int, string>  $labels
     * @return array<string, array<string, mixed>>
     */
    public function confusionMatrices(array $runs, array $labels): array
    {
        $matrices = [];

        foreach (array_keys(self::TECHNOLOGIES) as $tech) {
            $yTrue = [];
            $yPred = [];
            $nUnmapped = 0;
            foreach ($runs as $run) {
                if (($run['status'] ?? null) === 'no_relevant_item') {
                    continue;
                }
                $gold = $run['gold'] ?? null;
                if (! is_string($gold) || $gold === '') {
                    continue;
                }
                $pred = $run['top1'][$tech] ?? 'Unmapped';
                $yTrue[] = $gold;
                $yPred[] = $pred;
                if ($pred === 'Unmapped') {
                    $nUnmapped++;
                }
            }

            $grid = RankingLabMetrics::confusionMatrix($yTrue, $yPred, $labels);
            $scores = RankingLabMetrics::familyScores($grid, $labels);
            $hits = 0;
            foreach ($yTrue as $i => $gold) {
                if (($yPred[$i] ?? null) === $gold) {
                    $hits++;
                }
            }
            $n = count($yTrue);
            $rowTotals = [];
            $colTotals = [];
            foreach ($labels as $label) {
                $rowTotals[$label] = array_sum($grid[$label] ?? []);
                $colTotals[$label] = 0;
            }
            foreach ($labels as $gold) {
                foreach ($labels as $pred) {
                    $colTotals[$pred] += $grid[$gold][$pred] ?? 0;
                }
            }

            $matrices[$tech] = [
                'grid' => $grid,
                'row_totals' => $rowTotals,
                'col_totals' => $colTotals,
                'n' => $n,
                'hits' => $hits,
                'n_unmapped' => $nUnmapped,
                'accuracy' => $n === 0 ? 0.0 : RankingLabMetrics::accuracy($yTrue, $yPred),
                'macro_f1' => $scores['macro_f1'],
                'macro_precision' => $scores['macro_precision'],
                'macro_recall' => $scores['macro_recall'],
                'weighted_f1' => $scores['weighted_f1'],
                'mcc' => RankingLabMetrics::mcc($yTrue, $yPred),
                'per_class' => $scores['per_class'],
            ];
        }

        return $matrices;
    }

    /**
     * @param  array<int, string>  $secondary
     */
    public function predictedFamily(?string $gold, string $primary, array $secondary): string
    {
        if ($gold !== null && $this->familyMatches($gold, $primary, $secondary)) {
            return $gold;
        }

        return $primary;
    }

    /**
     * @param  array<int, string>  $secondary
     */
    public function familyMatches(string $gold, string $primary, array $secondary): bool
    {
        if ($gold === $primary) {
            return true;
        }

        return in_array($gold, $secondary, true);
    }

    public function goldRowFor(string $query): ?array
    {
        $this->loadEvalFiles();
        $key = $this->queryKey($query);

        return $this->goldByQuery[$key] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function withTechnologyRanks(array $rows): array
    {
        $ranks = [];
        foreach (array_keys(self::TECHNOLOGIES) as $tech) {
            $ordered = $rows;
            usort($ordered, function (array $a, array $b) use ($tech) {
                $sa = $this->numericScore($a['scores'][$tech] ?? null);
                $sb = $this->numericScore($b['scores'][$tech] ?? null);
                if ($sa === null && $sb === null) {
                    return ((int) $a['id']) <=> ((int) $b['id']);
                }
                if ($sa === null) {
                    return 1;
                }
                if ($sb === null) {
                    return -1;
                }
                $cmp = $sb <=> $sa;
                if ($cmp !== 0) {
                    return $cmp;
                }

                return ((int) $a['id']) <=> ((int) $b['id']);
            });

            $rank = 0;
            foreach ($ordered as $row) {
                $rank++;
                $ranks[$row['id']][$tech] = $this->numericScore($row['scores'][$tech] ?? null) === null
                    ? null
                    : $rank;
            }
        }

        foreach ($rows as &$row) {
            $row['ranks'] = $ranks[$row['id']] ?? [];
        }

        return $rows;
    }

    private function loadEvalFiles(): void
    {
        $this->goldByQuery = [];
        $this->productFamilies = [];

        [$goldPath, $goldCandidates] = $this->resolveEvalPath('gold_queries.csv');
        [$familyPath, $familyCandidates] = $this->resolveEvalPath('product_family.csv');
        $this->goldResolvedPath = $goldPath;
        $this->familyResolvedPath = $familyPath;

        [$goldReport, $goldRows] = $this->readCsv($goldPath);
        $goldReport['candidates'] = $goldCandidates;
        $qi = $this->headerIndex($goldReport['headers_normalized'], ['query']);
        $gi = $this->headerIndex($goldReport['headers_normalized'], ['gold_family']);
        $si = $this->headerIndex($goldReport['headers_normalized'], ['status']);
        $idIdx = $this->headerIndex($goldReport['headers_normalized'], ['query_id']);
        $sg = $this->headerIndex($goldReport['headers_normalized'], ['second_grader_family']);

        foreach ($goldRows as $row) {
            $query = $qi === null ? '' : self::stripListNumber((string) ($row[$qi] ?? ''));
            if ($this->queryKey($query) === '') {
                continue;
            }
            $this->goldByQuery[$this->queryKey($query)] = [
                'query_id' => $idIdx === null ? '' : trim((string) ($row[$idIdx] ?? '')),
                'query' => trim($query),
                'gold_family' => $gi === null ? null : $this->nullableCell($row[$gi] ?? null),
                'second_grader_family' => $sg === null ? null : $this->nullableCell($row[$sg] ?? null),
                'status' => $si === null ? 'graded' : Str::lower(trim((string) ($row[$si] ?? 'graded'))),
            ];
        }
        $goldReport['loaded_rows'] = count($this->goldByQuery);
        $goldQueryLines = [];
        $invalidGold = [];
        $allowed = array_fill_keys(self::FAMILIES, true);
        foreach ($this->goldByQuery as $row) {
            if (($row['query'] ?? '') !== '') {
                $goldQueryLines[] = $row['query'];
            }
            $goldFamily = $row['gold_family'] ?? null;
            if (is_string($goldFamily) && $goldFamily !== '' && ! isset($allowed[$goldFamily])) {
                $invalidGold[] = $row['query'].' → '.$goldFamily;
            }
        }

        [$familyReport, $familyRows] = $this->readCsv($familyPath);
        $familyReport['candidates'] = $familyCandidates;
        $pid = $this->headerIndex($familyReport['headers_normalized'], ['product_id']);
        $fi = $this->headerIndex($familyReport['headers_normalized'], ['family']);
        $sec = $this->headerIndex($familyReport['headers_normalized'], ['secondary_families']);
        $ni = $this->headerIndex($familyReport['headers_normalized'], ['name']);

        foreach ($familyRows as $row) {
            $id = $pid === null ? 0 : (int) trim((string) ($row[$pid] ?? '0'));
            if ($id <= 0) {
                continue;
            }
            $primary = $fi === null ? 'Unmapped' : trim((string) ($row[$fi] ?? ''));
            if ($primary === '') {
                $primary = 'Unmapped';
            }
            $secondary = [];
            if ($sec !== null) {
                foreach (preg_split('/;\s*/', (string) ($row[$sec] ?? '')) ?: [] as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $secondary[] = $part;
                    }
                }
            }
            $this->productFamilies[$id] = [
                'family' => $primary,
                'secondary' => $secondary,
                'name' => $ni === null ? '' : trim((string) ($row[$ni] ?? '')),
            ];
        }
        $familyReport['loaded_rows'] = count($this->productFamilies);
        $invalidProducts = [];
        $cookingPrimary = [];
        foreach ($this->productFamilies as $id => $mapping) {
            $primary = $mapping['family'] ?? '';
            if ($primary === 'Cooking') {
                $cookingPrimary[] = $id.' '.($mapping['name'] ?? '');
            } elseif ($primary !== '' && $primary !== 'Unmapped' && ! isset($allowed[$primary])) {
                $invalidProducts[] = $id.' '.($mapping['name'] ?? '').' → '.$primary;
            }
        }

        $this->evalDebug = [
            'gold' => $goldReport,
            'family' => $familyReport,
            'gold_matched' => 0,
            'unlabeled' => [],
            'n_excluded' => 0,
            'unmapped_products' => [],
            'gold_query_lines' => $goldQueryLines,
            'invalid_gold_families' => $invalidGold,
            'invalid_product_families' => $invalidProducts,
            'cooking_as_primary' => $cookingPrimary,
        ];
    }

    /**
     * @return array{n: int, kappa: float}
     */
    private function graderKappa(): array
    {
        $a = [];
        $b = [];
        foreach ($this->goldByQuery as $row) {
            if (($row['status'] ?? '') === 'no_relevant_item') {
                continue;
            }
            $gold = $row['gold_family'] ?? null;
            $second = $row['second_grader_family'] ?? null;
            if (! is_string($gold) || $gold === '' || ! is_string($second) || $second === '') {
                continue;
            }
            $a[] = $gold;
            $b[] = $second;
        }

        return [
            'n' => count($a),
            'kappa' => RankingLabMetrics::cohenKappa($a, $b),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $runs
     * @return array<int, string>
     */
    private function matrixLabels(array $runs): array
    {
        $labels = self::FAMILIES;
        $needsUnmapped = false;
        foreach ($runs as $run) {
            foreach ($run['top1'] ?? [] as $pred) {
                if ($pred === 'Unmapped') {
                    $needsUnmapped = true;
                    break 2;
                }
            }
            foreach ($run['rows'] ?? [] as $row) {
                if (($row['family'] ?? null) === 'Unmapped') {
                    $needsUnmapped = true;
                    break 2;
                }
            }
        }
        if ($needsUnmapped) {
            $labels[] = 'Unmapped';
        }

        return $labels;
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<string, string>
     */
    private function classMap(array $labels): array
    {
        $map = [];
        foreach ($labels as $label) {
            $map[$label] = $label;
        }

        return $map;
    }

    private function goldFile(): string
    {
        return $this->goldResolvedPath ?? $this->resolveEvalPath('gold_queries.csv')[0];
    }

    private function familyFile(): string
    {
        return $this->familyResolvedPath ?? $this->resolveEvalPath('product_family.csv')[0];
    }

    /**
     * @return array<int, string>
     */
    private function evalDirectories(): array
    {
        $configured = config('ranking-lab.eval_directories');
        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter(array_map(
                fn ($dir) => rtrim((string) $dir, '/\\'),
                $configured,
            )));
        }

        $write = rtrim((string) config('ranking-lab.eval_write_dir', storage_path('app/private/eval')), '/\\');

        return array_values(array_unique([
            $write,
            storage_path('app/eval'),
            storage_path('app/private/eval'),
            storage_path('app/public/eval'),
        ]));
    }

    /**
     * @return array{0: string, 1: array<int, array{path: string, exists: bool}>}
     */
    private function resolveEvalPath(string $filename): array
    {
        $candidates = [];
        foreach ($this->evalDirectories() as $dir) {
            $candidates[] = $dir.DIRECTORY_SEPARATOR.$filename;
        }
        $listed = [];
        foreach ($candidates as $path) {
            $listed[] = ['path' => $path, 'exists' => is_readable($path)];
        }
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return [$path, $listed];
            }
        }

        return [$candidates[0], $listed];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<int, string>>}
     */
    private function readCsv(string $path): array
    {
        $report = [
            'path' => $path,
            'exists' => is_file($path),
            'readable' => is_readable($path),
            'bom' => false,
            'delimiter' => ',',
            'headers_raw' => [],
            'headers_visible' => [],
            'headers_normalized' => [],
            'row_count' => 0,
            'preview' => [],
            'loaded_rows' => 0,
        ];
        if (! is_readable($path)) {
            return [$report, []];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [$report, []];
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
            $report['bom'] = true;
        }

        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $headerLine = strstr($contents, "\n", true);
        if ($headerLine === false) {
            $headerLine = $contents;
        }
        $report['delimiter'] = substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $rawHeader = fgetcsv($handle, 0, $report['delimiter']) ?: [];
        $report['headers_raw'] = $rawHeader;
        $report['headers_visible'] = array_map(fn ($h) => $this->visibleChars((string) $h), $rawHeader);
        $report['headers_normalized'] = array_map(fn ($h) => $this->normalizeHeader((string) $h), $rawHeader);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $report['delimiter'])) !== false) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }
            $rows[] = array_map(fn ($cell) => trim((string) $cell), $row);
        }
        fclose($handle);

        $report['row_count'] = count($rows);
        $report['preview'] = array_slice($rows, 0, 3);

        return [$report, $rows];
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string>  $aliases
     */
    private function headerIndex(array $headers, array $aliases): ?int
    {
        foreach ($headers as $i => $name) {
            if (in_array($name, $aliases, true)) {
                return (int) $i;
            }
        }

        return null;
    }

    private function normalizeHeader(string $header): string
    {
        $header = str_replace("\u{FEFF}", '', $header);
        $header = trim($header);
        $header = Str::lower($header);
        $header = preg_replace('/[\s\-]+/', '_', $header) ?? $header;

        return $header;
    }

    private function visibleChars(string $value): string
    {
        $out = '';
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $char) {
            $ord = mb_ord($char, 'UTF-8');
            if ($char === ' ') {
                $out .= '·';
            } elseif ($ord === 0xFEFF) {
                $out .= '<BOM>';
            } elseif ($ord < 32 || $ord === 127) {
                $out .= sprintf('\\x%02X', $ord);
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $runs
     * @return array<int, array{id: int, name: string}>
     */
    private function uniqueUnmapped(array $runs): array
    {
        $seen = [];
        foreach ($runs as $run) {
            foreach ($run['rows'] ?? [] as $row) {
                if (($row['family'] ?? null) !== 'Unmapped') {
                    continue;
                }
                $id = (int) ($row['id'] ?? 0);
                $seen[$id] = [
                    'id' => $id,
                    'name' => (string) ($row['name'] ?? ''),
                ];
            }
        }

        return array_values($seen);
    }

    private function fileSha256(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        return hash_file('sha256', $path) ?: null;
    }

    private function queryKey(string $query): string
    {
        $query = self::stripListNumber($query);
        $query = Str::lower($query);
        $query = preg_replace('/\s+/u', ' ', $query) ?? $query;

        return trim($query);
    }

    private function nullableCell(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function numericScore(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
