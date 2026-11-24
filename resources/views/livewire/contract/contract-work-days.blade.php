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

            <ul class="flex flex-wrap gap-2" aria-label="@lang('contracts.work-days.title')">
                @foreach($week as $d)
                    <li @class([
                        'inline-flex min-w-20 flex-col items-center rounded-control border px-3 py-1.5 text-small',
                        'border-dashed border-line bg-soft text-ink-3' => $d['closed'],
                        'border-ink bg-surface font-semibold text-ink' => $d['working'],
                        'border-line bg-card text-ink-2' => ! $d['closed'] && ! $d['working'],
                    ])>
                        <span>{{ $d['name'] }}</span>
                        <span class="text-caption font-normal">
                            @if($d['closed'])
                                @lang('general.composer.center-day-off')
                            @elseif($d['working'])
                                @lang('general.composer.workday-short')
                            @else
                                @lang('general.composer.own-day-off')
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>

            @if($history->isNotEmpty())
                <h3 class="mt-5 text-small font-semibold text-ink">@lang('contracts.work-days.history')</h3>
                <ol class="mt-2 divide-y divide-line-soft">
                    @foreach($history as $row)
                        <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2 text-small">
                            <span class="num font-semibold text-ink">
                                @lang('contracts.work-days.from', ['date' => $row['from']->format('d/m/Y')])
                            </span>
                            <span class="text-ink">{{ $row['days'] ?? __('contracts.work-days.back-to-range') }}</span>
                            @if($row['note'])
                                <span class="text-ink-2">· {{ $row['note'] }}</span>
                            @endif
                            @if($row['by'])
                                <span class="text-caption text-ink-3">· {{ $row['by'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-card>

        @if($canChange)
            <x-modal.dialog id="workDaysDialog" wire:model="showChange" maxWidth="lg" close-action="requestClose"
                            :title="__('contracts.work-days.change')">
                <div class="space-y-5">
                    <div>
                        <x-forms.input-label required>@lang('general.composer.workdays')</x-forms.input-label>
                        <div class="mt-1 grid grid-cols-4 gap-2 sm:grid-cols-7" role="group" aria-label="@lang('general.composer.workdays')">
                            @foreach($picker as $d)
                                <button type="button" wire:key="pick-{{ $d['day'] }}"
                                        wire:click="toggleDay({{ $d['day'] }})"
                                        @disabled($d['closed'])
                                        aria-pressed="{{ $d['working'] ? 'true' : 'false' }}"
                                        @class([
                                            'hc-choice flex min-h-14 flex-col items-center justify-center gap-0.5 px-1 py-1.5 text-center',
                                            'cursor-not-allowed border-dashed bg-soft text-ink-3 hover:border-line' => $d['closed'],
                                            'text-ink' => ! $d['closed'],
                                        ])>
                                    <span class="text-small font-semibold">{{ $d['name'] }}</span>
                                    <span class="text-caption">
                                        @if($d['closed'])
                                            <x-ic name="building" class="inline size-3" /> @lang('general.composer.center-short')
                                        @elseif($d['working'])
                                            @lang('general.composer.workday-short')
                                        @else
                                            @lang('general.composer.day-off-short')
                                        @endif
                                    </span>
                                </button>
                            @endforeach
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
