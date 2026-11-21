<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Search\EvalLabelService;
use App\Services\Search\RankingLabService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RankingLabController extends Controller
{
    public function __construct(
        private readonly RankingLabService $rankingLab,
        private readonly EvalLabelService $evalLabels,
    ) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:20000'],
            'limit' => ['nullable', 'integer', 'min:8', 'max:80'],
        ]);

        $queries = $this->queriesFrom($validated['q'] ?? '');
        $limit = (int) ($validated['limit'] ?? 32);
        if ($queries !== []) {
            set_time_limit(180);
        }
        $lab = $queries === []
            ? [
                'technologies' => RankingLabService::TECHNOLOGIES,
                'classes' => array_combine(RankingLabService::FAMILIES, RankingLabService::FAMILIES),
                'runs' => [],
                'matrices' => [],
                'unlabeled' => [],
                'n_excluded' => 0,
                'kappa' => ['n' => 0, 'kappa' => 0.0],
                'eval' => $this->rankingLab->describeEvalFiles(),
            ]
            : $this->rankingLab->evaluate($queries, $limit);

        if (empty($lab['eval'])) {
            $lab['eval'] = $this->rankingLab->describeEvalFiles();
        }

        if ($queries !== []) {
            $this->writeScoresCsv($lab);
            $this->writeMatricesCsv($lab);
        }

        $rawQuery = $validated['q'] ?? '';
        if ($rawQuery === '' && $request->isMethod('get')) {
            $rawQuery = implode("\n", $lab['eval']['gold_query_lines'] ?? []);
        }

        return view('admin.ranking-lab.index', [
            'rawQuery' => $rawQuery,
            'limit' => $limit,
            'lab' => $lab,
            'hasScoresExport' => is_file($this->scoresPath()),
            'hasMatricesExport' => is_file($this->matricesPath()),
            'evalLabels' => array_merge($this->evalLabels->status(), [
                'has_gold_file' => ! empty($lab['eval']['gold']['readable']),
                'has_family_file' => ! empty($lab['eval']['family']['readable']),
                'gold_path' => $lab['eval']['gold']['path'] ?? $this->evalLabels->goldPath(),
                'family_path' => $lab['eval']['family']['path'] ?? $this->evalLabels->familyPath(),
                'gold_rows' => $lab['eval']['gold']['loaded_rows'] ?? 0,
                'family_rows' => $lab['eval']['family']['loaded_rows'] ?? 0,
            ]),
        ]);
    }

    public function export(): BinaryFileResponse
    {
        $path = $this->scoresPath();
        abort_unless(is_file($path), 404, 'Run the ranking lab first, then download scores.');

        return response()->download($path, 'ranking-lab-scores.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportMatrices(): BinaryFileResponse
    {
        $path = $this->matricesPath();
        abort_unless(is_file($path), 404, 'Run the ranking lab first, then download matrices.');

        return response()->download($path, 'ranking-lab-matrices.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $lab
     */
    private function writeScoresCsv(array $lab): void
    {
        $path = $this->scoresPath();
        $out = fopen($path, 'w');
        $techKeys = array_keys($lab['technologies'] ?? RankingLabService::TECHNOLOGIES);
        fputcsv($out, array_merge(
            ['query', 'query_id', 'gold_family', 'status', 'live_rank', 'product_id', 'name', 'family', 'secondary_families', 'store', 'category', 'price'],
            array_map(fn (string $key) => 'score_'.$key, $techKeys),
            array_map(fn (string $key) => 'rank_'.$key, $techKeys),
        ));

        foreach ($lab['runs'] as $run) {
            foreach ($run['rows'] as $row) {
                $line = [
                    $run['query'],
                    $run['query_id'] ?? '',
                    $run['gold'] ?? '',
                    $run['status'] ?? '',
                    $row['live_rank'],
                    $row['id'],
                    $row['name'],
                    $row['family'] ?? '',
                    implode('; ', $row['secondary_families'] ?? []),
                    $row['store'],
                    $row['category'],
                    $row['price'],
                ];
                foreach ($techKeys as $key) {
                    $line[] = $row['scores'][$key] ?? '';
                }
                foreach ($techKeys as $key) {
                    $line[] = $row['ranks'][$key] ?? '';
                }
                fputcsv($out, $line);
            }
        }

        fclose($out);
    }

    /**
     * @param  array<string, mixed>  $lab
     */
    private function writeMatricesCsv(array $lab): void
    {
        $path = $this->matricesPath();
        $out = fopen($path, 'w');
        $classes = array_keys($lab['classes'] ?? []);
        if ($classes === []) {
            $classes = RankingLabService::FAMILIES;
        }

        fputcsv($out, array_merge(['scorer', 'gold'], $classes, ['Total']));

        foreach ($lab['matrices'] as $tech => $matrix) {
            $label = $lab['technologies'][$tech] ?? $tech;
            foreach ($classes as $gold) {
                $line = [$label, $gold];
                foreach ($classes as $pred) {
                    $line[] = $matrix['grid'][$gold][$pred] ?? 0;
                }
                $line[] = $matrix['row_totals'][$gold] ?? array_sum($matrix['grid'][$gold] ?? []);
                fputcsv($out, $line);
            }
            $totals = [$label, 'Total'];
            foreach ($classes as $pred) {
                $totals[] = $matrix['col_totals'][$pred] ?? 0;
            }
            $totals[] = $matrix['n'] ?? 0;
            fputcsv($out, $totals);
        }

        fclose($out);
    }

    private function scoresPath(): string
    {
        return $this->runDir().DIRECTORY_SEPARATOR.'scores.csv';
    }

    private function matricesPath(): string
    {
        return $this->runDir().DIRECTORY_SEPARATOR.'matrices.csv';
    }

    private function runDir(): string
    {
        $dir = storage_path('app/eval/runs/'.(Auth::id() ?? 'guest'));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    /**
     * @return array<int, string>
     */
    private function queriesFrom(string $raw): array
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
            if (count($queries) >= 150) {
                break;
            }
        }

        return array_values($queries);
    }
}
