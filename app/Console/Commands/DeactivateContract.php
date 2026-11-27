<?php

namespace App\Console\Commands;

use App\Models\Contract;
use Illuminate\Console\Command;

class DeactivateContract extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deactivate:contract';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'deactivate contract';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {

        // Past its end date, or terminated with a date that has come: a
        // future-dated termination keeps the contract running until the
        // night of that date (R2-3), and this run ends it then.
        $contracts = Contract::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereDate('end_date', '<', today())
                ->orWhereDate('terminated_at', '<=', today()))
            ->get();

        // ContractObserver::updated() frees the seat (exist_emp - 1) when
        // is_active turns false, the same path a termination takes, so this
        // command must not decrement as well. (It used to, unguarded: that
        // also freed a seat for an ended draft, which never held one, and
        // could take the counter below zero.)
        foreach ($contracts as $contract) {
            $contract->update(['is_active' => false]);
        }
        $this->info('Finished deactivating '.$contracts->count().' contract ');
    }
}
