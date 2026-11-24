{{--
    The working days on a contract's page: the week as it is worked now (the
    place's day off dashed, the person's own day off outlined), the moves of
    the day off so far, and HR's "Change days off" from a date on. Not
    printed: the contract states how many days, never which.
--}}
<div>
    @if($visible)
        <x-card id="work-days" class="scroll-mt-24" :title="__('contracts.work-days.title')">
            <x-slot:icon>
                @if($canChange)
                    <x-button variant="outline" size="sm" wire:click="openChange">
                        <x-ic name="calendar" class="size-4" />
                        @lang('contracts.work-days.change')
                    </x-button>
                @endif
            </x-slot:icon>

            {{-- The week at a glance: seven tiles, Saturday first, each with an
                 icon and a word (_work-day-tile). Short names up to laptop
                 width, full names from xl up; the badges below say it in words. --}}
            <ol class="grid max-w-3xl grid-cols-7 gap-1 sm:gap-2" aria-label="@lang('contracts.work-days.title')">
                @foreach($week as $d)
                    <li class="flex">@include('livewire.contract._work-day-tile', ['d' => $d, 'full' => true])</li>
                @endforeach
            </ol>

            {{-- The same week in words. --}}
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <x-tag icon="check">{{ trans_choice('contracts.work-days.days-a-week', $now['count'], ['count' => $now['count']]) }}</x-tag>
                @if($now['own'] !== '')
                    <x-tag icon="home">@lang('contracts.work-days.own-off', ['days' => $now['own']])</x-tag>
                @endif
                @if($now['center'] !== '')
                    <x-tag icon="building">@lang('contracts.work-days.center-off', ['days' => $now['center']])</x-tag>
                @endif
            </div>

            @if($since || $next)
                <p class="mt-3 text-small text-ink-2">
                    @if($since)
                        <span class="num">@lang('contracts.work-days.since', ['date' => $since['from']->format('d/m/Y')])</span>
                        @if($since['label'])
                            <span class="text-ink-3">· {{ $since['label'] }}</span>
                        @endif
                    @endif
                    @if($next)
                        <span @class(['block' => $since, 'font-semibold text-ink'])>
                            <x-ic name="clock" class="inline size-3.5" />
                            <span class="num">@lang('contracts.work-days.next', ['date' => $next['from']->format('d/m/Y'), 'days' => $next['days'] ?? __('contracts.work-days.back-to-range')])</span>
                        </span>
                    @endif
                </p>
            @endif

            @if($history->isNotEmpty())
                <details wire:ignore.self class="group/history mt-3 border-t border-line-soft pt-2">
                    <summary class="flex cursor-pointer list-none items-center gap-2 text-small font-semibold text-ink-2 hover:text-ink
                                    [&::-webkit-details-marker]:hidden">
                        <x-ic name="chevron" class="size-3.5 transition-transform group-open/history:rotate-90 rtl:-scale-x-100" />
                        @lang('contracts.work-days.history') <span class="num font-normal text-ink-3">({{ $history->count() }})</span>
                    </summary>
                    <ol class="mt-1 divide-y divide-line-soft ps-5">
                        @foreach($history as $row)
                            <li class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-1.5 text-small">
                                <span class="num font-semibold text-ink">
                                    @lang('contracts.work-days.from', ['date' => $row['from']->format('d/m/Y')])
                                </span>
                                <span class="text-ink">{{ $row['days'] ?? __('contracts.work-days.back-to-range') }}</span>
                                @if($row['note'] || $row['by'])
                                    <span class="text-caption text-ink-3">
                                        {{ collect([$row['note'], $row['by']])->filter()->implode(' · ') }}
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </details>
            @endif
        </x-card>

        @if($canChange)
            <x-modal.dialog id="workDaysDialog" wire:model="showChange" maxWidth="lg" close-action="requestClose"
                            :title="__('contracts.work-days.change')">
                <div class="space-y-5">
                    {{-- Where it stands, before anything is picked. --}}
                    <p class="rounded-control bg-soft px-3 py-2 text-small text-ink">
                        <span class="font-semibold">@lang('contracts.work-days.now'):</span>
                        {{ $now['working'] ?: '—' }}
                        @if($now['own'] !== '')
                            <span class="text-ink-2">· @lang('contracts.work-days.off') {{ $now['own'] }}</span>
                        @endif
                    </p>

                    <div>
                        <x-forms.input-label required>@lang('general.composer.workdays')</x-forms.input-label>
                        {{-- Tapped in the browser, at once; the server hears of each
                             tap in the background (days is entangled, live). --}}
                        <div class="mt-1 grid grid-cols-7 gap-1 sm:gap-1.5" role="group" aria-label="@lang('general.composer.workdays')"
                             x-data="{
                                 days: $wire.entangle('days').live,
                                 meta: @js($pickerDays),
                                 get week() { return this.meta.map(d => ({ ...d, working: ! d.closed && this.days.map(Number).includes(d.day) })) },
                                 toggle(d) { if (d.closed) return; this.days = d.working ? this.days.filter(x => Number(x) !== d.day) : [...this.days, d.day] },
                             }">
                            <template x-for="d in week" :key="d.day">
                                <button type="button"
                                        class="flex rounded-control focus-visible:outline-2 focus-visible:outline-offset-2"
                                        :class="d.closed ? 'cursor-not-allowed' : 'transition hover:-translate-y-px motion-reduce:transition-none'"
                                        :disabled="d.closed"
                                        :aria-pressed="d.working ? 'true' : 'false'"
                                        x-on:click="toggle(d)">
                                    @include('livewire.contract._work-day-tile-alpine')
                                </button>
                            </template>
                        </div>
                        @if($expected !== null)
                            <p class="mt-1 text-small text-ink-2">
                                {{ trans_choice('contracts.work-days.keep-count', $expected, ['count' => $expected]) }}
                            </p>
                        @endif
                        <x-error-span>{{ $errors->first('days') }}</x-error-span>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-forms.input-label for="work_days_from" required>@lang('contracts.work-days.effective-from')</x-forms.input-label>
                            <x-forms.date-input id="work_days_from" class="w-full" wire:model.live="effectiveFrom" />
                            <x-error-span>{{ $errors->first('effectiveFrom') }}</x-error-span>
                        </div>
                        <div>
                            <x-forms.input-label for="work_days_note">@lang('contracts.work-days.note')</x-forms.input-label>
                            <x-forms.input id="work_days_note" class="w-full" wire:model="note" maxlength="255" />
                            <x-error-span>{{ $errors->first('note') }}</x-error-span>
                        </div>
                    </div>

                    {{-- What saving will do, in one sentence. --}}
                    @if($move && $move['changed'])
                        <p class="rounded-control border border-ink px-3 py-2 text-small text-ink" role="status" aria-live="polite">
                            <span class="font-semibold">@lang('contracts.work-days.day-off'):</span>
                            {{ $move['before'] }} <span aria-hidden="true">→</span><span class="sr-only">@lang('contracts.work-days.to')</span> <strong>{{ $move['after'] }}</strong>
                            <span class="num text-ink-2">· @lang('contracts.work-days.from', ['date' => \Illuminate\Support\Carbon::parse($effectiveFrom)->format('d/m/Y')])</span>
                        </p>
                    @endif

                    @if($affected->isNotEmpty())
                        <div class="rounded-control border border-ink bg-soft px-3 py-2 text-small text-ink" role="status">
                            <p class="font-semibold">
                                <x-ic name="info" class="inline size-4" />
                                {{ trans_choice('contracts.work-days.affected', $affected->count(), ['count' => $affected->count()]) }}
                            </p>
                            <ul class="mt-1 list-disc ps-5 text-ink-2">
                                @foreach($affected as $leave)
                                    <li class="num">{{ $leave->start_at->format('d/m/Y') }} – {{ $leave->end_at->format('d/m/Y') }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <p class="text-small text-ink-2">@lang('contracts.work-days.explain')</p>

                    <div class="flex flex-col-reverse gap-2 border-t border-line-soft pt-4 sm:flex-row sm:justify-end">
                        <x-button variant="quiet" x-on:click="close()">@lang('general.cancel')</x-button>
                        <x-button wire:click="save">
                            <span wire:loading.remove wire:target="save">@lang('general.save')</span>
                            <span wire:loading wire:target="save">@lang('general.saving')…</span>
                        </x-button>
                    </div>
                </div>
            </x-modal.dialog>
        @endif
    @endif
</div>
