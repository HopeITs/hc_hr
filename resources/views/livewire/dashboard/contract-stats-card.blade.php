{{--
    One contract statistics card as a tile (ContractStatsCard): the page's own
    card body (partials.contract-stat-body), titled with what it counts
    (center · program · city), and a link to its program or center. No longer
    allowed: one quiet line.
--}}
<div class="hc-cstat flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @elseif (! $allowed || $card === null)
        <p class="hc-nt-empty pt-4">{{ __('dashboard.record.gone') }}</p>
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ $label }}</h2>
            <x-button variant="outline" size="sm" class="ms-auto shrink-0" wire:navigate
                      :href="$card['center'] ? route('centers.show', $card['center']->id) : route('programs.show', ['program' => $card['program']->slug])">
                {{ __('general.show-more') }}
            </x-button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto">
            @include('livewire.dashboard.partials.contract-stat-body', ['card' => $card, 'cardType' => $cardType])
        </div>
    @endif
</div>
