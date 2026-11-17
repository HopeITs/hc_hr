<?php

namespace App\Console\Commands;

use App\Models\RecruitmentApplication;
use App\Services\RecruitmentMatchingIndicators;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Saves matching-indicator snapshots for reporting, each with the criteria
 * version it was computed under.
 *
 * Reads applications and writes only snapshot rows: no status, decision or
 * tag is touched, whatever the indicators say.
 */
class SnapshotRecruitmentIndicators extends Command
{
    protected $signature = 'recruitment:indicators:snapshot
        {--campaign=* : Only applications in these campaign ids}
        {--stale : Only applications with no snapshot under the current criteria}
        {--chunk=200 : Applications per batch}';

    protected $description = 'Save explainable matching-indicator snapshots with their criteria version';

    public function handle(RecruitmentMatchingIndicators $indicators): int
    {
        $version = $indicators->criteriaVersion();
        $chunk = max(1, min(1000, (int) $this->option('chunk')));
        $campaigns = array_filter(array_map('intval', (array) $this->option('campaign')));

        $query = RecruitmentApplication::query()
            ->when($campaigns !== [], fn ($query) => $query->whereIn('campaign_id', $campaigns))
            ->when($this->option('stale'), fn ($query) => $query->whereDoesntHave(
                'indicatorSnapshot',
                fn ($snapshots) => $snapshots->where('criteria_version', $version),
            ));

        $saved = 0;
        $query->chunkById($chunk, function (Collection $applications) use ($indicators, &$saved): void {
            $saved += count($indicators->snapshotMany($applications));
        });

        $this->info("Saved {$saved} snapshot(s) under criteria {$version}.");

        return self::SUCCESS;
    }
}
