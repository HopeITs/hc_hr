<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\LeaveBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillLeaveBalances extends Command
{
    protected $signature = 'leaves:backfill
        {--dry-run : Show which contracts would be processed without writing anything}
        {--contract=* : Limit to specific contract IDs}
        {--active : Only process active contracts}';

    protected $description = 'Generate the deserved leave balances for contracts that have none (e.g. contracts imported from Excel).';

    public function handle(LeaveBalanceService $service): int
    {
        // Target only contracts that have no leave balances yet, so re-running
        // the command is safe and never duplicates existing balances.
        $query = Contract::query()
            ->doesntHave('leaveBalances')
            ->with('employee');

        if ($ids = $this->option('contract')) {
            $query->whereIn('id', $ids);
        }

        if ($this->option('active')) {
            $query->where('is_active', true);
        }

        // Carry-over reads the preceding contract's annual balance, so each
        // employee's contracts must be generated oldest first.
        $contracts = $query->orderBy('employee_id')->orderBy('start_date')->orderBy('id')->get();

        if ($contracts->isEmpty()) {
            $this->info('No contracts are missing leave balances. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Found {$contracts->count()} contract(s) without leave balances.");

        if ($this->option('dry-run')) {
            $this->table(
                ['Contract ID', 'Employee', 'Type', 'Duration (months)'],
                $contracts->map(fn (Contract $c) => [
                    $c->id,
                    $c->employee?->name ?? '—',
                    $c->contract_type_id,
                    $c->duration,
                ])->all(),
            );
            $this->warn('Dry run — no changes were written.');

            return self::SUCCESS;
        }

        $created = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar($contracts->count());
        $bar->start();

        foreach ($contracts as $contract) {
            try {
                DB::transaction(fn () => $service->create($contract));
                $created++;
            } catch (Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("Contract #{$contract->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Done. Balances generated for {$created} contract(s).");

        if ($failed > 0) {
            $this->warn("{$failed} contract(s) failed — see messages above.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
