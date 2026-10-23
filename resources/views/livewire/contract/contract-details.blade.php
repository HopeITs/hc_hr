@php
    /** @var \App\Models\Contract $contract */
    use Illuminate\Support\Str;

    $locale = $locale ?? app()->getLocale();

    // ContractPolicy::export already refuses a role that may not see pay
    // figures; the salary check is repeated here so this page never offers
    // a download the server will refuse (the PDF carries the pay clause).
    $canExport = auth()->user()?->can('export', $contract)
        && auth()->user()?->canViewSalary();
@endphp

<x-slot:title>
    {{ trans_choice('general.contracts', 1) }}
</x-slot:title>

<div class="space-y-4">
    {{-- The export state wraps the header row and the error below it, so a
         failure can be explained (and retried) full width, under the buttons. --}}
    <div class="space-y-3" @if($canExport) x-data="@include('livewire.contract._export-pdf-state', ['contract' => $contract])" @endif>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <livewire:layout.breadcrumbs
            :title="trans_choice('general.contracts', 1)"
            :crumbs="$crumbs ?? [
                ['label' => trans_choice('general.employee-management', 2)],
                ['label' => trans_choice('general.contracts', 2), 'url' => route('contracts.index', [], false)],

            ]"
        />

        <div class="flex flex-wrap items-center gap-2 max-md:w-full max-md:[&>*]:flex-1 max-md:[&>*]:justify-center max-md:[&>*]:min-h-11">
            @if($canExport)
            <button type="button" data-tour="contract-details.export"
                    x-on:click="exportPdf()"
                    x-bind:aria-disabled="exporting ? 'true' : 'false'"
                    x-bind:aria-busy="exporting ? 'true' : 'false'"
                    class="inline-flex min-h-11 items-center gap-2 rounded-control bg-primary px-4 py-2 text-white text-body font-medium shadow
                           hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary
                           aria-disabled:cursor-wait aria-disabled:opacity-80">
                <svg x-show="!exporting" class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <path d="M7 10l5 5 5-5"/>
                    <path d="M12 15V3"/>
                </svg>
                <x-icons.spinner x-cloak x-show="exporting" class="size-4 shrink-0 motion-reduce:animate-none" aria-hidden="true" />
                <span x-text="exporting ? @js(__('contracts.export-loading')) : @js(__('general.export-pdf'))">@lang('general.export-pdf')</span>
            </button>
            @endif
            @can('renew', $contract)
                {{-- Tailwind 4 puts the important modifier at the end (mb-0!),
                     not at the front; the v3 "!mb-0" form is inert here. --}}
                <x-outline-button type="button" class="mb-0! me-0!" data-tour="contract-details.renew"
                                  x-on:click="Livewire.dispatch('open-renew-modal', { contractId: {{ $contract->id }} })">
                    {{ __('general.renew') }}
                </x-outline-button>
            @endcan
            @can('update', $contract)
            <x-primary-button :href="route('contracts.edit', $contract->id)" data-tour="contract-details.edit">
                {{ __('general.edit') }}
            </x-primary-button>
            @endcan
            <x-secondary-button :href="route('contracts.index')">
                {{ __('general.back') }}
            </x-secondary-button>
        </div>
    </div>

    @if($canExport)
        {{-- Not a live region: the error toast raised at the same moment is
             what screen readers announce; this stays on screen with Retry. --}}
        <div x-cloak x-show="exportError"
             class="flex flex-wrap items-center gap-3 rounded-control border border-neutral-200 border-s-4 border-s-primary
                    bg-neutral-50 px-3 py-2 text-small text-neutral-800">
            <svg class="size-4 shrink-0 text-primary" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10 1.5a8.5 8.5 0 100 17 8.5 8.5 0 000-17zM9 5.5h2v6H9v-6zm0 7.5h2v2H9v-2z"/>
            </svg>
            <p class="min-w-0 flex-1 leading-relaxed" x-text="exportError"></p>
            <button type="button" x-on:click="exportPdf()" x-bind:aria-disabled="exporting ? 'true' : 'false'"
                    x-bind:aria-busy="exporting ? 'true' : 'false'"
                    class="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-control border border-neutral-300 bg-white px-3 py-1.5
                           text-small font-semibold text-secondary-dark shadow-e1 transition hover:border-primary hover:text-primary
                           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary
                           aria-disabled:cursor-wait aria-disabled:opacity-80">
                <x-icons.spinner x-cloak x-show="exporting" class="size-3.5 shrink-0 motion-reduce:animate-none" aria-hidden="true" />
                @lang('contracts.export-retry')
            </button>
        </div>
    @endif
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <x-badge color="primary">
            {{ $contract->contractType?->name
                ?? $contract->contractType?->name
                ?? __('general.na') }}
        </x-badge>
    </div>

    <div data-tour="contract-details.document" class="contract-review">
        @includeIf($partialPath)
    </div>

    @can('renew', $contract)
        <livewire:contract.contract-renew-modal />
    @endcan
</div>

<script nonce="{{ Vite::cspNonce() }}">
    // wire:navigate re-runs this script on every visit and livewire:init never
    // fires again, so register the (global) listener once, whenever Livewire is up.
    (() => {
        if (window.__hcScrollToTopBound) return;

        const bind = () => {
            if (window.__hcScrollToTopBound) return;
            window.__hcScrollToTopBound = true;
            Livewire.on('scroll-to-top', () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        };

        window.Livewire?.on ? bind() : document.addEventListener('livewire:init', bind, { once: true });
    })();
</script>
