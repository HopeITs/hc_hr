{{--
    Read-only contract drawer. No pay amount is rendered here for any role.
    Dialog semantics: role=dialog + aria-modal, labelled by the employee name,
    focus moves to the Close button on open and is trapped, Escape / backdrop /
    Close dismiss it, and the page (contracts-module) returns focus to the
    control that opened it.
--}}
<div>
    @if($contract)
        @php
            $titleKey = app()->getLocale() === 'ar' ? 'title_ar' : 'title_en';
            $contractStatus = \App\Enums\ContractStatus::for($contract);
            $days = \App\Livewire\Contract\ContractsTable::endsInDays($contract);
            $position = $contract->position;
            $city = $position?->city?->name ?? $position?->center?->city?->name;
            $fields = [
                trans_choice('general.contract-type', 1) => $contract->contractType?->name,
                __('general.position') => $position?->{$titleKey},
                trans_choice('general.program', 1) => $position?->program?->name,
                trans_choice('general.department', 1) => $position?->department?->name,
                trans_choice('general.job-level', 1) => $position?->jobLevel?->name,
                trans_choice('general.city', 1) => $city,
                __('general.weekly-hours') => $contract->weekly_hours ? $contract->weekly_hours : null,
                __('general.auto-renew') => $contract->auto_renew ? __('general.yes') : __('general.no'),
            ];
        @endphp
        <div class="fixed inset-0 z-50" wire:key="contract-drawer-{{ $contract->getKey() }}"
            x-data
            x-init="$nextTick(() => $refs.close.focus())"
            x-on:keydown.escape.stop="$wire.close()"
            x-on:keydown.tab="
                const f = [...$refs.panel.querySelectorAll('a[href], button:not([disabled])')];
                if (! f.length) return;
                if ($event.shiftKey && document.activeElement === f[0]) { $event.preventDefault(); f.at(-1).focus(); }
                else if (! $event.shiftKey && document.activeElement === f.at(-1)) { $event.preventDefault(); f[0].focus(); }">
            <div class="absolute inset-0 bg-backdrop" x-on:click="$wire.close()" aria-hidden="true"></div>
            <section x-ref="panel" role="dialog" aria-modal="true" aria-labelledby="contract-drawer-title"
                class="absolute inset-y-0 end-0 flex w-full max-w-md flex-col overflow-y-auto bg-card shadow-e2">
                <header class="flex items-start justify-between gap-3 border-b border-line-soft p-4">
                    <div class="min-w-0">
                        <p class="text-small text-ink-2">{{ __('contracts.drawer-title') }}</p>
                        <h2 id="contract-drawer-title" class="break-words text-h4 font-semibold" dir="auto">{{ $contract->employee?->name ?? __('general.na') }}</h2>
                    </div>
                    <button type="button" x-ref="close" wire:click="close"
                        aria-label="{{ __('contracts.drawer-close') }}"
                        class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control text-ink-2 hover:bg-hover hover:text-ink">
                        <x-ic name="x" class="size-5" />
                    </button>
                </header>

                <div class="flex flex-col gap-4 p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-status :status="$contractStatus" />
                        @if($days !== null)
                            <x-tag variant="rush">{{ trans_choice('contracts.ends-in-days', $days, ['count' => $days]) }}</x-tag>
                        @endif
                    </div>

                    <dl class="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-x-3 gap-y-3 text-small">
                        <dt class="text-ink-2">{{ __('general.period') }}</dt>
                        <dd class="font-medium" dir="auto"><bdi>{{ $contract->start_date?->format('Y-m-d') }} – {{ $contract->end_date?->format('Y-m-d') ?? __('general.present') }}</bdi></dd>
                        @foreach($fields as $label => $value)
                            @continue(blank($value))
                            <dt class="text-ink-2">{{ $label }}</dt>
                            <dd class="break-words font-medium" dir="auto">{{ $value }}</dd>
                        @endforeach
                        @if($contract->isTerminated())
                            <dt class="text-ink-2">{{ __('general.terminated-at') }}</dt>
                            <dd class="break-words font-medium" dir="auto">
                                <bdi>{{ $contract->terminated_at?->format('Y-m-d') }}</bdi>
                                {{ $contract->terminationReason?->title ?? $contract->reason_of_termination }}
                            </dd>
                        @endif
                    </dl>
                </div>

                <footer class="sticky bottom-0 mt-auto flex flex-wrap gap-2 border-t border-line-soft bg-card p-4 pb-[max(1rem,env(safe-area-inset-bottom))] max-sm:[&>a]:flex-1 max-sm:[&>a]:justify-center">
                    <x-button :href="route('contracts.show', $contract->getKey())">{{ __('contracts.drawer-open-full') }}</x-button>
                    @if($canEdit)
                        <x-button variant="outline" :href="route('contracts.edit', $contract->getKey())">{{ __('general.edit') }}</x-button>
                    @endif
                </footer>
            </section>
        </div>
    @endif
</div>
