@php
    use App\Enums\RecruitmentInterviewType;

    $focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';

    // How many cards each candidate has on the board, so a thread can say how many other rounds it links.
    $threadCounts = collect($lanes)->flatMap(fn (array $lane) => $lane['items'])->countBy('application_id');

    $allEmpty = collect($lanes)->every(fn (array $lane) => $lane['count'] === 0);

    $today = now($timezone)->toDateString();
    $tomorrow = now($timezone)->addDay()->toDateString();
@endphp
<div x-data="{ lane: $wire.entangle('lane'), thread: null }" class="space-y-4">
    {{-- Filters. Each one narrows every lane at once; none of them widens what the user may see. --}}
    <div class="flex flex-wrap items-center gap-2 rounded-surface border border-neutral-200 bg-white p-2 shadow-e1">
        <div class="relative min-w-48 flex-1">
            <label for="board-search" class="sr-only">{{ __('recruitment-applicant.interviews.board.search') }}</label>
            <flux:icon.magnifying-glass variant="micro" class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-secondary-medium" />
            <input id="board-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('recruitment-applicant.interviews.board.search') }}"
                   class="min-h-11 w-full rounded-control border border-neutral-200 bg-white ps-9 pe-3 text-small text-neutral-900 placeholder:text-secondary-medium sm:min-h-9 {{ $focusRing }}">
        </div>

        <div role="group" aria-label="{{ __('recruitment-applicant.interviews.board.round') }}" class="flex flex-wrap items-center gap-1">
            @foreach (array_merge([''], RecruitmentInterviewType::values()) as $round)
                <button type="button" wire:click="$set('type', '{{ $round }}')" aria-pressed="{{ $type === $round ? 'true' : 'false' }}"
                        @class([
                            'inline-flex min-h-11 cursor-pointer items-center rounded-control px-3 text-small transition duration-150 motion-reduce:transition-none sm:min-h-9',
                            $focusRing,
                            'bg-selection font-semibold text-neutral-900' => $type === $round,
                            'text-secondary-dark hover:bg-neutral-100' => $type !== $round,
                        ])>
                    {{ $round === '' ? __('recruitment-applicant.interviews.board.all_rounds') : __('recruitment-applicant.interviews.board.rounds_short.'.$round) }}
                </button>
            @endforeach
        </div>

        @if ($vacancies->count() > 1)
            <div>
                <label for="board-vacancy" class="sr-only">{{ __('recruitment-applicant.interviews.board.vacancy') }}</label>
                <select id="board-vacancy" wire:model.live="vacancy"
                        class="min-h-11 max-w-64 rounded-control border border-neutral-200 bg-white ps-3 pe-8 text-small text-neutral-900 sm:min-h-9 {{ $focusRing }}">
                    <option value="">{{ __('recruitment-applicant.interviews.board.all_vacancies') }}</option>
                    @foreach ($vacancies as $vacancyId => $vacancyTitle)
                        <option value="{{ $vacancyId }}">{{ $vacancyTitle }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($canFilterMine)
            <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 px-2 text-small text-secondary-dark sm:min-h-9">
                <input type="checkbox" wire:model.live="mine" class="size-4 rounded-inset border-neutral-300 text-primary {{ $focusRing }}">
                {{ __('recruitment-applicant.interviews.mine') }}
            </label>
        @endif

        @if ($filtering)
            <button type="button" wire:click="clearFilters"
                    class="inline-flex min-h-11 cursor-pointer items-center rounded-control px-3 text-small font-medium text-secondary-dark transition duration-150 hover:bg-selection motion-reduce:transition-none sm:min-h-9 {{ $focusRing }}">
                {{ __('recruitment-applicant.interviews.board.clear') }}
            </button>
        @endif
    </div>

    @if ($filtering && $allEmpty)
        {{-- Four empty lanes would say the same thing four times. --}}
        <div class="rounded-surface border border-neutral-200 bg-white p-6 text-center shadow-e1">
            <p class="text-body text-secondary-dark">{{ __('recruitment-applicant.interviews.board.no_match') }}</p>
            <button type="button" wire:click="clearFilters"
                    class="mt-3 inline-flex min-h-11 cursor-pointer items-center rounded-control bg-neutral-100 px-3 text-small font-semibold text-neutral-900 transition duration-150 hover:bg-selection motion-reduce:transition-none sm:min-h-9 {{ $focusRing }}">
                {{ __('recruitment-applicant.interviews.board.clear') }}
            </button>
        </div>
    @else
        {{-- Phones: the lanes become tabs. Switching is Alpine only; the server renders the same lane after a filter change. --}}
        <div role="group" aria-label="{{ __('recruitment-applicant.interviews.board.lanes_label') }}"
             class="grid grid-cols-2 gap-1 rounded-control bg-neutral-100 p-1 sm:grid-cols-4 md:hidden">
            @foreach ($lanes as $key => $data)
                <button type="button" x-on:click="lane = '{{ $key }}'"
                        aria-pressed="{{ $lane === $key ? 'true' : 'false' }}" x-bind:aria-pressed="lane === '{{ $key }}'"
                        @class([
                            'inline-flex min-h-11 cursor-pointer items-center justify-center gap-1.5 rounded-inset px-2 text-small transition duration-150 motion-reduce:transition-none',
                            $focusRing,
                            'bg-white font-semibold text-neutral-900 shadow-e1' => $lane === $key,
                            'text-secondary-dark' => $lane !== $key,
                        ])
                        x-bind:class="{ 'bg-white font-semibold text-neutral-900 shadow-e1': lane === '{{ $key }}', 'text-secondary-dark': lane !== '{{ $key }}' }">
                    <span class="truncate">{{ __('recruitment-applicant.interviews.board.lanes.'.$key) }}</span>
                    <span class="rounded-inset bg-neutral-200 px-1.5 text-caption font-semibold tabular-nums">{{ $data['count'] }}</span>
                </button>
            @endforeach
        </div>

        <div class="grid items-start gap-4 transition-opacity duration-150 motion-reduce:transition-none md:grid-cols-2 xl:grid-cols-4"
             wire:loading.class="opacity-60" wire:target="search,type,vacancy,mine,clearFilters">
            @foreach ($lanes as $key => $data)
                @php $remaining = $data['count'] - $data['items']->count(); @endphp
                <section aria-labelledby="lane-{{ $key }}-title" wire:key="board-lane-{{ $key }}"
                         @class(['flex flex-col rounded-surface bg-neutral-100 p-2', 'max-md:hidden' => $lane !== $key])
                         x-bind:class="{ 'max-md:hidden': lane !== '{{ $key }}' }">
                    <header class="flex items-start justify-between gap-2 px-1 pb-2">
                        <div class="min-w-0">
                            <h2 id="lane-{{ $key }}-title" class="text-body font-semibold text-neutral-900">{{ __('recruitment-applicant.interviews.board.lanes.'.$key) }}</h2>
                            <p class="text-small text-secondary-medium">{{ __('recruitment-applicant.interviews.board.lane_hints.'.$key) }}</p>
                        </div>
                        <span class="rounded-inset bg-white px-1.5 text-caption font-semibold tabular-nums text-neutral-900 shadow-e1">{{ $data['count'] }}</span>
                    </header>

                    @if ($data['items']->isEmpty())
                        <p class="rounded-control border border-dashed border-neutral-300 p-4 text-center text-small text-secondary-medium">
                            {{ __('recruitment-applicant.interviews.board.lane_empty.'.$key) }}
                        </p>
                    @else
                        <ol class="space-y-2">
                            @php $previousDay = null; @endphp
                            @foreach ($data['items'] as $interview)
                                {{-- Coming up reads as an agenda: one heading per day, so the cards carry only their times. --}}
                                @if ($key === 'upcoming')
                                    @php $day = $interview->scheduled_at->copy()->setTimezone($timezone); @endphp
                                    @if ($day->toDateString() !== $previousDay)
                                        @php $previousDay = $day->toDateString(); @endphp
                                        <li wire:key="board-day-{{ $previousDay }}" class="px-1 pt-2 text-small font-semibold text-secondary-dark">
                                            @if ($previousDay === $today)
                                                <span class="me-1 rounded-inset bg-neutral-900 px-1.5 py-0.5 text-caption font-semibold text-white">{{ __('recruitment-applicant.interviews.today') }}</span>
                                                {{ $day->translatedFormat('l j F') }}
                                            @elseif ($previousDay === $tomorrow)
                                                {{ __('recruitment-applicant.interviews.board.tomorrow') }}
                                            @else
                                                {{ $day->translatedFormat('l j F') }}
                                            @endif
                                        </li>
                                    @endif
                                @endif

                                @include('livewire.recruitment.interviews.partials.board-card', [
                                    'interview' => $interview,
                                    'lane' => $key,
                                    'pending' => in_array($interview->id, $pendingIds),
                                    'submitted' => in_array($interview->id, $submittedIds),
                                    'threaded' => in_array($interview->application_id, $threaded),
                                    'threadCount' => max(($threadCounts[$interview->application_id] ?? 1) - 1, 0),
                                    'timezone' => $timezone,
                                    'showDate' => $key !== 'upcoming',
                                ])
                            @endforeach
                        </ol>

                        @if ($remaining > 0)
                            <button type="button" wire:click="showMore('{{ $key }}')"
                                    class="mt-2 inline-flex min-h-11 w-full cursor-pointer items-center justify-center gap-1.5 rounded-control px-3 text-small font-medium text-secondary-dark transition duration-150 hover:bg-selection motion-reduce:transition-none sm:min-h-9 {{ $focusRing }}">
                                {{ __('recruitment-applicant.interviews.board.show_more') }}
                                <span class="tabular-nums text-secondary-medium">({{ $remaining }})</span>
                            </button>
                        @endif
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
