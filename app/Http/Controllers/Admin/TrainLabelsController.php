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
            'trainQueries' => $this->labels->trainQueriesFromFile(),
        ]);
    }

    public function downloadExisting(): StreamedResponse
    {
        $rows = $this->labels->existingJudgmentRows();
        abort_if($rows === [], 422, 'No training qrels yet. Grade some queries first, or copy an upload into qrels.');

        return $this->judgmentDownload('train-qrels-all-queries.csv', $this->labels->judgmentHeaders(), $rows);
    }

    public function download(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:20000'],
            'query_source' => ['required', 'in:pasted,all_train'],
            'sku_scope' => ['required', 'in:all,contains,category'],
            'contains' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);
        $pasted = $this->labels->queriesFrom((string) ($validated['q'] ?? ''));
        $queries = $pasted !== []
            ? $pasted
            : ($validated['query_source'] === 'all_train'
                ? array_column($this->labels->trainQueriesFromFile(), 'query')
                : []);
        abort_if($queries === [], 422, 'Paste at least one query, or clear the box and choose all train queries from queries.csv.');
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

        return $this->judgmentDownload(
            'train-judgment.csv',
            $this->labels->judgmentHeaders(),
            $this->labels->judgmentRows($queries, $products),
        );
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

        $msg = 'Merged '.$result['incoming'].' graded rows into staging ('.$result['qrels'].' pairs, '.$result['queries'].' queries; skipped '.$result['skipped'].' blank). Other queries already in staging were kept. Live ranker.txt was not changed.';
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
        $train = Process::path(base_path('ml'))
            ->timeout(3600)
            ->env($this->windowsProcessEnv())
            ->run([$python, 'train.py', '--out-dir', $out]);
        if ($train->failed()) {
            $err = trim($train->errorOutput().' '.$train->output());
            if (str_contains($err, '10106') || str_contains($err, '_overlapped')) {
                return back()->withErrors(['train' => 'Windows blocked Python when started from this page (Winsock). In Git Bash, from the ml folder, run: .venv/Scripts/python.exe train.py --out-dir models/candidates/'.$version.'  Then click Promote candidate to live.']);
            }

            return back()->withErrors(['train' => 'Training failed: '.$err]);
        }

        return back()->with('status', 'Candidate written to '.$out.'. Live ml/models/ranker.txt was not overwritten. Click Promote candidate, then restart uvicorn.');
    }

    public function promoteCandidate(): RedirectResponse
    {
        $result = $this->labels->promoteLatestCandidate();
        if (! ($result['ok'] ?? false)) {
            return back()->withErrors(['promote' => $result['message'] ?? 'Could not promote.']);
        }

        return back()->with('status', $result['message']);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function judgmentDownload(string $filename, array $headers, array $rows): StreamedResponse
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

    /**
     * @return array<string, string>
     */
    private function windowsProcessEnv(): array
    {
        $root = getenv('SYSTEMROOT') ?: (getenv('SystemRoot') ?: 'C:\\Windows');

        return [
            'SYSTEMROOT' => $root,
            'SystemRoot' => $root,
            'WINDIR' => getenv('WINDIR') ?: $root,
            'COMSPEC' => getenv('COMSPEC') ?: $root.'\\system32\\cmd.exe',
            'PATH' => getenv('PATH') ?: $root.'\\system32',
            'TEMP' => getenv('TEMP') ?: sys_get_temp_dir(),
            'TMP' => getenv('TMP') ?: sys_get_temp_dir(),
            'PATHEXT' => getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD',
            'USERPROFILE' => getenv('USERPROFILE') ?: '',
            'HOMEDRIVE' => getenv('HOMEDRIVE') ?: '',
            'HOMEPATH' => getenv('HOMEPATH') ?: '',
        ];
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
