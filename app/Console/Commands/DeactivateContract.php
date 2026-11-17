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

        $contracts = Contract::query()
            ->where('is_active', true)
            ->whereDate('end_date', '<', today())
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
