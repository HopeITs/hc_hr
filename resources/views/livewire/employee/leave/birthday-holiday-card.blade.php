@php
    use App\Models\BirthdayHolidayChoice;
    $fmt = fn ($d) => \Carbon\Carbon::parse($d)->locale(app()->getLocale())->translatedFormat('l j F');
@endphp

<div>
    @if ($own->isNotEmpty() || $team->isNotEmpty())
        <div class="mb-6 space-y-4 rounded-surface border border-neutral-200 bg-white p-4 shadow-e1">
            @if ($own->isNotEmpty())
                <section>
                    <h2 class="mb-2 font-semibold">@lang('holidays.birthday.card-title')</h2>

                    @foreach ($own as $o)
                        @php
                            $key = $o['holiday']->id.':'.$o['year'];
                            $choice = $o['choice'];
                        @endphp
                        <div class="mb-3 last:mb-0" wire:key="bday-{{ $key }}">
                            @if (! $o['conflict'])
                                <p>@lang('holidays.birthday.on-day', ['date' => $fmt($o['date'])])</p>
                            @elseif ($choice?->status === BirthdayHolidayChoice::APPROVED)
                                <p>@lang('holidays.birthday.approved', ['date' => $fmt($choice->chosen_date)])</p>
                            @else
                                <p class="mb-2">
                                    @if ($choice?->status === BirthdayHolidayChoice::PENDING)
                                        @lang('holidays.birthday.pending', ['date' => $fmt($choice->chosen_date)])
                                    @elseif ($choice?->status === BirthdayHolidayChoice::REJECTED)
                                        @lang('holidays.birthday.rejected', ['date' => $fmt($choice->chosen_date)])
                                    @else
                                        @lang('holidays.birthday.needs-choice', ['date' => $fmt($o['date'])])
                                    @endif
                                </p>

                                @php
                                    $weekStart = $o['date']->startOfWeek(\Carbon\CarbonInterface::MONDAY)->toDateString();
                                    $weekEnd = $o['date']->endOfWeek(\Carbon\CarbonInterface::SUNDAY)->toDateString();
                                @endphp
                                <div class="flex flex-wrap items-start gap-2">
                                    <div>
                                        <x-forms.date-input wire:model="picked.{{ $key }}" min="{{ $weekStart }}" max="{{ $weekEnd }}"
                                            aria-label="{{ __('holidays.birthday.choose') }}" />
                                        <x-error-span>{{ $errors->first('picked.'.$key) ?: $errors->first('chosen_date') }}</x-error-span>
                                    </div>
                                    <x-primary-button type="button" class="!w-auto" wire:click="choose('{{ $key }}')">
                                        @lang('holidays.birthday.choose')
                                    </x-primary-button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif

            @if ($team->isNotEmpty())
                <section>
                    <h2 class="mb-2 font-semibold">@lang('holidays.birthday.team-title')</h2>
                    <ul class="space-y-2">
                        @foreach ($team as $choice)
                            <li class="flex flex-wrap items-center justify-between gap-2" wire:key="bday-team-{{ $choice->id }}">
                                <span>@lang('holidays.birthday.team-row', ['name' => $choice->employee?->name, 'date' => $fmt($choice->chosen_date)])</span>
                                <span class="flex gap-2">
                                    <x-primary-button type="button" class="!w-auto" wire:click="decide({{ $choice->id }}, true)">@lang('holidays.birthday.approve')</x-primary-button>
                                    <x-secondary-button type="button" wire:click="decide({{ $choice->id }}, false)">@lang('holidays.birthday.reject')</x-secondary-button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif
</div>
