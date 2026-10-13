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


$contracts = Contract::with('position')
    ->where('is_active', true)
    ->whereDate('end_date', '<', today())
    ->get();

foreach ($contracts as $contract) {

    $contract->update(['is_active' => false]);


    if ($contract->position) {
        $contract->position()->decrement('exist_emp');
    }
}
        $this->info('Finished deactivating '.$contracts->count().' contract ' );
    }
}
