<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EvalLabelService
{
    public const MAX_QUERIES = 150;

    public const MAX_PRODUCTS = 400;

    /** @var array<int, string> */
    public const MILK_NEEDLES = [
        'milk', 'gatas', 'gaas', 'creamer', 'condensada', 'evaporada', 'evaporated',
        'condensed', 'formula', 'gata', 'yogurt', 'yoghurt', 'yakult', 'lactum',
        'anlene', 'ensure', 'glucerna', 'nido', 'chuckie',
    ];

    public function evalDir(): string
    {
        return rtrim((string) config('ranking-lab.eval_write_dir', storage_path('app/private/eval')), '/\\');
    }

    public function goldPath(): string
    {
        return $this->evalDir().DIRECTORY_SEPARATOR.'gold_queries.csv';
    }

    public function familyPath(): string
    {
        return $this->evalDir().DIRECTORY_SEPARATOR.'product_family.csv';
    }

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
     * @param  array<int, string>  $queries
     * @return array<int, array<int, string>>
     */
    public function goldTemplateRows(array $queries): array
    {
        $rows = [];
        foreach ($queries as $i => $query) {
            $rows[] = [
                'q'.($i + 1),
                $query,
                '',
                '',
                'graded',
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function goldHeaders(): array
    {
        return ['query_id', 'query', 'gold_family', 'second_grader_family', 'status'];
    }

    /**
     * @return array<int, string>
     */
    public function familyHeaders(): array
    {
        return ['product_id', 'name', 'family', 'secondary_families', 'note'];
    }

    /**
     * @return Collection<int, Product>
     */
    public function milkProducts(): Collection
    {
        $query = Product::query()
            ->available()
            ->with('category')
            ->orderBy('id');

        $query->where(function ($outer) {
            foreach (self::MILK_NEEDLES as $needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $outer->orWhere('name', 'like', $like)
                    ->orWhere('description', 'like', $like);
            }
        });

        return $query->limit(self::MAX_PRODUCTS)->get();
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function familyTemplateRows(Collection $products): array
    {
        $rows = [];
        foreach ($products as $product) {
            $rows[] = [
                (string) $product->id,
                (string) $product->name,
                '',
                '',
                '',
            ];
        }

        return $rows;
    }

    /**
     * @return array{saved: int, dropped_ai: int, skipped: int, errors: array<int, string>}
     */
    public function storeGoldUpload(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return ['saved' => 0, 'dropped_ai' => 0, 'skipped' => 0, 'errors' => ['Could not read the upload.']];
        }
        $rawHeader = fgetcsv($handle) ?: [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $rawHeader);
        $index = array_flip($header);
        if (! isset($index['query'])) {
            fclose($handle);

            return ['saved' => 0, 'dropped_ai' => 0, 'skipped' => 0, 'errors' => ['CSV must include a query column.']];
        }

        $allowed = array_fill_keys(RankingLabService::FAMILIES, true);
        $rows = [];
        $droppedAi = 0;
        $skipped = 0;
        $errors = [];
        $n = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $assoc = [];
            foreach ($header as $i => $name) {
                $assoc[$name] = trim((string) ($row[$i] ?? ''));
            }
            $query = RankingLabService::stripListNumber($assoc['query'] ?? '');
            $gold = $assoc['gold_family'] ?? '';
            if ($query === '' || $gold === '') {
                $skipped++;
                continue;
            }
            if ($this->looksLikeAiRow($assoc)) {
                $droppedAi++;
                continue;
            }
            if (! isset($allowed[$gold])) {
                $errors[] = 'Invalid gold_family "'.$gold.'" for '.$query.'.';
                continue;
            }
            $status = strtolower($assoc['status'] ?? 'graded');
            if (! in_array($status, ['graded', 'no_relevant_item'], true)) {
                $status = 'graded';
            }
            $n++;
            $assoc['query'] = $query;
            $assoc['gold_family'] = $gold;
            $assoc['status'] = $status;
            if (($assoc['query_id'] ?? '') === '') {
                $assoc['query_id'] = 'q'.$n;
            }
            $rows[] = $assoc;
        }
        fclose($handle);

        if ($rows !== []) {
            $writeHeaders = $this->writeHeaders($rawHeader, $this->goldHeaders(), ['check', 'reason']);
            $this->backupAndWrite($this->goldPath(), $writeHeaders, $rows);
        }

        return ['saved' => count($rows), 'dropped_ai' => $droppedAi, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20)];
    }

    /**
     * @return array{saved: int, dropped_ai: int, skipped: int, errors: array<int, string>}
     */
    public function storeFamilyUpload(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return ['saved' => 0, 'dropped_ai' => 0, 'skipped' => 0, 'errors' => ['Could not read the upload.']];
        }
        $rawHeader = fgetcsv($handle) ?: [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $rawHeader);
        $index = array_flip($header);
        if (! isset($index['product_id'], $index['family'])) {
            fclose($handle);

            return ['saved' => 0, 'dropped_ai' => 0, 'skipped' => 0, 'errors' => ['CSV must include product_id and family.']];
        }

        $allowed = array_fill_keys(RankingLabService::FAMILIES, true);
        $rows = [];
        $droppedAi = 0;
        $skipped = 0;
        $errors = [];
        while (($row = fgetcsv($handle)) !== false) {
            $assoc = [];
            foreach ($header as $i => $name) {
                $assoc[$name] = trim((string) ($row[$i] ?? ''));
            }
            $id = (int) ($assoc['product_id'] ?? 0);
            $family = $assoc['family'] ?? '';
            if ($id <= 0 || $family === '') {
                $skipped++;
                continue;
            }
            if ($this->looksLikeAiRow($assoc)) {
                $droppedAi++;
                continue;
            }
            if (! isset($allowed[$family])) {
                $errors[] = 'Invalid family "'.$family.'" for product '.$id.'.';
                continue;
            }
            if ($family === 'Cooking') {
                $errors[] = 'Product '.$id.': Cooking is not a primary family; put it in secondary_families.';
                continue;
            }
            $assoc['product_id'] = (string) $id;
            $assoc['family'] = $family;
            $rows[] = $assoc;
        }
        fclose($handle);

        if ($rows !== []) {
            $writeHeaders = $this->writeHeaders($rawHeader, $this->familyHeaders());
            $this->backupAndWrite($this->familyPath(), $writeHeaders, $rows);
        }

        return ['saved' => count($rows), 'dropped_ai' => $droppedAi, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20)];
    }

    /**
     * @param  array<string, string>  $row
     */
    public function looksLikeAiRow(array $row): bool
    {
        $blob = Str::lower(implode(' ', [
            $row['reason'] ?? '',
            $row['check'] ?? '',
            $row['note'] ?? '',
            $row['status'] ?? '',
        ]));
        if (preg_match('/\b(ai|llm|chatgpt|gpt|claude|suggested|auto-?label|voc said|generated)\b/u', $blob)) {
            return true;
        }
        $suggested = trim($row['suggested_family'] ?? '');
        $gold = trim($row['gold_family'] ?? $row['family'] ?? '');
        if ($suggested !== '' && ($gold === '' || strcasecmp($suggested, $gold) === 0)) {
            return true;
        }

        return false;
    }

    public function status(): array
    {
        return [
            'gold_path' => $this->goldPath(),
            'family_path' => $this->familyPath(),
            'gold_rows' => $this->countDataRows($this->goldPath()),
            'family_rows' => $this->countDataRows($this->familyPath()),
            'milk_needles' => self::MILK_NEEDLES,
            'families' => RankingLabService::FAMILIES,
            'export_cap' => self::MAX_PRODUCTS,
            'has_gold_file' => is_file($this->goldPath()),
            'has_family_file' => is_file($this->familyPath()),
        ];
    }

    /**
     * @param  array<int, string>  $rawHeader
     * @param  array<int, string>  $required
     * @param  array<int, string>  $drop
     * @return array<int, string>
     */
    private function writeHeaders(array $rawHeader, array $required, array $drop = []): array
    {
        $drop = array_fill_keys(array_map('strtolower', $drop), true);
        $headers = [];
        $seen = [];
        foreach ($rawHeader as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $key = strtolower($name);
            if (isset($drop[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $headers[] = $name;
        }
        foreach ($required as $name) {
            $key = strtolower($name);
            if (! isset($seen[$key])) {
                $headers[] = $name;
            }
        }

        return $headers;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<string, string>>  $rows
     */
    private function backupAndWrite(string $path, array $headers, array $rows): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (is_file($path)) {
            copy($path, $path.'.backup.csv');
        }
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[strtolower($header)] ?? $row[$header] ?? '';
            }
            fputcsv($handle, $line);
        }
        fclose($handle);
    }

    private function countDataRows(string $path): int
    {
        if (! is_readable($path)) {
            return 0;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return max(0, count($lines ?: []) - 1);
    }
}
