@php
    /**
     * One amendment on the contract's amendments card.
     *
     * @var \App\Models\ContractAmendment $amendment
     * @var \App\Models\Contract $contract
     * @var array<int, array{0: string, 1: string, 2: string}> $lines  what changed (ContractAmendmentDescriber)
     * @var bool $isCurrent  the latest amendment in effect: its terms are the contract's now
     */
    $signed = $amendment->getFirstMedia('signed');
    $arrow = app()->getLocale() === 'ar' ? '←' : '→';

    // An amendment in effect that a later one has replaced is history, not
    // "in effect": only the newest one describes the contract as it stands.
    $status = $amendment->isApplied() && ! $isCurrent
        ? ['tone' => 'done', 'icon' => 'check', 'label' => __('contracts.amendment.status.superseded')]
        : ['status' => $amendment->statusEnum()];

    $download = '<svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>';
    $upload = '<svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>';
@endphp

<div class="flex flex-wrap items-center gap-x-3 gap-y-1">
    <span class="font-semibold text-ink">@lang('contracts.amendment.number', ['number' => $amendment->number])</span>
    <x-status :status="$status['status'] ?? null" :tone="$status['tone'] ?? null" :icon="$status['icon'] ?? null" :label="$status['label'] ?? null" />
    <span class="num text-small text-ink-2">
        @lang('contracts.amendment.effective-from-summary', ['date' => $amendment->effective_from->format('d/m/Y')])
    </span>
</div>

@if($lines !== [])
    <dl class="mt-2 grid gap-1 text-small sm:grid-cols-[auto_minmax(0,1fr)] sm:gap-x-4">
        @foreach($lines as [$label, $from, $to])
            <dt class="text-ink-2">{{ $label }}</dt>
            <dd class="num text-ink">
                @if($from !== '' || $to !== '')
                    <span class="text-ink-2">{{ $from }}</span>
                    <span aria-hidden="true" class="text-ink-3">{{ $arrow }}</span>
                    <span class="sr-only">@lang('contracts.amendment.becomes')</span>
                    <span class="font-semibold">{{ $to }}</span>
                @endif
            </dd>
        @endforeach
    </dl>
@endif

@if(filled($amendment->reason))
    <p class="mt-2 text-small text-ink-2">{{ $amendment->reason }}</p>
@endif

<div class="mt-3 flex flex-wrap items-center gap-2">
    @can('decideAmendment', $amendment)
        <x-button size="sm" wire:click="askApprove({{ $amendment->id }})">@lang('contracts.amendment.approve')</x-button>
    @endcan

    {{-- The sheet to print and sign, downloaded at once. A plain <a>, not
         <x-button href>: that one adds wire:navigate, which cannot fetch a file. --}}
    @can('exportAmendment', $amendment)
        <a class="hc-btn hc-btn--outline hc-btn--sm" download
           href="{{ route('contracts.amendments.export', [$contract->id, $amendment->id]) }}">
            {!! $download !!}
            @lang('general.export-pdf')
        </a>
    @endcan

    @if($signed)
        @can('exportAmendment', $amendment)
            <a class="hc-btn hc-btn--outline hc-btn--sm" download
               href="{{ route('contracts.amendments.signed', [$contract->id, $amendment->id]) }}">
                {!! $download !!}
                @lang('contracts.amendment.download-signed')
            </a>
        @endcan
    @endif

    @can('attachSignedAmendment', $amendment)
        <label class="hc-btn {{ $signed ? 'hc-btn--quiet' : 'hc-btn--outline' }} hc-btn--sm cursor-pointer">
            {!! $upload !!}
            {{ $signed ? __('contracts.amendment.replace-signed') : __('contracts.amendment.attach-signed') }}
            <input type="file" class="sr-only" accept=".pdf,.jpg,.jpeg,.png,.webp"
                   wire:model="signedFiles.{{ $amendment->id }}" />
        </label>
        <span wire:loading wire:target="signedFiles.{{ $amendment->id }}" class="text-caption text-ink-2">@lang('general.saving')</span>
    @endcan

    @can('decideAmendment', $amendment)
        <a class="hc-btn hc-btn--quiet hc-btn--sm" wire:navigate
           href="{{ route('contracts.amendments.edit', [$contract->id, $amendment->id]) }}">
            <x-ic name="pen" class="size-4" />
            @lang('general.edit')
        </a>
    @endcan

    @can('discardAmendment', $amendment)
        <x-button size="sm" variant="quiet" wire:click="askDiscard({{ $amendment->id }})">
            <x-ic name="trash" class="size-4" />
            {{ $amendment->isDraft() ? __('contracts.amendment.discard') : __('contracts.amendment.cancel') }}
        </x-button>
    @endcan
</div>
<x-error-span>{{ $errors->first('signedFiles.'.$amendment->id) }}</x-error-span>
