<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class RetrainSearch extends Command
{
    protected $signature = 'search:retrain
        {--promote : Copy the candidate into ml/models if tests pass}
        {--skip-pytest : Skip ranker pytest during promote}';

    protected $description = 'Export search feedback, build labels, and optionally train a candidate LambdaMART model';

    public function handle(): int
    {
        $this->call('search:export-feedback');

        $python = $this->python();
        if ($python === null) {
            $this->error('ml/.venv Python was not found.');

            return self::FAILURE;
        }

        $ml = base_path('ml');
        $qrels = Process::path($ml)->run([$python, 'build_feedback_qrels.py']);
        $this->output->write($qrels->output());
        if ($qrels->failed()) {
            $this->error($qrels->errorOutput());

            return self::FAILURE;
        }

        $qrelsPath = base_path('ml/data/feedback/qrels.csv');
        $rows = is_file($qrelsPath) ? max(0, count(file($qrelsPath)) - 1) : 0;
        if ($rows < 1) {
            $this->warn('No feedback judgements yet (need enough sessions/carts). Current ranker is unchanged.');

            return self::SUCCESS;
        }

        $version = now()->utc()->format('Ymd\\THis\\Z');
        $out = base_path('ml/models/candidates/'.$version);
        $this->info("Training candidate {$version} with {$rows} feedback rows");

        $train = Process::path($ml)->timeout(3600)->run([
            $python, 'train.py', '--out-dir', $out, '--with-feedback',
        ]);
        $this->output->write($train->output());
        if ($train->failed()) {
            $this->error($train->errorOutput());

            return self::FAILURE;
        }

        if (! $this->option('promote')) {
            $this->info("Candidate written to {$out}. Re-run with --promote after reviewing eval.");

            return self::SUCCESS;
        }

        $promote = [$python, 'promote.py', '--candidate', $out];
        if ($this->option('skip-pytest')) {
            $promote[] = '--skip-pytest';
        }
        $promoted = Process::path($ml)->timeout(600)->run($promote);
        $this->output->write($promoted->output());
        if ($promoted->failed()) {
            $this->error($promoted->errorOutput());

            return self::FAILURE;
        }

        $this->warn('Restart the uvicorn process on port 8088 so it loads the promoted artifacts.');

        return self::SUCCESS;
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
