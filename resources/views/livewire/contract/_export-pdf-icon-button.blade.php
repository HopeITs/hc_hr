{{--
    The contracts list's per-row PDF export. Same state as the details page
    (_export-pdf-state); a table cell has no room for an inline error, so a
    failure is reported by the error toast and the icon itself becomes the
    Retry control (its name says so, and it turns brand red).
--}}
<span class="inline-flex" x-data="@include('livewire.contract._export-pdf-state', ['contract' => $contract])">
    <button type="button"
            x-on:click="exportPdf()"
            aria-label="{{ __('general.export-pdf') }}"
            title="{{ __('general.export-pdf') }}"
            x-bind:aria-label="exporting ? @js(__('contracts.export-loading')) : (exportError ? @js(__('contracts.export-retry-label')) : @js(__('general.export-pdf')))"
            x-bind:title="exporting ? @js(__('contracts.export-loading')) : (exportError ? @js(__('contracts.export-retry-label')) : @js(__('general.export-pdf')))"
            x-bind:aria-disabled="exporting ? 'true' : 'false'"
            x-bind:aria-busy="exporting ? 'true' : 'false'"
            x-bind:class="exportError ? 'text-primary' : 'text-secondary-medium'"
            class="cursor-pointer p-2 inline-flex items-center justify-center max-sm:min-h-11 max-sm:min-w-11
                   aria-disabled:cursor-wait focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
        <x-icons.export x-show="!exporting" />
        <x-icons.spinner x-cloak x-show="exporting" class="size-5 motion-reduce:animate-none" aria-hidden="true" />
    </button>
</span>
