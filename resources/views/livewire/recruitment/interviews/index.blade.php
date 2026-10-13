@php
    use App\Enums\RecruitmentInterviewLocationType;
    use App\Enums\RecruitmentInterviewStatus;

    $t = 'recruitment-applicant.interviews.';
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $panel = 'rounded-surface border border-neutral-200 bg-white p-5 shadow-e1';
    $showUrl = fn ($id) => route('recruitment.interviews.show', ['interviewId' => $id]);
    $zoned = fn ($interview, $date) => $date?->copy()->setTimezone($interview->timezone ?: $timezone);
    $personName = fn ($participant) => $participant->user?->profile?->name ?? $participant->user?->email;

    if ($next) {
        $start = $zoned($next, $next->scheduled_at);
        $end = $zoned($next, $next->ends_at);
        $underWay = $start->lte(now()) && $end !== null && now()->lt($end);
        $application = $next->application;
        $position = $application?->vacancy?->position?->title ?? $application?->campaign?->vacancy?->position?->title;
        $online = $next->location_type === RecruitmentInterviewLocationType::Online;
        // The viewer leads the panel, so "am I on it?" answers itself.
        $members = $next->currentParticipants
            ->sortByDesc(fn ($participant) => $participant->user_id === auth()->id())
            ->values();
    }
@endphp
<x-slot:title>{{ __($t.'title') }}</x-slot:title>

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <livewire:layout.breadcrumbs :title="__($t.'title')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __($t.'title')]]" />
            <p class="text-body text-secondary-medium">{{ __($t.'index_hint') }}</p>
        </div>

        {{-- A view switch, not tabs: two pressed-state buttons, so assistive tech is not promised arrow-key tab behaviour. --}}
        <div class="inline-flex rounded-control border border-neutral-200 bg-white p-1 text-body shadow-e1" role="group"
             aria-label="{{ __($t.'title') }}">
            @foreach (['board' => ['board_view', 'squares-2x2'], 'calendar' => ['calendar_view', 'calendar-days']] as $option => [$label, $icon])
                <button type="button" wire:click="$set('view', '{{ $option }}')" aria-pressed="{{ $view === $option ? 'true' : 'false' }}"
                        class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control px-4 transition duration-150 motion-reduce:transition-none sm:min-h-10 {{ $focus }}
                               {{ $view === $option ? 'bg-selection font-semibold text-secondary-dark' : 'text-secondary-medium hover:text-secondary-dark' }}">
                    <flux:icon :name="$icon" variant="micro" class="size-4" aria-hidden="true" />
                    {{ __($t.$label) }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- The briefing: what is next, what is waiting on me, and the week at a glance. --}}
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-12">
        {{-- 1. Next interview --}}
        <section aria-labelledby="briefing-next" class="{{ $panel }} flex flex-col md:col-span-2 xl:col-span-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="briefing-next" class="text-small font-semibold text-secondary-dark">
                    {{ __($t.'board.'.($nextIsMine || ! $next ? 'next_mine' : 'next_scope')) }}
                </h2>
                @if ($next)
                    @if ($underWay)
                        <span class="inline-flex items-center gap-1.5 rounded-control bg-neutral-900 px-2 py-0.5 text-caption font-semibold text-white">
                            <span class="relative flex size-2" aria-hidden="true">
                                <span class="absolute inline-flex size-full animate-ping rounded-full bg-white opacity-75 motion-reduce:animate-none"></span>
                                <span class="relative inline-flex size-2 rounded-full bg-white"></span>
                            </span>
                            {{ __($t.'board.now') }}
                        </span>
                    @else
                        <time datetime="{{ $start->toIso8601String() }}" class="text-small tabular-nums text-secondary-medium">
                            {{ $start->copy()->locale(app()->getLocale())->diffForHumans(['parts' => 2, 'join' => true]) }}
                        </time>
                    @endif
                @endif
            </div>

            @if ($next)
                <div class="mt-4 flex flex-1 gap-4">
                    {{-- A tear-off date tile. --}}
                    <time datetime="{{ $start->toDateString() }}" aria-hidden="true"
                          class="w-16 shrink-0 self-start overflow-hidden rounded-control border border-neutral-200 text-center shadow-e1">
                        <span class="block bg-neutral-900 px-1 py-0.5 text-caption font-semibold text-white">
                            {{ $start->isToday() ? __($t.'today') : $start->translatedFormat('D') }}
                        </span>
                        <span class="block pt-1 text-h3 font-bold tabular-nums text-neutral-900">{{ $start->day }}</span>
                        <span class="block pb-1.5 text-caption text-secondary-medium">{{ $start->translatedFormat('M') }}</span>
                    </time>

                    <div class="min-w-0 flex-1">
                        <a href="{{ $showUrl($next->id) }}" wire:navigate
                           class="rounded-control text-h4 font-semibold text-neutral-900 underline-offset-4 hover:underline {{ $focus }}">
                            {{ $application?->applicant?->full_name }}
                        </a>
                        <p class="mt-0.5 text-small text-secondary-medium">
                            @if ($position)
                                {{ $position }} &middot;
                            @endif
                            {{ __($t.'types.'.$next->interview_type) }}
                        </p>

                        <dl class="mt-3 space-y-1.5 text-small text-secondary-dark">
                            <div class="flex items-start gap-2">
                                <dt class="mt-0.5 shrink-0">
                                    <flux:icon.clock variant="micro" class="size-3.5 text-secondary-medium" aria-hidden="true" />
                                    <span class="sr-only">{{ __($t.'when') }}</span>
                                </dt>
                                <dd>
                                    <span class="sr-only">{{ $start->translatedFormat('l j F Y') }}</span>
                                    <time datetime="{{ $start->toIso8601String() }}" class="font-semibold tabular-nums">{{ $start->format('H:i') }}</time>@if ($end)&ndash;<time datetime="{{ $end->toIso8601String() }}" class="font-semibold tabular-nums">{{ $end->format('H:i') }}</time>@endif
                                    <span class="ms-1 text-caption text-secondary-medium">{{ __($t.'times_in', ['timezone' => $next->timezone ?: $timezone]) }}</span>
                                </dd>
                            </div>
                            <div class="flex items-start gap-2">
                                <dt class="mt-0.5 shrink-0">
                                    @if ($online)
                                        <flux:icon.video-camera variant="micro" class="size-3.5 text-secondary-medium" aria-hidden="true" />
                                    @else
                                        <flux:icon.map-pin variant="micro" class="size-3.5 text-secondary-medium" aria-hidden="true" />
                                    @endif
                                    <span class="sr-only">{{ __($t.'location') }}</span>
                                </dt>
                                <dd class="min-w-0 wrap-break-word">
                                    @if ($online)
                                        {{ __($t.'location_types.online') }}@if ($next->location_value) &middot; {{ $next->location_value }}@endif
                                    @else
                                        {{ $next->location_value ?: __($t.'location_types.in_person') }}
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        @if ($nextMeetingUrl)
                            <a href="{{ $nextMeetingUrl }}" target="_blank" rel="noopener noreferrer"
                               class="mt-3 inline-flex min-h-11 items-center gap-1.5 rounded-control bg-neutral-900 px-3 text-small font-semibold text-white shadow-e1 transition duration-150 hover:bg-neutral-800 active:scale-[0.98] motion-reduce:transition-none sm:min-h-9 {{ $focus }}">
                                <flux:icon.video-camera variant="micro" class="size-3.5" aria-hidden="true" />
                                {{ __($t.'join_meeting') }}
                                <span class="sr-only">{{ __($t.'new_tab') }}</span>
                            </a>
                        @endif
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-neutral-100 pt-3">
                    <div class="flex min-w-0 items-center gap-2">
                        @if ($members->isNotEmpty())
                            <span class="flex shrink-0 -space-x-1.5" aria-hidden="true">
                                @foreach ($members->take(4) as $member)
                                    <x-recruitment.monogram :name="$personName($member)" :self="$member->user_id === auth()->id()" class="ring-2 ring-white" />
                                @endforeach
                                @if ($members->count() > 4)
                                    <span class="inline-flex size-7 items-center justify-center rounded-full bg-neutral-100 text-caption font-semibold text-secondary-dark ring-2 ring-white tabular-nums">+{{ $members->count() - 4 }}</span>
                                @endif
                            </span>
                            <p class="min-w-0 text-small text-secondary-dark">
                                <span class="sr-only">{{ __($t.'board.panel') }}:</span>
                                {{ $members->map(fn ($member) => $member->user_id === auth()->id() ? __($t.'board.you') : $personName($member))->implode(app()->getLocale() === 'ar' ? '، ' : ', ') }}
                            </p>
                        @endif
                    </div>
                    <a href="{{ $showUrl($next->id) }}" wire:navigate
                       class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-control text-small font-semibold text-secondary-dark hover:text-neutral-900 sm:min-h-9 {{ $focus }}">
                        {{ __($t.'board.open') }}
                        <flux:icon.arrow-right variant="micro" class="size-3.5 rtl:rotate-180" aria-hidden="true" />
                    </a>
                </div>
            @else
                <div class="flex flex-1 flex-col items-center justify-center py-6 text-center">
                    <span class="flex size-11 items-center justify-center rounded-full bg-neutral-100 text-secondary-medium" aria-hidden="true">
                        <flux:icon.calendar-days class="size-5" />
                    </span>
                    <p class="mt-3 font-semibold text-secondary-dark">{{ __($t.'board.none_ahead') }}</p>
                    <p class="mt-1 max-w-sm text-small text-secondary-medium">{{ __($t.'board.none_ahead_hint') }}</p>
                </div>
            @endif
        </section>

        {{-- 2. Waiting on you --}}
        <section aria-labelledby="briefing-waiting" class="{{ $panel }} flex flex-col xl:col-span-4">
            <div class="flex items-center gap-2">
                <h2 id="briefing-waiting" class="text-small font-semibold text-secondary-dark">{{ __($t.'board.waiting_title') }}</h2>
                @if ($waitingCount > 0)
                    <span class="rounded-inset bg-neutral-900 px-1.5 py-0.5 text-caption font-semibold tabular-nums text-white">{{ $waitingCount }}</span>
                @endif
            </div>
            <p class="mt-1 text-small text-secondary-medium">{{ __($t.'board.waiting_hint') }}</p>

            @if ($waiting->isNotEmpty())
                <ul class="mt-2 divide-y divide-neutral-100">
                    @foreach ($waiting as $interview)
                        @php
                            $name = $interview->application?->applicant?->full_name;
                            $at = $zoned($interview, $interview->scheduled_at);
                            $evaluateUrl = $showUrl($interview->id).'#my-evaluation';
                        @endphp
                        <li class="flex items-center gap-3 py-2.5" wire:key="briefing-waiting-{{ $interview->id }}">
                            <x-recruitment.monogram :name="$name" tone="candidate" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold text-neutral-900">{{ $name }}</p>
                                <p class="truncate text-small text-secondary-medium">
                                    {{ __($t.'types.'.$interview->interview_type) }} &middot;
                                    <time datetime="{{ $at->toIso8601String() }}">{{ $at->translatedFormat('j M') }}</time>
                                </p>
                            </div>
                            @if ($loop->first)
                                <a href="{{ $evaluateUrl }}" wire:navigate
                                   class="inline-flex min-h-11 shrink-0 items-center rounded-control bg-primary px-3 text-small font-semibold text-white shadow-e1 transition duration-150 hover:bg-primary-hover active:scale-[0.98] motion-reduce:transition-none sm:min-h-9 {{ $focus }}">
                                    {{ __($t.'board.evaluate') }}<span class="sr-only">: {{ $name }}</span>
                                </a>
                            @else
                                <a href="{{ $evaluateUrl }}" wire:navigate
                                   class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-control px-1 text-small font-semibold text-secondary-dark hover:text-neutral-900 sm:min-h-9 {{ $focus }}">
                                    {{ __($t.'board.evaluate') }}<span class="sr-only">: {{ $name }}</span>
                                    <flux:icon.arrow-right variant="micro" class="size-3.5 rtl:rotate-180" aria-hidden="true" />
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($waitingCount > 3)
                    <p class="mt-1 text-small text-secondary-medium">{{ trans_choice($t.'board.waiting_more', $waitingCount - 3) }}</p>
                @endif
            @else
                <div class="flex flex-1 flex-col items-center justify-center py-6 text-center">
                    <flux:icon.check-circle class="size-7 text-secondary-medium" aria-hidden="true" />
                    <p class="mt-2 font-semibold text-secondary-dark">{{ __($t.'board.caught_up') }}</p>
                    <p class="mt-1 max-w-xs text-small text-secondary-medium">{{ __($t.'board.caught_up_hint') }}</p>
                </div>
            @endif
        </section>

        {{-- 3. This week: a small data list, not a headline number. --}}
        <section aria-labelledby="briefing-week" class="{{ $panel }} xl:col-span-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="briefing-week" class="text-small font-semibold text-secondary-dark">{{ __($t.'board.week_title') }}</h2>
                <span class="text-small tabular-nums text-secondary-medium">
                    {{ $weekStart->translatedFormat('j M') }} &ndash; {{ $weekStart->copy()->addDays(6)->translatedFormat('j M') }}
                </span>
            </div>

            <ol class="mt-3 grid grid-cols-7 gap-1">
                @foreach (range(0, 6) as $offset)
                    @php
                        $day = $weekStart->copy()->addDays($offset);
                        $dayInterviews = $week->get($day->toDateString(), collect());
                        $isToday = $day->isToday();
                    @endphp
                    <li wire:key="briefing-day-{{ $day->toDateString() }}"
                        aria-label="{{ trans_choice($t.'board.day_count', $dayInterviews->count(), ['date' => $day->translatedFormat('l j F')]) }}"
                        class="flex flex-col items-center gap-1 py-1.5 {{ $isToday ? 'rounded-control bg-neutral-100' : '' }}">
                        <span aria-hidden="true" class="text-caption {{ $day->isFriday() ? 'text-secondary-light' : 'text-secondary-medium' }}">{{ $day->minDayName }}</span>
                        <span aria-hidden="true" class="flex size-6 items-center justify-center text-small font-semibold tabular-nums
                            {{ $isToday ? 'rounded-full bg-neutral-900 text-white' : ($day->isFriday() ? 'text-secondary-light' : 'text-secondary-dark') }}">{{ $day->day }}</span>
                        <span aria-hidden="true" class="flex flex-col items-center gap-0.5">
                            @foreach ($dayInterviews->take(5) as $interview)
                                <span class="size-2 rounded-inset {{ RecruitmentInterviewStatus::dotClasses($interview->status) }}"></span>
                            @endforeach
                            @if ($dayInterviews->count() > 5)
                                <span class="text-caption leading-none tabular-nums text-secondary-medium">+{{ $dayInterviews->count() - 5 }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ol>

            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1.5 text-small">
                @foreach (['all' => null, 'ahead' => RecruitmentInterviewStatus::Scheduled, 'held' => RecruitmentInterviewStatus::Completed, 'off' => RecruitmentInterviewStatus::Cancelled] as $total => $dotStatus)
                    <div class="flex items-center justify-between gap-2">
                        <dt class="flex min-w-0 items-center gap-1.5 text-secondary-medium">
                            @if ($dotStatus)
                                <span class="size-2 shrink-0 rounded-inset {{ RecruitmentInterviewStatus::dotClasses($dotStatus) }}" aria-hidden="true"></span>
                            @endif
                            {{ __($t.'board.totals.'.$total) }}
                        </dt>
                        <dd class="font-semibold tabular-nums text-secondary-dark">{{ $weekTotals[$total] }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    </div>

    @if ($view === 'calendar')
        <livewire:recruitment.interviews.calendar key="interviews-calendar" />
    @else
        <livewire:recruitment.interviews.board key="interviews-board" />
    @endif
</div>
