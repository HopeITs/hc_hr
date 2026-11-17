@php
    use App\Enums\RecruitmentInterviewStatus;

    $navButton = 'inline-flex size-11 cursor-pointer items-center justify-center rounded-control border border-secondary-soft bg-white text-secondary-dark shadow-e1 transition hover:bg-selection sm:size-9 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';

    // The phone agenda lists only this month's days that have something on them.
    $agendaDays = collect($weeks)->flatten(1)->filter(fn ($day) => $day['inMonth'] && count($day['interviews']) > 0);
@endphp
<section class="rounded-surface border border-secondary-soft bg-white p-4 shadow-e1 sm:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="previousMonth" class="{{ $navButton }}" aria-label="{{ __('recruitment-applicant.interviews.previous_month') }}">
                <svg class="size-4 rtl:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12.5 15 7.5 10l5-5"/></svg>
            </button>
            <h2 class="min-w-36 text-center text-h4 font-semibold" aria-live="polite">{{ $first->translatedFormat('F Y') }}</h2>
            <button type="button" wire:click="nextMonth" class="{{ $navButton }}" aria-label="{{ __('recruitment-applicant.interviews.next_month') }}">
                <svg class="size-4 rtl:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m7.5 5 5 5-5 5"/></svg>
            </button>
            <button type="button" wire:click="thisMonth"
                    class="ms-1 inline-flex py-2 cursor-pointer items-center rounded-control px-3 text-body font-medium text-secondary-dark transition hover:bg-selection focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('recruitment-applicant.interviews.today') }}
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-secondary-medium">
            @if ($canSchedule)
                <x-primary-button type="button" wire:click="startScheduling('{{ now()->toDateString() }}')" wire:target="startScheduling">
                    {{ __('recruitment-applicant.interviews.schedule') }}
                </x-primary-button>
            @endif
            <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 sm:min-h-0">
                <input type="checkbox" wire:model.live="showCancelled" class="size-4 rounded-inset border-secondary-light text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('recruitment-applicant.interviews.show_cancelled') }}
            </label>
            <span>{{ __('recruitment-applicant.interviews.times_in', ['timezone' => $timezone]) }}</span>
        </div>
    </div>

    @if ($selectedDate !== null && $canSchedule)
        <form wire:submit="continueScheduling" class="mt-4 space-y-4 rounded-surface border border-secondary-soft bg-neutral-50 p-4" aria-labelledby="calendar-schedule-title">
            <h2 id="calendar-schedule-title" class="text-h4 font-semibold">{{ __('recruitment-applicant.interviews.schedule') }}</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <x-forms.input-label for="calendar-schedule-date" required>{{ __('recruitment-applicant.interviews.calendar_date') }}</x-forms.input-label>
                    <input id="calendar-schedule-date" type="date" value="{{ $selectedDate }}" min="{{ now()->toDateString() }}" wire:change="startScheduling($event.target.value)" class="mt-1 w-full rounded-control border border-secondary-light bg-white px-3 py-2 text-body focus:border-primary focus:ring-primary">
                    <x-forms.input-error field="selectedDate" />
                    <x-forms.input-error field="date" />
                </div>
                <div>
                    <x-forms.input-label for="calendar-schedule-campaign" required>{{ __('recruitment-applicant.interviews.calendar_campaign') }}</x-forms.input-label>
                    <x-forms.select2-input id="calendar-schedule-campaign" wire:model.live="campaignId">
                        <option value="">{{ __('recruitment.choose') }}</option>
                        @foreach ($schedulingOptions->unique('campaign_id') as $option)
                            <option value="{{ $option->campaign_id }}">#{{ $option->campaign_id }} · {{ $option->campaign?->vacancy?->position?->title }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-forms.input-error field="campaignId" />
                </div>
                <div wire:key="calendar-applications-{{ $campaignId }}">
                    <x-forms.input-label for="calendar-schedule-application" required>{{ __('recruitment-applicant.interviews.calendar_application') }}</x-forms.input-label>
                    <x-forms.select2-input id="calendar-schedule-application" wire:model="applicationId">
                        <option value="">{{ __('recruitment.choose') }}</option>
                        @foreach ($schedulingOptions->where('campaign_id', $campaignId) as $option)
                            <option value="{{ $option->id }}">{{ $option->applicant?->full_name }} · #{{ $option->id }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-forms.input-error field="applicationId" />
                </div>
            </div>
            @if ($schedulingOptions->isEmpty())
                <p class="text-body text-secondary-medium">{{ __('recruitment-applicant.interviews.calendar_empty') }}</p>
            @endif
            <div class="flex flex-wrap gap-2">
                <x-primary-button type="submit" wire:target="continueScheduling" :disabled="$schedulingOptions->isEmpty()">{{ __('recruitment-applicant.interviews.calendar_continue') }}</x-primary-button>
                <x-secondary-button wire:click="cancelScheduling">{{ __('recruitment-applicant.cancel') }}</x-secondary-button>
            </div>
        </form>
    @endif

    {{-- Phones: an agenda. A seven-column grid cannot fit a readable name at this width. --}}
    <div class="mt-4 sm:hidden">
        @forelse ($agendaDays as $day)
            <section class="border-t border-secondary-soft py-3 first:border-t-0" wire:key="agenda-{{ $day['date']->toDateString() }}">
                <h3 class="text-sm font-semibold {{ $day['isToday'] ? 'text-secondary-dark' : 'text-secondary-dark' }}">
                    {{ $day['date']->translatedFormat('l j F') }}
                    @if ($day['isToday'])
                        <span class="ms-1 rounded-inset bg-secondary-dark px-1.5 py-0.5 text-caption font-semibold text-white">{{ __('recruitment-applicant.interviews.today') }}</span>
                    @endif
                </h3>
                <ul class="mt-2 space-y-2">
                    @foreach ($day['interviews'] as $interview)
                        @include('livewire.recruitment.interviews.partials.calendar-entry', ['interview' => $interview, 'compact' => false])
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="py-6 text-center text-sm text-secondary-medium">{{ __('recruitment-applicant.interviews.none_this_month') }}</p>
        @endforelse
    </div>

    {{-- Wider screens: the month grid. Each day is a labelled list, so its entries are announced with their date. --}}
    <div class="mt-4 hidden overflow-x-auto sm:block">
        <div class="min-w-[640px]">
            <div class="grid grid-cols-7 border-b border-secondary-soft pb-2 text-center text-xs font-semibold text-secondary-medium" aria-hidden="true">
                @foreach ($weekdays as $weekday)
                    <span>{{ $weekday }}</span>
                @endforeach
            </div>

            @foreach ($weeks as $week)
                <div class="grid grid-cols-7 border-b border-secondary-soft" wire:key="week-{{ $week[0]['date']->toDateString() }}">
                    @foreach ($week as $day)
                        <div class="min-h-28 border-e border-secondary-soft p-1.5 last:border-e-0 {{ $day['isToday'] ? 'bg-neutral-50' : ($day['inMonth'] ? '' : 'bg-neutral-100/60') }}">
                            <div class="mb-1 flex items-center justify-between">
                                @if ($canSchedule && $day['date']->gte(now()->startOfDay()))
                                    <button type="button" wire:click="startScheduling('{{ $day['date']->toDateString() }}')"
                                            aria-label="{{ __('recruitment-applicant.interviews.schedule_on', ['date' => $day['date']->translatedFormat('j F Y')]) }}"
                                            class="inline-flex size-9 cursor-pointer items-center justify-center rounded-control text-body font-semibold text-primary hover:bg-selection focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                                        <span aria-hidden="true">+</span>
                                    </button>
                                @endif
                                <span aria-hidden="true" class="flex size-6 items-center justify-center rounded-full text-xs
                                    {{ $day['isToday'] ? 'bg-neutral-900 font-semibold text-white' : ($day['inMonth'] ? 'text-secondary-dark' : 'text-secondary-light') }}">
                                    {{ $day['date']->day }}
                                </span>
                            </div>

                            @if (count($day['interviews']) > 0)
                                <ul class="space-y-1" aria-label="{{ $day['date']->translatedFormat('l j F Y') }}">
                                    @foreach ($day['interviews'] as $interview)
                                        @include('livewire.recruitment.interviews.partials.calendar-entry', ['interview' => $interview, 'compact' => true])
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</section>
