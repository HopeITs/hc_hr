<?php

namespace App\Console\Commands;

use App\Enums\ContractType as ContractTypeEnum;
use App\Models\Contract;
use App\Models\Position;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recompute `positions.exist_emp` from the contracts that hold a seat: active
 * and not a draft — the same rule ContractObserver keeps it to.
 *
 * Needed once after the E4 fix (journeys.md §3.3): before it, terminating a
 * contract switched it inactive without freeing its seat, so a position HR had
 * terminated anyone from still reads fuller than it is and canAssign() can
 * refuse a hire. Idempotent: a second run reports nothing. Unlike
 * positions:fix-counts it never touches max_emp and does not count drafts.
 */
class RecountPositionSeats extends Command
{
    protected $signature = 'positions:recount-seats {--dry-run : Report the differences without writing them}';

    protected $description = 'Recompute exist_emp from active non-draft contracts and print every position that was off';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;

        Position::query()->select('id')->orderBy('id')->chunkById(200, function ($positions) use ($dryRun, &$changed): void {
            foreach ($positions as $row) {
                $diff = DB::transaction(function () use ($row, $dryRun): ?array {
                    $position = Position::query()->whereKey($row->id)->lockForUpdate()->first();

                    if (! $position) {
                        return null;
                    }

                    $actual = Contract::query()
                        ->where('position_id', $position->id)
                        ->where('is_active', true)
                        ->where('type', '!=', ContractTypeEnum::Draft)
                        ->count();

                    $stored = (int) $position->exist_emp;

                    if ($stored === $actual) {
                        return null;
                    }

                    if (! $dryRun) {
                        $position->update(['exist_emp' => $actual]);
                    }

                    return [$position->id, $stored, $actual, (int) $position->max_emp];
                });

                if ($diff) {
                    [$id, $stored, $actual, $max] = $diff;
                    $changed++;
                    $this->line("Position #{$id}: exist_emp {$stored} -> {$actual} (max_emp {$max})");
                }
            }
        });

        $this->info($dryRun
            ? "Dry run: {$changed} position(s) would change. Nothing was written."
            : "{$changed} position(s) corrected.");

        return self::SUCCESS;
    }
}
