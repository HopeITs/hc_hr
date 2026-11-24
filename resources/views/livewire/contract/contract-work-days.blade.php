{{--
    The working days on a contract's page: the week as it is worked now (the
    center's day off dashed, a day off outlined), the changes so far, and
    HR's change from a date on — as a day range (most programs) or as days
    picked one by one (Study Zone). Not printed: the contract states how
    many days, never which. A different number of days is an amendment.
--}}
<div>
    @if($visible)
        <x-card id="work-days" class="scroll-mt-24" :title="__('contracts.work-days.title')">
            <x-slot:icon>
                @if($canChange)
                    <x-button variant="outline" size="sm" wire:click="openChange">
                        <x-ic name="calendar" class="size-4" />
                        @lang($isRange ? 'contracts.work-days.change-range' : 'contracts.work-days.change')
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
                @if($isRange && $rangeLabel)
                    {{-- A day range is the contract's own week, not a day off chosen. --}}
                    <x-tag icon="calendar">@lang('contracts.work-days.range-badge', ['range' => $rangeLabel])</x-tag>
                @elseif($now['own'] !== '')
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
            @php
                $dayOptions = collect(range(0, 6))->mapWithKeys(fn (int $d) => [$d => now()->startOfWeek(0)->addDays($d)->translatedFormat('l')]);
            @endphp
            <x-modal.dialog id="workDaysDialog" wire:model="showChange" maxWidth="lg" close-action="requestClose"
                            :title="__($isRange ? 'contracts.work-days.change-range' : 'contracts.work-days.change')">
                {{-- One Alpine scope for the picker: the days change at once in the
                     browser (entangled, the server hears of each in the background);
                     a range sets them from–to; the count is checked as they change. --}}
                <div class="space-y-5"
                     x-data="{
                         days: $wire.entangle('days').live,
                         mode: $wire.entangle('mode'),
                         dateTaken: $wire.entangle('dateTaken'),
                         meta: @js($pickerDays),
                         expected: @js($expected),
                         from: '',
                         to: '',
                         init() { this.syncRange(); this.$watch('days', () => this.syncRange()) },
                         syncRange() {
                             const d = this.days.map(Number).sort((a, b) => a - b);
                             if (d.length && d[d.length - 1] - d[0] + 1 === d.length) { this.from = String(d[0]); this.to = String(d[d.length - 1]) }
                         },
                         applyRange() {
                             if (this.from === '' || this.to === '' || this.rangeInvalid) return;
                             const closed = this.meta.filter(m => m.closed).map(m => m.day);
                             this.days = Array.from({ length: Number(this.to) - Number(this.from) + 1 }, (_, i) => Number(this.from) + i).filter(x => ! closed.includes(x));
                         },
                         get rangeInvalid() { return this.from !== '' && this.to !== '' && Number(this.to) < Number(this.from) },
                         get count() { return this.days.length },
                         get countDiffers() { return this.expected !== null && this.count !== this.expected },
                         get week() { return this.meta.map(d => ({ ...d, working: ! d.closed && this.days.map(Number).includes(d.day) })) },
                         toggle(d) { if (d.closed || this.mode === 'range') return; this.days = d.working ? this.days.filter(x => Number(x) !== d.day) : [...this.days, d.day] },
                     }">
                    {{-- Where it stands, before anything is changed. --}}
                    <p class="rounded-control bg-soft px-3 py-2 text-small text-ink">
                        <span class="font-semibold">@lang('contracts.work-days.now'):</span>
                        {{ $isRange && $rangeLabel ? $rangeLabel : ($now['working'] ?: '—') }}
                        @if($now['own'] !== '' && ! $isRange)
                            <span class="text-ink-2">· @lang('contracts.work-days.off') {{ $now['own'] }}</span>
                        @endif
                    </p>

                    {{-- The same two ways as the contract form. --}}
                    <div>
                        <x-forms.input-label required>@lang('general.composer.day-pick-mode')</x-forms.input-label>
                        <x-forms.segmented-control
                            name="work_days_mode"
                            model="mode"
                            :options="['range' => __('general.composer.day-pick-range'), 'days' => __('general.composer.day-pick-days')]"
                            :aria-label="__('general.composer.day-pick-mode')"
                        />
                    </div>

                    <div x-cloak x-show="mode === 'range'" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-forms.input-label for="work_days_range_from" required>@lang('general.composer.work-day-from')</x-forms.input-label>
                            <x-forms.select id="work_days_range_from" :options="$dayOptions" x-model="from" x-on:change="applyRange()" :placeholder="__('general.select')" />
                        </div>
                        <div>
                            <x-forms.input-label for="work_days_range_to" required>@lang('general.composer.work-day-to')</x-forms.input-label>
                            <x-forms.select id="work_days_range_to" :options="$dayOptions" x-model="to" x-on:change="applyRange()" :placeholder="__('general.select')" />
                        </div>
                        <p x-cloak x-show="rangeInvalid" class="hc-err sm:col-span-2">@lang('contracts.work-days.range-invalid')</p>
                    </div>

                    <div>
                        <x-forms.input-label>@lang('general.composer.workdays')</x-forms.input-label>
                        <div class="mt-1 grid grid-cols-7 gap-1 sm:gap-1.5" role="group" aria-label="@lang('general.composer.workdays')">
                            <template x-for="d in week" :key="d.day">
                                <button type="button"
                                        class="flex rounded-control focus-visible:outline-2 focus-visible:outline-offset-2"
                                        :class="d.closed || mode === 'range' ? 'cursor-default' : 'transition hover:-translate-y-px motion-reduce:transition-none'"
                                        :disabled="d.closed"
                                        :aria-disabled="mode === 'range' ? 'true' : 'false'"
                                        :aria-pressed="d.working ? 'true' : 'false'"
                                        x-on:click="toggle(d)">
                                    @include('livewire.contract._work-day-tile-alpine')
                                </button>
                            </template>
                        </div>
                        <p class="mt-1 text-small text-ink-2" x-show="mode === 'days'">@lang('general.composer.workdays-hint')</p>
                        <x-error-span>{{ $errors->first('days') }}</x-error-span>
                    </div>

                    {{-- The number of days is a contract term: said as it changes, with the way to change it. --}}
                    @if($expected !== null)
                        <div x-cloak x-show="countDiffers" role="status" aria-live="polite"
                             class="rounded-control border-2 border-ink bg-soft px-3 py-3 text-small text-ink">
                            <p class="font-semibold">
                                <x-ic name="info" class="inline size-4" />
                                <span x-text="@js(__('contracts.work-days.count-differs', ['expected' => $expected, 'count' => '__COUNT__'])).replace('__COUNT__', count)"></span>
                            </p>
                            <p class="mt-1 text-ink-2">@lang('contracts.work-days.count-differs-hint')</p>
                            @if($amendUrl)
                                <x-button variant="outline" size="sm" class="mt-2" :href="$amendUrl">
                                    <x-ic name="plus" class="size-4" />
                                    @lang('contracts.work-days.make-amendment')
                                </x-button>
                            @endif
                        </div>
                        <p x-show="! countDiffers" class="text-small text-ink-2">
                            {{ trans_choice('contracts.work-days.keep-count', $expected, ['count' => $expected]) }}
                        </p>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-forms.input-label for="work_days_from" required>@lang('contracts.work-days.effective-from')</x-forms.input-label>
                            <x-forms.date-input id="work_days_from" class="w-full" wire:model.live="effectiveFrom" />
                            @if($amendmentOnDate)
                                {{-- Said as soon as the date is picked, with the way out. --}}
                                <div class="mt-2 rounded-control border border-ink bg-soft px-3 py-2 text-small text-ink" role="status" aria-live="polite">
                                    <p><x-ic name="info" class="inline size-4" /> @lang('contracts.work-days.amendment-on-date', ['number' => $amendmentOnDate])</p>
                                    <x-button variant="outline" size="sm" class="mt-2" wire:click="$set('effectiveFrom', '{{ $freeDate }}')">
                                        @lang('contracts.work-days.use-date', ['date' => \Illuminate\Support\Carbon::parse($freeDate)->format('d/m/Y')])
                                    </x-button>
                                </div>
                            @else
                                <x-error-span>{{ $errors->first('effectiveFrom') }}</x-error-span>
                            @endif
                        </div>
                        <div>
                            <x-forms.input-label for="work_days_note">@lang('contracts.work-days.note')</x-forms.input-label>
                            <x-forms.input id="work_days_note" class="w-full" wire:model="note" maxlength="255" />
                            <x-error-span>{{ $errors->first('note') }}</x-error-span>
                        </div>
                    </div>

                    {{-- What saving will do, in one sentence. --}}
                    @if($move && $move['changed'])
                        <p x-show="! countDiffers" class="rounded-control border border-ink px-3 py-2 text-small text-ink" role="status" aria-live="polite">
                            <span class="font-semibold">{{ $move['label'] }}:</span>
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
                        <x-button wire:click="save" x-bind:disabled="countDiffers || rangeInvalid || dateTaken">
                            <span wire:loading.remove wire:target="save">@lang('general.save')</span>
                            <span wire:loading wire:target="save">@lang('general.saving')…</span>
                        </x-button>
                    </div>
                </div>
            </x-modal.dialog>
        @endif
    @endif
</div>
