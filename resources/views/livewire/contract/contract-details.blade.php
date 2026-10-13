@php
    /** @var \App\Models\Contract $contract */
    use Illuminate\Support\Str;

    $locale = $locale ?? app()->getLocale();
@endphp

<x-slot:title>
    {{ trans_choice('general.contracts', 1) }}
</x-slot:title>

<div class="space-y-4">
    <div class="flex items-center justify-between gap-3">
        <livewire:layout.breadcrumbs
            :title="trans_choice('general.contracts', 1)"
            :crumbs="$crumbs ?? [
                ['label' => trans_choice('general.employee-management', 2)],
                ['label' => trans_choice('general.contracts', 2), 'url' => route('contracts.index', [], false)],

            ]"
        />

        <div class="shrink-0 flex items-center gap-2">
            @can('export', $contract)
            <a href="{{ route('contracts.export', $contract) }}" data-tour="contract-details.export"
               class="inline-flex items-center rounded-control bg-primary px-4 py-2 text-white text-body font-medium shadow hover:bg-primary">
                <svg class="h-4 w-4 ms-2 me-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <path d="M7 10l5 5 5-5"/>
                    <path d="M12 15V3"/>
                </svg>
                @lang('general.export-pdf')
            </a>
            @endcan
            @can('renew', $contract)
                {{-- Tailwind 4 puts the important modifier at the end (mb-0!),
                     not at the front; the v3 "!mb-0" form is inert here. --}}
                <x-outline-button type="button" class="mb-0! me-0!" data-tour="contract-details.renew"
                                  x-on:click="Livewire.dispatch('open-renew-modal', { contractId: {{ $contract->id }} })">
                    {{ __('general.renew') }}
                </x-outline-button>
            @endcan
            <x-primary-button :href="route('contracts.edit', $contract->id)" data-tour="contract-details.edit">
                {{ __('general.edit') }}
            </x-primary-button>
            <x-secondary-button :href="route('contracts.index')">
                {{ __('general.back') }}
            </x-secondary-button>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <x-badge color="red">
            {{ $contract->contractType?->name
                ?? $contract->contractType?->name
                ?? __('general.na') }}
        </x-badge>
    </div>

    <div data-tour="contract-details.document">
        @includeIf($partialPath)
    </div>

    @can('renew', $contract)
        <livewire:contract.contract-renew-modal />
    @endcan
</div>

<script nonce="{{ Vite::cspNonce() }}">
    document.addEventListener('livewire:init', () => {
        Livewire.on('scroll-to-top', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    });
</script>
