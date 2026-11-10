{{--
    One person's contracts tile (EmployeeContractsCard), added from the career
    history section of their profile: position, department · type, dates and
    status, newest first, never pay. No longer allowed: one quiet line.
--}}
@php($locale = app()->getLocale())
<div class="hc-empc flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @elseif ($employee === null)
        <p class="hc-nt-empty pt-4">{{ __('dashboard.record.gone') }}</p>
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.rec-contracts', ['name' => $employee->name]) }}</h2>
        </header>

        @if ($contracts->isEmpty())
            <p class="hc-nt-empty" data-tile-empty>{{ __('general.no-available-data') }}</p>
        @else
            <ul class="hc-list">
                @foreach ($contracts as $contract)
                    @php($current = $contract->is_active && ! $contract->terminated_at)
                    <li class="hc-list-row">
                        <span class="min-w-0 flex-1">
                            <b class="block truncate">{{ $contract->position?->title ?? __('general.not-specified') }}</b>
                            <span class="block truncate text-small text-ink-2">
                                {{ collect([$contract->position?->department?->name, $contract->contractType?->name])->filter()->implode(' · ') }}
                            </span>
                            <span class="block text-small text-ink-2 num">
                                <bdi>{{ $contract->start_date?->locale($locale)->isoFormat('D MMM YYYY') }}</bdi> –
                                <bdi>{{ ($contract->terminated_at ?? $contract->end_date)?->locale($locale)->isoFormat('D MMM YYYY') }}</bdi>
                            </span>
                        </span>
                        <x-status class="shrink-0" :status="$current ? \App\Enums\ContractStatus::Active : \App\Enums\ContractStatus::Inactive" />
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
