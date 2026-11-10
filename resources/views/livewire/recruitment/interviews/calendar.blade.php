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
                    class="ms-1 inline-flex min-h-11 cursor-pointer items-center rounded-control px-3 text-sm font-medium text-secondary-dark transition hover:bg-selection sm:min-h-9 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('recruitment-applicant.interviews.today') }}
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-secondary-medium">
            <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 sm:min-h-0">
                <input type="checkbox" wire:model.live="showCancelled" class="size-4 rounded-inset border-secondary-light text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('recruitment-applicant.interviews.show_cancelled') }}
            </label>
            <span>{{ __('recruitment-applicant.interviews.times_in', ['timezone' => $timezone]) }}</span>
        </div>
    </div>

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
                            <div class="mb-1 flex justify-end">
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
