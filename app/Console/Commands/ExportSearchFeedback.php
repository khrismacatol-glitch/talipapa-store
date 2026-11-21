<?php

namespace App\Console\Commands;

use App\Services\Search\SearchFeedbackService;
use Illuminate\Console\Command;

class ExportSearchFeedback extends Command
{
    protected $signature = 'search:export-feedback {--path=ml/data/feedback/export.json}';

    protected $description = 'Export hashed search sessions, impressions, and events for ML label building';

    public function handle(SearchFeedbackService $feedback): int
    {
        $path = base_path($this->option('path'));
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = $feedback->exportPayload();
        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $this->info('Exported '.count($payload['sessions']).' search sessions to '.$path);

        return self::SUCCESS;
    }
}
