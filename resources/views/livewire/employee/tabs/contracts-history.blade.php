{{--
    Read-only: no inputs, nothing here is bound to a form or submitted.
    Contracts come from EmployeeEdit::contractHistory(), which mirrors the
    profile's Career History query.
--}}
<div class="space-y-6">
    <x-heading-label>@lang('general.contracts-history')</x-heading-label>

    @if ($isCreateMode)
        <p class="rounded-surface bg-soft p-4 text-center text-body text-ink-2">
            @lang('general.contracts-history-empty')</p>
    @elseif ($contractHistory->isEmpty())
        <p class="rounded-surface bg-soft p-4 text-center text-body text-ink-2">
            @lang('general.no-available-data')</p>
    @else
        <div class="space-y-4">
            @foreach ($contractHistory as $contract)
                <x-contract.history-card :contract="$contract" :can-view-financial="$canViewContractPay"
                    wire:key="contract-history-{{ $contract->id }}" />
            @endforeach
        </div>
    @endif
</div>
