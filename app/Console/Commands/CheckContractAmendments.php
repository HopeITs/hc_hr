<?php

namespace App\Console\Commands;

use App\Enums\ContractAmendmentStatus;
use App\Models\Contract;
use App\Models\ContractAmendment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finds contracts whose amendment history no longer holds together — what an
 * amendment deleted outside the application (a SQL statement, a restore)
 * leaves behind. Nothing in the app can produce these, and nothing here
 * repairs them: it lists them for someone to look at.
 *
 *   missing     a number in the run 1…N is gone (an amendment was deleted)
 *   not-current the contract's terms are not the latest amendment's "after"
 *   broken      an amendment's "before" is not the previous one's "after"
 *
 * Exits 1 when anything is found, so a scheduler or a deploy script notices.
 */
class CheckContractAmendments extends Command
{
    protected $signature = 'contracts:amendments-check';

    protected $description = 'List contracts whose amendment history does not hold together';

    public function handle(): int
    {
        $problems = [];

        Contract::query()
            ->whereHas('amendments')
            ->with(['amendments.payTiers', 'payTiers'])
            ->orderBy('id')
            ->chunkById(100, function ($contracts) use (&$problems): void {
                foreach ($contracts as $contract) {
                    foreach ($this->problemsOf($contract) as $problem) {
                        $problems[] = [$contract->id, ...$problem];
                    }
                }
            });

        if ($problems === []) {
            $this->info('Every contract\'s amendments hold together.');

            return self::SUCCESS;
        }

        foreach ($problems as [$contractId, $kind, $detail]) {
            Log::warning('Contract amendment history does not hold together', [
                'contract_id' => $contractId, 'problem' => $kind, 'detail' => $detail,
            ]);
        }

        $this->table(['Contract', 'Problem', 'Detail'], $problems);
        $this->warn(count($problems).' problem(s). Restore the missing amendment from a backup, or correct the contract with a new amendment.');

        return self::FAILURE;
    }

    /** @return list<array{0: string, 1: string}> */
    private function problemsOf(Contract $contract): array
    {
        $problems = [];
        $all = $contract->amendments->sortBy('number')->values();
        $numbers = $all->pluck('number')->all();

        foreach (range(1, max($numbers)) as $expected) {
            if (! in_array($expected, $numbers, true)) {
                $problems[] = ['missing', "amendment {$expected} is gone"];
            }
        }

        $applied = $all->filter(fn (ContractAmendment $a) => $a->status === ContractAmendmentStatus::Applied)->values();
        $latest = $applied->last();

        if ($latest && ! $latest->isCarriedBy($contract)) {
            $problems[] = ['not-current', "the contract's terms are not those of amendment {$latest->number}, the latest in effect"];
        }

        foreach ($applied->slice(1)->values() as $i => $amendment) {
            $previous = $applied[$i];

            if (! $amendment->followsOn($previous)) {
                $problems[] = ['broken', "amendment {$amendment->number} does not start from the terms amendment {$previous->number} left"];
            }
        }

        return $problems;
    }
}
