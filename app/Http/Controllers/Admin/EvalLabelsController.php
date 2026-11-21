<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Search\EvalLabelService;
use App\Services\Search\RankingLabService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvalLabelsController extends Controller
{
    public function __construct(
        private readonly EvalLabelService $labels,
        private readonly RankingLabService $rankingLab,
    ) {}

    public function index(): RedirectResponse
    {
        return $this->rankingLabRedirect();
    }

    public function downloadGold(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:20000'],
        ]);
        $queries = $this->labels->queriesFrom($validated['q']);
        abort_if($queries === [], 422, 'Enter at least one query, one per line.');

        $headers = $this->labels->goldHeaders();
        $rows = $this->labels->goldTemplateRows($queries);

        return $this->csvDownload('gold_queries.csv', $headers, $rows);
    }

    public function downloadCurrentGold(): BinaryFileResponse
    {
        return $this->downloadResolved('gold', 'gold_queries.csv');
    }

    public function downloadCurrentFamily(): BinaryFileResponse
    {
        return $this->downloadResolved('family', 'product_family.csv');
    }

    public function downloadFamily(): StreamedResponse
    {
        $products = $this->labels->milkProducts();
        abort_if($products->isEmpty(), 422, 'No in-stock milk-like products found.');

        return $this->csvDownload(
            'product_family.csv',
            $this->labels->familyHeaders(),
            $this->labels->familyTemplateRows($products),
        );
    }

    public function uploadGold(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gold' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);
        $result = $this->labels->storeGoldUpload($validated['gold']);

        return $this->uploadRedirect($result, 'gold queries');
    }

    public function uploadFamily(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'family' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);
        $result = $this->labels->storeFamilyUpload($validated['family']);

        return $this->uploadRedirect($result, 'product families');
    }

    /**
     * @param  array{saved: int, dropped_ai: int, skipped: int, errors: array<int, string>}  $result
     */
    private function uploadRedirect(array $result, string $what): RedirectResponse
    {
        if ($result['saved'] === 0 && $result['errors'] !== []) {
            return $this->rankingLabRedirect()->withErrors(['upload' => $result['errors'][0]]);
        }
        $msg = 'Saved '.$result['saved'].' '.$what.' (skipped '.$result['skipped'].' blank, dropped '.$result['dropped_ai'].' AI-looking rows). Previous file kept as .backup.csv. Ranking was not changed.';
        if ($result['errors'] !== []) {
            $msg .= ' Notes: '.implode(' ', $result['errors']);
        }

        return $this->rankingLabRedirect()->with('status', $msg);
    }

    private function rankingLabRedirect(): RedirectResponse
    {
        return redirect()->route('admin.ranking-lab.index')->withFragment('eval-labels');
    }

    private function downloadResolved(string $key, string $filename): BinaryFileResponse
    {
        $eval = $this->rankingLab->describeEvalFiles();
        $path = $eval[$key]['path'] ?? '';
        abort_unless(is_string($path) && is_readable($path) && is_file($path), 404, 'No '.$filename.' on disk yet. Upload one first, or download a blank template.');

        return response()->download($path, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function csvDownload(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
