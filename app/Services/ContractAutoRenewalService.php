<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContractAutoRenewalService
{
    /**
     * @return Collection<int, Contract>
     */
    public function process(?CarbonInterface $asOf = null): Collection
    {
        $asOf ??= today();
        $renewed = collect();

        Contract::query()
            ->with(['position', 'payTiers'])
            ->where('auto_renew', true)
            ->where('is_active', false)
            ->whereNull('terminated_at')
            ->whereNull('termination_reason_id')
            ->whereDate('end_date', '<', $asOf->toDateString())
            ->orderBy('id')
            ->chunkById(100, function ($contracts) use ($renewed, $asOf): void {
                foreach ($contracts as $contract) {
                    $renewal = $this->renewIfNeeded($contract, $asOf);

                    if ($renewal) {
                        $renewed->push($renewal);
                    }
                }
            });

        return $renewed;
    }

    public function renewIfNeeded(Contract $contract, CarbonInterface $asOf): ?Contract
    {
        return DB::transaction(function () use ($contract, $asOf): ?Contract {
            $source = Contract::query()
                ->with(['position', 'payTiers'])
                ->whereKey($contract->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $source
                || ! $source->auto_renew
                // A legacy row can still carry the flag from before
                // ContractObserver::saving() forced it off for these.
                || ! $source->canAutoRenew()
                || $source->is_active
                || ! $source->end_date
                || $source->end_date->gte($asOf)
            ) {
                return null;
            }

            $start = $source->end_date->copy()->addDay();

            if ($this->successorExists($source, $start->toDateString())) {
                return null;
            }

            $renewal = $source->replicate([
                'id',
                'created_at',
                'updated_at',
            ]);

            $renewal->renewalSourceId = $source->id;
            $renewal->start_date = $start->toDateString();
            $renewal->end_date = $asOf->copy()->endOfYear()->toDateString();
            $renewal->terminated_at = null;
            $renewal->termination_reason_id = null;
            $renewal->reason_of_termination = null;
            $renewal->is_active = true;
            $renewal->auto_renew = true;
            $renewal->save();

            foreach ($source->payTiers as $tier) {
                $renewal->payTiers()->create($tier->only([
                    'condition_en',
                    'condition_ar',
                    'amount',
                    'sort_order',
                ]));
            }

            return $renewal->refresh();
        });
    }

    private function successorExists(Contract $source, string $startDate): bool
    {
        return Contract::query()
            ->where('id', '!=', $source->id)
            ->where('employee_id', $source->employee_id)
            ->where('position_id', $source->position_id)
            ->whereDate('start_date', $startDate)
            ->exists();
    }
}
