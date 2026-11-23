{{-- The reports hub: each report opens on its own page, with its own filters and Excel export. --}}
<div class="space-y-4">
    <x-slot:title>{{ __('Reports') }}</x-slot:title>

    <x-page-header :title="__('Reports')" :lead="__('Ready-made reports from the data already in the system. Each one respects your city and role.')" />

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="reports-hub">
        @foreach ($reports as $key => $report)
            <a href="{{ route($report['route']) }}" wire:navigate data-testid="report-card-{{ $key }}"
               class="block rounded-surface border border-line-soft bg-card p-5 shadow-e1 transition hover:border-line">
                <h2 class="text-h4 font-extrabold text-ink">{{ $report['title'] }}</h2>
                <p class="mt-2 text-small text-ink-2">{{ $report['lead'] }}</p>
                <span class="mt-4 inline-block text-small font-bold text-ink underline">{{ __('Open the report') }}</span>
            </a>
        @endforeach
    </div>
</div>
