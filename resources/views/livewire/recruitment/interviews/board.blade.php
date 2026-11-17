@php
    use App\Enums\RecruitmentInterviewType;

    $focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';

    // How many cards each candidate has on the board, so a thread can say how many other rounds it links.
    $threadCounts = collect($lanes)->flatMap(fn (array $lane) => $lane['items'])->countBy('application_id');

    $allEmpty = collect($lanes)->every(fn (array $lane) => $lane['count'] === 0);

    $advanced = collect([$dateFrom ?? '', $dateTo ?? '', $campaign ?? '', $applicant ?? '', $participant ?? ''])->contains(fn ($value) => $value !== '');
    $laneRule = ['upcoming' => 'border-primary', 'awaiting' => 'border-secondary-dark', 'held' => 'border-neutral-300', 'closed' => 'border-neutral-200'];

@endphp
<div x-data="{ lane: $wire.entangle('lane'), thread: null, more: @js($advanced) }" class="space-y-5">
    {{-- Filters. Each one narrows every lane at once; none of them widens what the user may see. --}}
    <div class="space-y-3">
      <div class="grid items-stretch gap-3 xl:grid-cols-[minmax(18rem,1fr)_auto_auto_auto]">
        <div class="relative min-w-48 flex-1">
            <label for="board-search" class="sr-only">{{ __('recruitment-applicant.interviews.board.search') }}</label>
            <flux:icon.magnifying-glass variant="micro" class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-secondary-medium" />
            <input id="board-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('recruitment-applicant.interviews.board.search') }}"
                   class="min-h-11 w-full rounded-control border border-neutral-300 bg-white ps-9 pe-3 text-body text-neutral-900 placeholder:text-secondary-medium {{ $focusRing }}">
        </div>

        <div role="group" aria-label="{{ __('recruitment-applicant.interviews.board.round') }}" class="inline-flex items-center gap-1 rounded-control border border-neutral-200 bg-white p-1">
            @foreach (array_merge([''], RecruitmentInterviewType::values()) as $round)
                <button type="button" wire:click="$set('type', '{{ $round }}')" aria-pressed="{{ $type === $round ? 'true' : 'false' }}"
                        @class([
                            'inline-flex py-2 cursor-pointer items-center rounded-control px-3 text-body transition duration-150 motion-reduce:transition-none',
                            $focusRing,
                            'bg-secondary-dark font-semibold text-white' => $type === $round,
                            'text-secondary-medium hover:text-secondary-dark' => $type !== $round,
                        ])>
                    {{ $round === '' ? __('recruitment-applicant.interviews.board.all_rounds') : __('recruitment-applicant.interviews.board.rounds_short.'.$round) }}
                </button>
            @endforeach
        </div>

        @if ($canFilterMine)
            <label class="inline-flex py-2 cursor-pointer items-center gap-2.5 rounded-control border border-neutral-200 bg-white px-3 text-body text-secondary-dark transition duration-150 hover:border-neutral-300 motion-reduce:transition-none has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary">
                <input type="checkbox" role="switch" wire:model.live="mine" class="peer sr-only">
                <span aria-hidden="true" class="relative h-4.5 w-8 shrink-0 rounded-full bg-neutral-300 transition-colors duration-150 peer-checked:bg-neutral-900 motion-reduce:transition-none
                             after:absolute after:top-0.5 after:start-0.5 after:size-3.5 after:rounded-full after:bg-white after:shadow-e1 after:transition-all after:duration-150 peer-checked:after:start-4 motion-reduce:after:transition-none"></span>
                {{ __('recruitment-applicant.interviews.mine') }}
            </label>
        @endif

        <button type="button" x-on:click="more = ! more" x-bind:aria-expanded="more" aria-expanded="{{ $advanced ? 'true' : 'false' }}" aria-controls="board-more-filters"
                class="inline-flex py-2 cursor-pointer items-center justify-center gap-2 rounded-control border border-neutral-200 bg-white px-3 text-body font-semibold text-secondary-dark transition duration-150 hover:border-neutral-300 motion-reduce:transition-none {{ $focusRing }}"
                x-bind:class="more ? 'border-primary text-primary' : 'bg-white'">
            <flux:icon.funnel variant="micro" class="size-4" aria-hidden="true" />
            {{ __('recruitment-applicant.interviews.board.more_filters') }}
            @if ($advanced)
                <span class="size-1.5 rounded-full bg-neutral-900" aria-hidden="true"></span>
            @endif
        </button>

        @if ($filtering)
            <button type="button" wire:click="clearFilters"
                    class="inline-flex py-2 cursor-pointer items-center rounded-control px-3 text-body font-medium text-secondary-dark transition duration-150 hover:bg-selection motion-reduce:transition-none {{ $focusRing }}">
                {{ __('recruitment-applicant.interviews.board.clear') }}
            </button>
        @endif
      </div>

      <div id="board-more-filters" x-show="more" x-cloak x-transition.opacity.duration.150ms
           class="grid gap-3 rounded-surface border border-neutral-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <div>
            <label for="board-date-from" class="mb-1.5 block text-small font-semibold text-secondary-dark">{{ __('recruitment-applicant.interviews.board.date_from') }}</label>
            <input id="board-date-from" type="date" wire:model.live="dateFrom"
                   class="h-10 w-full rounded-control border border-neutral-300 bg-white px-3 text-small text-neutral-900 {{ $focusRing }}">
        </div>
        <div>
            <label for="board-date-to" class="mb-1.5 block text-small font-semibold text-secondary-dark">{{ __('recruitment-applicant.interviews.board.date_to') }}</label>
            <input id="board-date-to" type="date" wire:model.live="dateTo"
                   class="h-10 w-full rounded-control border border-neutral-300 bg-white px-3 text-small text-neutral-900 {{ $focusRing }}">
        </div>
        <div>
            <label for="board-campaign" class="mb-1.5 block text-small font-semibold text-secondary-dark">{{ __('recruitment-applicant.interviews.board.campaign') }}</label>
            <select id="board-campaign" wire:model.live="campaign" class="h-10 w-full rounded-control border border-neutral-300 bg-white ps-3 pe-8 text-small font-medium text-neutral-900 {{ $focusRing }}">
                <option value="">{{ __('recruitment-applicant.interviews.board.all_campaigns') }}</option>
                @foreach ($campaigns as $campaignId => $campaignTitle)
                    <option value="{{ $campaignId }}">{{ $campaignTitle }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="board-applicant" class="mb-1.5 block text-small font-semibold text-secondary-dark">{{ __('recruitment-applicant.interviews.board.applicant') }}</label>
            <select id="board-applicant" wire:model.live="applicant" class="h-10 w-full rounded-control border border-neutral-300 bg-white ps-3 pe-8 text-small font-medium text-neutral-900 {{ $focusRing }}">
                <option value="">{{ __('recruitment-applicant.interviews.board.all_applicants') }}</option>
                @foreach ($applicants as $applicantId => $applicantName)
                    <option value="{{ $applicantId }}">{{ $applicantName }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="board-participant" class="mb-1.5 block text-small font-semibold text-secondary-dark">{{ __('recruitment-applicant.interviews.board.participant') }}</label>
            <select id="board-participant" wire:model.live="participant" class="h-10 w-full rounded-control border border-neutral-300 bg-white ps-3 pe-8 text-small font-medium text-neutral-900 {{ $focusRing }}">
                <option value="">{{ __('recruitment-applicant.interviews.board.all_participants') }}</option>
                @foreach ($participants as $participantId => $participantName)
                    <option value="{{ $participantId }}">{{ $participantName }}</option>
                @endforeach
            </select>
        </div>
      </div>
    </div>

    @if ($filtering && $allEmpty)
        {{-- Four empty lanes would say the same thing four times. --}}
        <div class="rounded-surface border border-neutral-200 bg-white p-6 text-center shadow-e1">
            <p class="text-body text-secondary-dark">{{ __('recruitment-applicant.interviews.board.no_match') }}</p>
            <button type="button" wire:click="clearFilters"
                    class="mt-3 inline-flex py-2 cursor-pointer items-center rounded-control bg-neutral-100 px-3 text-body font-semibold text-neutral-900 transition duration-150 hover:bg-selection motion-reduce:transition-none {{ $focusRing }}">
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
             wire:loading.class="opacity-60" wire:target="search,type,vacancy,campaign,applicant,participant,dateFrom,dateTo,mine,clearFilters">
            @foreach ($lanes as $key => $data)
                @php $remaining = $data['count'] - $data['items']->count(); @endphp
                <section aria-labelledby="lane-{{ $key }}-title" wire:key="board-lane-{{ $key }}"
                         @class(['flex flex-col gap-3', 'max-md:hidden' => $lane !== $key])
                         x-bind:class="{ 'max-md:hidden': lane !== '{{ $key }}' }">
                    <header class="flex items-start justify-between gap-2 border-b-2 pb-2.5 {{ $laneRule[$key] }}">
                        <div class="min-w-0">
                            <h2 id="lane-{{ $key }}-title" class="text-body font-semibold text-neutral-900">{{ __('recruitment-applicant.interviews.board.lanes.'.$key) }}</h2>
                            <p class="text-small text-secondary-medium">{{ __('recruitment-applicant.interviews.board.lane_hints.'.$key) }}</p>
                        </div>
                        <span class="inline-flex min-w-6 items-center justify-center rounded-full border border-neutral-200 bg-white px-2 py-0.5 text-caption font-semibold tabular-nums text-secondary-dark">{{ $data['count'] }}</span>
                    </header>

                    @if ($data['items']->isEmpty())
                        <p class="rounded-surface border border-dashed border-neutral-300 px-4 py-5 text-center text-small text-secondary-medium text-pretty">
                            {{ __('recruitment-applicant.interviews.board.lane_empty.'.$key) }}
                        </p>
                    @else
                        <ol class="space-y-3">
                            @foreach ($data['items'] as $interview)
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
                                    class="mt-2 inline-flex py-2 w-full cursor-pointer items-center justify-center gap-1.5 rounded-control px-3 text-body font-medium text-secondary-dark transition duration-150 hover:bg-selection motion-reduce:transition-none {{ $focusRing }}">
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
