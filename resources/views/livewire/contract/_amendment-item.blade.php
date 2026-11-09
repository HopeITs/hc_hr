@php
    /**
     * One amendment on the contract's amendments card.
     *
     * @var \App\Models\ContractAmendment $amendment
     * @var \App\Models\Contract $contract
     * @var array<int, array{label: string, after: string, before: string, changed: bool}> $terms  every term after it (ContractAmendmentDescriber::terms)
     * @var bool $isCurrent  the latest amendment in effect: its terms are the contract's now
     */
    $signed = $amendment->getFirstMedia('signed');

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

{{-- Why, before what: read first, labelled, at body size, line breaks kept. --}}
@if(filled($amendment->reason))
    <div class="mt-3 rounded-control border-s-4 border-ink bg-soft px-3 py-2">
        <p class="text-caption font-semibold text-ink-2">@lang('contracts.amendment.reason-label')</p>
        <p dir="auto" class="whitespace-pre-line text-body text-ink">{{ $amendment->reason }}</p>
    </div>
@endif

@if($terms !== [])
    {{-- Every term the contract runs on after this amendment, so it reads on its
         own. What changed is outlined, with what it replaced beneath; what did
         not change is quieter and says so. No colour: shape and words only. --}}
    <dl class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3" @if($tour ?? false) data-tour="contract-details.amend-terms" @endif>
        @foreach($terms as $term)
            <div @class([
                'min-w-0 rounded-control px-3 py-2',
                'border border-ink bg-card' => $term['changed'],
                'border border-transparent bg-soft' => ! $term['changed'],
            ])>
                <dt class="flex items-center justify-between gap-2 text-caption text-ink-2">
                    <span class="truncate">{{ $term['label'] }}</span>
                    @if($term['changed'])
                        <span class="shrink-0 font-semibold text-ink">@lang('contracts.amendment.changed')</span>
                    @endif
                </dt>
                <dd @class(['num', 'font-semibold text-ink' => $term['changed'], 'text-ink-2' => ! $term['changed']])>{{ $term['after'] }}</dd>
                <dd class="num text-caption text-ink-3">
                    @if($term['changed'])
                        {{ $term['before'] !== '' ? __('contracts.amendment.was', ['value' => $term['before']]) : __('contracts.amendment.newly-set') }}
                    @else
                        @lang('contracts.amendment.unchanged')
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
@endif


{{-- Only the open (newest) amendment carries the tour hooks: one per page. --}}
<div class="mt-3 flex flex-wrap items-center gap-2" @if($tour ?? false) data-tour="contract-details.amend-actions" @endif>
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
