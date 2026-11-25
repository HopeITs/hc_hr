{{--
    The working days & hours of a contract, on its page and on the employee's
    profile (variant): the week as it is worked now (the center's day off
    dashed, a day off outlined), the changes so far, and the change from a
    date on by HR or the employee's direct / technical manager — as a day
    range (most programs) or as days picked one by one (Study Zone). Not
    printed: the contract states how many days, never which. A different
    number of days is an amendment.
--}}
<div>
    @if($visible)
        @if($variant === 'profile')
            {{-- The employee profile: a section like its neighbours. Its body is
                 drawn twice (phone / wider), so the dialog stays outside it. --}}
            <x-profile-section data-section="work-days" :title="__('contracts.work-days.title')" :subtitle="$contractLabel" icon="icons.clock">
                <x-slot:action>
                    @if($canChange)
                        <x-button variant="outline" size="sm" wire:click="openChange">
                            <x-ic name="calendar" class="size-4" />
                            @lang($now['count'] === 0 ? 'contracts.work-days.set-days' : ($isRange ? 'contracts.work-days.change-range' : 'contracts.work-days.change'))
                        </x-button>
                    @endif
                </x-slot:action>
                @include('livewire.contract._work-days-body')
            </x-profile-section>
        @else
            <x-card id="work-days" class="scroll-mt-24" :title="__('contracts.work-days.title')">
                <x-slot:icon>
                    @if($canChange)
                        <x-button variant="outline" size="sm" wire:click="openChange">
                            <x-ic name="calendar" class="size-4" />
                            @lang($now['count'] === 0 ? 'contracts.work-days.set-days' : ($isRange ? 'contracts.work-days.change-range' : 'contracts.work-days.change'))
                        </x-button>
                    @endif
                </x-slot:icon>
                @include('livewire.contract._work-days-body')
            </x-card>
        @endif

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
                            @if($amendWay)
                                {{-- A small link to the way out, in the box's own line: make an
                                     amendment, open the waiting one, or who to ask. --}}
                                @if($amendWay['href'])
                                    <a href="{{ $amendWay['href'] }}" wire:navigate
                                       class="mt-2 inline-flex items-center gap-1 font-semibold text-ink underline underline-offset-4 hover:text-ink-2 focus-visible:outline-2 focus-visible:outline-offset-2">
                                        {{ $amendWay['label'] }}
                                        <x-ic name="arrow" class="size-3.5 rtl:-scale-x-100" />
                                    </a>
                                @else
                                    <p class="mt-2 text-ink-2">{{ $amendWay['label'] }}</p>
                                @endif
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
