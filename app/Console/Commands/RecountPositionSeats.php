<?php

namespace App\Console\Commands;

use App\Enums\ContractType as ContractTypeEnum;
use App\Models\Contract;
use App\Models\Position;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recompute `positions.exist_emp` from the contracts that hold a seat: active
 * and not a draft — the same rule ContractObserver keeps it to.
 *
 * Needed once after the E4 fix (journeys.md §3.3): before it, terminating a
 * contract switched it inactive without freeing its seat, so a position HR had
 * terminated anyone from still reads fuller than it is and canAssign() can
 * refuse a hire. Idempotent: a second run reports nothing. Unlike
 * positions:fix-counts it never touches max_emp and does not count drafts.
 *
 * `--check` is the weekly guard (routes/console.php): it writes nothing, lists
 * the positions whose counter is off and those holding more people than
 * max_emp, logs them, and exits 1 when there is any. The observer keeps the
 * counter right for every write that goes through Eloquent; what it cannot see
 * is a bulk import or a raw query (position 62 and 88 on hr_db came from the
 * June import), and the capacity check is skipped in the console by design.
 */
class RecountPositionSeats extends Command
{
    protected $signature = 'positions:recount-seats
        {--dry-run : Report the differences without writing them}
        {--check : Write nothing; also list positions over max_emp, log them, and exit 1 when anything is off}';

    protected $description = 'Recompute exist_emp from active non-draft contracts and print every position that was off';

    public function handle(): int
    {
        $check = (bool) $this->option('check');
        $dryRun = (bool) $this->option('dry-run') || $check;
        $changed = 0;
        $drifted = [];
        $overCapacity = [];

        Position::query()->select('id')->orderBy('id')->chunkById(200, function ($positions) use ($dryRun, $check, &$changed, &$drifted, &$overCapacity): void {
            foreach ($positions as $row) {
                $diff = DB::transaction(function () use ($row, $dryRun, $check, &$overCapacity): ?array {
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

                    if ($check && $actual > (int) $position->max_emp) {
                        $overCapacity[] = [$position->id, $actual, (int) $position->max_emp];
                    }

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
                    $drifted[] = $diff;
                    $this->line("Position #{$id}: exist_emp {$stored} -> {$actual} (max_emp {$max})");
                }
            }
        });

        if ($check) {
            return $this->reportCheck($drifted, $overCapacity);
        }

        $this->info($dryRun
            ? "Dry run: {$changed} position(s) would change. Nothing was written."
            : "{$changed} position(s) corrected.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $drifted  id, stored, actual, max
     * @param  list<array{0: int, 1: int, 2: int}>  $overCapacity  id, actual, max
     */
    private function reportCheck(array $drifted, array $overCapacity): int
    {
        foreach ($overCapacity as [$id, $actual, $max]) {
            $this->line("Position #{$id}: {$actual} people hold it, max_emp {$max} (over capacity)");
        }

        if ($drifted === [] && $overCapacity === []) {
            $this->info('Every seat counter matches its contracts and no position is over capacity.');

            return self::SUCCESS;
        }

        Log::warning('positions:recount-seats --check found positions that disagree with their contracts', [
            'counter_off' => array_map(fn (array $d) => ['position' => $d[0], 'stored' => $d[1], 'actual' => $d[2], 'max_emp' => $d[3]], $drifted),
            'over_capacity' => array_map(fn (array $o) => ['position' => $o[0], 'actual' => $o[1], 'max_emp' => $o[2]], $overCapacity),
        ]);

        $this->error(count($drifted).' counter(s) off, '.count($overCapacity).' position(s) over capacity. Nothing was written; run without --check to repair the counters.');

        return self::FAILURE;
    }
}
