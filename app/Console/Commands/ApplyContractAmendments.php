<?php

namespace App\Console\Commands;

use App\Services\ContractAmendmentService;
use Illuminate\Console\Command;

class ApplyContractAmendments extends Command
{
    protected $signature = 'contracts:apply-amendments';

    protected $description = 'Apply approved contract amendments whose effective date has come';

    public function handle(ContractAmendmentService $amendments): int
    {
        ['applied' => $applied, 'failed' => $failed, 'cancelled' => $cancelled] = $amendments->applyDue();

        $this->info('Applied '.$applied->count().' amendment(s).');

        if ($cancelled->isNotEmpty()) {
            $this->info('Cancelled, the contract ended first: '.$cancelled->pluck('id')->implode(', '));
        }

        if ($failed->isNotEmpty()) {
            $this->warn('Not applied (see the log): '.$failed->pluck('id')->implode(', '));
        }

        return self::SUCCESS;
    }
}
