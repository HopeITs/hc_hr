<?php

namespace App\Console\Commands;

use App\Services\Assistant\DrivePolicySync;
use Illuminate\Console\Command;

/**
 * Refreshes the assistant's copy of the policies from the Drive folder.
 * Scheduled daily in routes/console.php; a file that did not change since the
 * last run is not sent to Gemini again, so a daily run costs next to nothing.
 */
class SyncPolicies extends Command
{
    protected $signature = 'policies:sync
        {--folder= : Drive folder id, defaults to config}
        {--force : Read every file again, changed or not}';

    protected $description = 'Copy the policies in the Google Drive folder into policy_documents as plain text';

    public function handle(DrivePolicySync $sync): int
    {
        $folderId = $this->option('folder') ?: config('assistant.policies.drive_folder_id');

        $missing = array_keys(array_filter([
            'POLICIES_DRIVE_FOLDER_ID (or --folder)' => blank($folderId),
            'GOOGLE_DRIVE_API_KEY' => blank(config('services.google.drive_api_key')),
            'GEMINI_API_KEY' => blank(config('assistant.gemini.key')),
        ]));

        if ($missing) {
            $this->error('Not configured: '.implode(', ', $missing).' is not set.');

            return self::FAILURE;
        }

        $summary = $sync->run($folderId, (bool) $this->option('force'));

        $this->table(
            ['Added', 'Updated', 'Unchanged', 'Skipped', 'Removed', 'Failed'],
            [[$summary['added'], $summary['updated'], $summary['unchanged'], $summary['skipped'], $summary['removed'], $summary['failed']]],
        );

        foreach ($summary['failures'] as $failure) {
            $this->warn("{$failure['title']}: {$failure['reason']}");
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
