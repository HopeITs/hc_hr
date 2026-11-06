<x-slot:title>{{ __('recruitment-campaign.campaigns') }}</x-slot:title>

@php
    use App\Enums\HiringCampaignStatus;
    use App\Livewire\Recruitment\Campaigns\Index;

    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $tile = fn (bool $on) => 'flex min-h-16 w-full cursor-pointer flex-col items-start justify-between gap-1 rounded-control border px-4 py-2.5 text-start shadow-e1 transition duration-150 motion-reduce:transition-none '.$focus.' '
        .($on ? 'border-neutral-300 bg-selection' : 'border-neutral-200 bg-white hover:border-neutral-300');
    // Same stage dots as the table: hollow = being prepared, ink = live, faded = over.
    $dot = fn (int $status) => match ($status) {
        HiringCampaignStatus::Open => 'bg-neutral-900',
        HiringCampaignStatus::Published => 'bg-neutral-500',
        HiringCampaignStatus::Draft => 'border border-neutral-500 bg-white',
        default => 'bg-neutral-300',
    };
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <livewire:layout.breadcrumbs :title="__('recruitment-campaign.campaigns')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment-campaign.campaigns')]]" />
            @if ($closingSoon > 0)
                {{-- Plain sentence, no alarm colour: it is a reminder, not an error. --}}
                <p class="mt-1 flex items-center gap-2 text-small text-secondary-dark">
                    <flux:icon.clock variant="mini" class="size-4 text-secondary-medium" aria-hidden="true" />
                    {{ trans_choice('recruitment-campaign.closing_soon', $closingSoon, ['count' => $closingSoon]) }}
                </p>
            @endif
        </div>
        {{-- The permission alone, since the policy's create needs a vacancy and
             none is chosen yet. Each seat is authorized again on the next screen. --}}
        @if (auth()->user()?->hasActivePermission('recruitment-campaigns.manage'))
            <a href="{{ route('recruitment.campaigns.create') }}" wire:navigate
                class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control bg-primary px-4 text-body font-semibold text-white shadow-e1 transition hover:opacity-90 sm:min-h-10 {{ $focus }}">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                {{ __('recruitment-campaign.new_campaign') }}
            </a>
        @endif
    </div>

    {{-- Status tiles double as the main filter; each shows how many campaigns sit there right now. --}}
    <nav aria-label="{{ __('recruitment-campaign.status') }}">
        <ul class="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-7">
            <li>
                <button type="button" wire:loading.attr="disabled" wire:click="filterByStatus(null)" aria-pressed="{{ $statusFilter === null ? 'true' : 'false' }}" class="{{ $tile($statusFilter === null) }}">
                    <span class="text-h3 font-semibold tabular-nums text-neutral-900">{{ $total }}</span>
                    <span class="text-small {{ $statusFilter === null ? 'font-semibold text-secondary-dark' : 'text-secondary-medium' }}">{{ __('recruitment-applicant.filters.all') }}</span>
                </button>
            </li>
            @foreach (Index::TILE_ORDER as $status)
                @php
                    $count = (int) ($counts[$status] ?? 0);
                    $on = $statusFilter === $status;
                @endphp
                <li wire:key="campaign-status-{{ $status }}">
                    <button type="button" wire:loading.attr="disabled" wire:click="filterByStatus({{ $status }})" aria-pressed="{{ $on ? 'true' : 'false' }}" class="{{ $tile($on) }}">
                        <span class="flex w-full items-center justify-between gap-2">
                            <span @class(['text-h3 font-semibold tabular-nums', 'text-neutral-900' => $count > 0, 'text-secondary-light' => $count === 0])>{{ $count }}</span>
                            <span aria-hidden="true" class="size-2 rounded-full {{ $dot($status) }}"></span>
                        </span>
                        <span class="text-small {{ $on ? 'font-semibold text-secondary-dark' : 'text-secondary-medium' }}">{{ HiringCampaignStatus::getLocalizedName($status)['value'] }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </nav>

    <livewire:recruitment.campaigns.campaign-table :status-filter="$statusFilter" :key="'campaigns-'.($statusFilter ?? 'all')" />
</div>
