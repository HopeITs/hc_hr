<?php

namespace App\Console\Commands;

use App\Enums\LeaveTypeCases;
use App\Models\Contract;
use App\Services\LeaveBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecalculateLeaveBalances extends Command
{
    protected $signature = 'leaves:recalculate
        {--dry-run : Show the annual balance each contract would move to, without writing anything}
        {--contract=* : Limit to specific contract IDs}
        {--employee=* : Limit to the contracts of specific employee IDs}
        {--active : Only process active contracts}
        {--changed-only : Only list contracts whose annual balance actually changes}';

    protected $description = 'Reconcile the leave balances of contracts that already have them, after a change to the entitlement rules (e.g. seniority now running from employees.joined_at).';

    public function handle(LeaveBalanceService $service): int
    {
        // Contracts with no balances at all are the job of leaves:backfill —
        // this command only re-reconciles the ones that already have them.
        $query = Contract::query()
            ->has('leaveBalances')
            ->with('employee');

        if ($ids = $this->option('contract')) {
            $query->whereIn('id', $ids);
        }

        if ($employeeIds = $this->option('employee')) {
            $query->whereIn('employee_id', $employeeIds);
        }

        if ($this->option('active')) {
            $query->where('is_active', true);
        }

        // Carry-over reads the preceding contract's annual balance, so each
        // employee's contracts must be reconciled oldest first.
        $contracts = $query->orderBy('employee_id')->orderBy('start_date')->orderBy('id')->get();

        if ($contracts->isEmpty()) {
            $this->info('No contracts matched. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Reconciling {$contracts->count()} contract(s).");

        $dryRun = $this->option('dry-run');
        $changedOnly = $this->option('changed-only');

        $rows = [];
        $changed = 0;
        $processed = 0;
        $failures = [];
        $bar = $this->output->createProgressBar($contracts->count());
        $bar->start();

        foreach ($contracts as $contract) {
            $before = $this->annualDeserved($contract);

            try {
                DB::beginTransaction();

                $service->update($contract);
                $after = $this->annualDeserved($contract->fresh('leaveBalances'));

                // A dry run still runs the real service, then throws the write
                // away — the reported figures are exactly what an apply writes.
                $dryRun ? DB::rollBack() : DB::commit();

                $processed++;
            } catch (Throwable $e) {
                DB::rollBack();
                $failures[] = [$contract->id, $contract->employee?->name ?? '—', $e->getMessage()];
                $bar->advance();

                continue;
            }

            $hasChanged = $before !== null && $after !== null
                ? abs($after - $before) > 0.001
                : $before !== $after;

            if ($hasChanged) {
                $changed++;
            }

            if (! $changedOnly || $hasChanged) {
                $rows[] = [
                    $contract->id,
                    $contract->employee?->name ?? '—',
                    $contract->employee?->joined_at?->toDateString() ?? '—',
                    $contract->start_date?->toDateString() ?? '—',
                    $contract->employee?->years_of_service ?? '—',
                    $this->format($before),
                    $this->format($after),
                    $hasChanged ? '←' : '',
                ];
            }
        }

        $bar->finish();
        $this->newLine(2);

        if ($rows) {
            $this->table(
                ['Contract', 'Employee', 'Joined', 'Contract start', 'Years', 'Annual before', 'Annual after', ''],
                $rows,
            );
        }

        $this->info(sprintf(
            '%s %d contract(s) reconciled, %d with a changed annual balance.',
            $dryRun ? 'Dry run —' : 'Done.',
            $processed,
            $changed,
        ));

        if ($failures) {
            $this->newLine();
            $this->warn(count($failures).' contract(s) could not be reconciled and were left untouched:');
            $this->table(['Contract', 'Employee', 'Reason'], $failures);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function annualDeserved(Contract $contract): ?float
    {
        $deserved = $contract->leaveBalances
            ->where('type_id', LeaveTypeCases::Annual)
            ->first()?->deserved;

        return $deserved === null ? null : (float) $deserved;
    }

    private function format(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
