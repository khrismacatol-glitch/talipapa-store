<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Search\HumanTrainLabelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrainLabelsController extends Controller
{
    public function __construct(
        private readonly HumanTrainLabelService $labels,
    ) {}

    public function index(): View
    {
        return view('admin.train-labels.index', [
            'status' => $this->labels->status(),
            'intents' => HumanTrainLabelService::INTENTS,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:8000'],
            'sku_scope' => ['required', 'in:all,contains,category'],
            'contains' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);
        $queries = $this->labels->queriesFrom($validated['q']);
        abort_if($queries === [], 422, 'Enter at least one query, one per line.');
        abort_if(
            $validated['sku_scope'] === 'contains'
                && $this->labels->containsNeedles((string) ($validated['contains'] ?? '')) === [],
            422,
            'Enter a word for name or description (comma-separated is OK), or choose All SKUs.',
        );
        abort_if(
            $validated['sku_scope'] === 'category' && empty($validated['category_id']),
            422,
            'Choose a category, or pick All SKUs.',
        );

        $products = $this->labels->catalogProducts(
            $validated['sku_scope'],
            (string) ($validated['contains'] ?? ''),
            isset($validated['category_id']) ? (int) $validated['category_id'] : null,
        );
        $rows = $this->labels->judgmentRows($queries, $products);
        $headers = $this->labels->judgmentHeaders();

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'train-judgment.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'grades' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);
        $result = $this->labels->storeUpload($validated['grades']);
        if ($result['qrels'] === 0 && $result['errors'] !== []) {
            return back()->withErrors(['grades' => $result['errors'][0]]);
        }

        $msg = 'Saved '.$result['qrels'].' graded pairs and '.$result['queries'].' queries (skipped '.$result['skipped'].' blank grades). Live ranker.txt was not changed.';
        if ($result['errors'] !== []) {
            $msg .= ' Notes: '.implode(' ', $result['errors']);
        }

        return back()->with('status', $msg);
    }

    public function apply(): RedirectResponse
    {
        $result = $this->labels->applyToTrainingFiles();
        if (! ($result['ok'] ?? false)) {
            return back()->withErrors(['apply' => $result['message'] ?? 'Could not apply grades.']);
        }

        return back()->with('status', $result['message']);
    }

    public function trainCandidate(): RedirectResponse
    {
        $python = $this->python();
        if ($python === null) {
            return back()->withErrors(['train' => 'ml/.venv Python was not found.']);
        }

        $applied = $this->labels->applyToTrainingFiles();
        if (! ($applied['ok'] ?? false)) {
            return back()->withErrors(['train' => $applied['message'] ?? 'Upload grades first.']);
        }

        $version = now()->utc()->format('Ymd\\THis\\Z');
        $out = base_path('ml/models/candidates/'.$version);
        set_time_limit(3600);
        $train = Process::path(base_path('ml'))->timeout(3600)->run([
            $python, 'train.py', '--out-dir', $out,
        ]);
        if ($train->failed()) {
            return back()->withErrors(['train' => 'Training failed: '.$train->errorOutput()]);
        }

        return back()->with('status', 'Candidate written to '.$out.'. Live ml/models/ranker.txt was not overwritten. Restart uvicorn only after you copy/promote this candidate.');
    }

    private function python(): ?string
    {
        $windows = base_path('ml/.venv/Scripts/python.exe');
        $unix = base_path('ml/.venv/bin/python');

        return match (true) {
            is_file($windows) => $windows,
            is_file($unix) => $unix,
            default => null,
        };
    }
}
