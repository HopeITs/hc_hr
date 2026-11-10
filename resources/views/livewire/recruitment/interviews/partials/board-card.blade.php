{{-- One interview on the board. The candidate's name is a stretched link, so the whole card opens it; anything else interactive sits above it. --}}
@php
    use App\Enums\RecruitmentInterviewLocationType;
    use App\Enums\RecruitmentInterviewStatus;
    use App\Enums\RecruitmentInterviewType;

    $focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $appId = (int) $interview->application_id;
    $status = $interview->status;
    $showUrl = route('recruitment.interviews.show', ['interviewId' => $interview->id]);

    $zone = $interview->timezone ?: $timezone;
    $start = $interview->scheduled_at->copy()->setTimezone($zone);
    $end = $interview->ends_at?->copy()->setTimezone($zone);
    $underWay = $lane === 'upcoming' && $end !== null && $interview->scheduled_at->lte(now()) && $interview->ends_at->isFuture();

    $position = $interview->application?->vacancy?->position?->{transColumn('title')};

    // The candidate's other rounds, only as far as this user may see them (constrained when eager-loaded).
    $others = ($interview->application?->interviews ?? collect())->where('id', '!=', $interview->id);
    $rounds = collect(RecruitmentInterviewType::values())->map(function (string $round) use ($interview, $others) {
        if ($round === $interview->interview_type) {
            return ['type' => $round, 'this' => true, 'status' => $interview->status];
        }

        // Already ordered by scheduled_at: the latest one that was not called off, else the latest of all.
        $ofType = $others->where('interview_type', $round);
        $pick = $ofType->where('status', '!=', RecruitmentInterviewStatus::Cancelled)->last() ?? $ofType->last();

        return ['type' => $round, 'this' => false, 'status' => $pick?->status];
    });

    // The viewer first, so "am I on this?" is answered by the first monogram.
    $viewerId = auth()->id();
    $panel = $interview->currentParticipants
        ->sortBy(fn ($participant) => $participant->user_id === $viewerId ? 0 : 1)
        ->values()
        ->map(fn ($participant) => [
            'self' => $participant->user_id === $viewerId,
            'name' => $participant->user?->profile?->name ?: $participant->user?->email,
        ]);
    $panelNames = $panel->map(fn ($member) => $member['self'] ? __('recruitment-applicant.interviews.board.you') : $member['name'])->implode(app()->getLocale() === 'ar' ? '، ' : ', ');

    $reasonKey = 'recruitment-applicant.interviews.cancellation_reasons.'.$interview->cancellation_reason;
@endphp
<li wire:key="board-card-{{ $interview->id }}"
    class="relative rounded-control border border-neutral-200 bg-white p-3 shadow-e1 transition duration-200 hover:border-neutral-300 hover:shadow-e2 motion-reduce:transition-none has-[.card-link:focus-visible]:outline-2 has-[.card-link:focus-visible]:outline-offset-2 has-[.card-link:focus-visible]:outline-primary"
    x-bind:class="thread === null ? '' : (thread === {{ $appId }} ? 'ring-2 ring-neutral-900 shadow-e2' : 'opacity-40')"
    @if ($threaded)
        x-on:mouseenter="thread = {{ $appId }}" x-on:mouseleave="thread = null"
        x-on:focusin="thread = {{ $appId }}" x-on:focusout="thread = null"
    @endif
>
    <div class="flex items-center justify-between gap-2">
        <time datetime="{{ $start->toIso8601String() }}" class="text-small tabular-nums text-secondary-medium">
            @if ($showDate)
                {{ $start->translatedFormat('D j M') }} &middot;
            @endif
            {{ $start->format('H:i') }}@if ($end)&ndash;{{ $end->format('H:i') }}@endif
        </time>

        @if ($underWay)
            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-control bg-neutral-900 px-2 py-0.5 text-xs font-semibold text-white">
                <span class="size-1.5 animate-pulse rounded-full bg-white motion-reduce:animate-none" aria-hidden="true"></span>
                {{ __('recruitment-applicant.interviews.board.now') }}
            </span>
        @elseif ($status !== RecruitmentInterviewStatus::Scheduled)
            <x-recruitment.pill class="shrink-0" :tone="RecruitmentInterviewStatus::classes($status)">{{ __('recruitment-applicant.interviews.statuses.'.$status) }}</x-recruitment.pill>
        @endif
    </div>

    <div class="mt-2 flex items-center gap-2.5">
        <x-recruitment.monogram :name="$interview->application?->applicant?->full_name" tone="candidate" />
        <div class="min-w-0">
            <h3 class="truncate text-body font-semibold text-neutral-900">
                <a href="{{ $showUrl }}" wire:navigate
                   @class(['card-link after:absolute after:inset-0 after:rounded-control focus-visible:outline-none', 'line-through' => $lane === 'closed'])>{{ $interview->application?->applicant?->full_name }}</a>
            </h3>
            <p class="truncate text-small text-secondary-medium">
                @if ($position)
                    {{ $position }} &middot;
                @endif
                {{ __('recruitment-applicant.interviews.types.'.$interview->interview_type) }}
            </p>
        </div>
    </div>

    @if ($threaded && $threadCount > 0)
        <p class="mt-2 inline-flex items-center gap-1 rounded-inset bg-neutral-100 px-1.5 py-0.5 text-caption text-secondary-dark">
            <flux:icon.link variant="micro" class="size-3.5" />
            {{ trans_choice('recruitment-applicant.interviews.board.thread', $threadCount, ['count' => $threadCount]) }}
        </p>
    @endif

    {{-- Round track: which of the candidate's rounds this is, and where the others stand. Logical start/end, so it mirrors in RTL. --}}
    <ol class="pointer-events-none isolate mt-3 grid grid-cols-3" aria-label="{{ __('recruitment-applicant.interviews.board.rounds') }}">
        @foreach ($rounds as $round)
            <li @class([
                'relative flex flex-col items-center gap-1',
                'before:absolute before:start-0 before:end-1/2 before:top-2 before:h-px before:bg-neutral-200' => ! $loop->first,
                'after:absolute after:start-1/2 after:end-0 after:top-2 after:h-px after:bg-neutral-200' => ! $loop->last,
            ])>
                <span class="relative z-1 flex h-4 items-center justify-center" aria-hidden="true">
                    @if ($round['this'])
                        <span class="size-3 rounded-full ring-2 ring-neutral-900 ring-offset-2 ring-offset-white {{ RecruitmentInterviewStatus::dotClasses($round['status']) }}"></span>
                    @elseif ($round['status'] !== null)
                        <span class="size-2.5 rounded-full {{ RecruitmentInterviewStatus::dotClasses($round['status']) }}"></span>
                    @else
                        <span class="size-2.5 rounded-full border border-dashed border-neutral-300 bg-white"></span>
                    @endif
                </span>
                <span aria-hidden="true" @class([
                    'text-caption',
                    'font-semibold text-neutral-900' => $round['this'],
                    'text-secondary-medium' => ! $round['this'],
                ])>{{ __('recruitment-applicant.interviews.board.rounds_short.'.$round['type']) }}</span>
                <span class="sr-only">
                    {{ __('recruitment-applicant.interviews.types.'.$round['type']) }}:
                    @if ($round['this'])
                        {{ __('recruitment-applicant.interviews.board.round_this') }}
                    @elseif ($round['status'] !== null)
                        {{ __('recruitment-applicant.interviews.statuses.'.$round['status']) }}
                    @else
                        {{ __('recruitment-applicant.interviews.board.round_none') }}
                    @endif
                </span>
            </li>
        @endforeach
    </ol>

    <div class="mt-3 flex items-center justify-between gap-2 border-t border-neutral-100 pt-2.5">
        {{-- Where it is held. Never the meeting link: that is only on the interview page, behind its own permission. --}}
        <p class="flex min-w-0 items-center gap-1 text-small text-secondary-medium">
            @if ($interview->location_type === RecruitmentInterviewLocationType::Online)
                <flux:icon.video-camera variant="micro" class="size-3.5 shrink-0" />
                <span class="truncate">
                    {{ __('recruitment-applicant.interviews.location_types.online') }}@if ($interview->location_value) &middot; {{ $interview->location_value }}@endif
                </span>
            @else
                <flux:icon.map-pin variant="micro" class="size-3.5 shrink-0" />
                <span class="truncate">{{ $interview->location_value ?: __('recruitment-applicant.interviews.location_types.in_person') }}</span>
            @endif
        </p>

        @if ($panel->isNotEmpty())
            {{-- Above the stretched link, so the names show on hover. --}}
            <div class="relative flex shrink-0 items-center gap-1" title="{{ $panelNames }}">
                <span class="flex -space-x-1.5" aria-hidden="true">
                    @foreach ($panel->take(3) as $member)
                        <x-recruitment.monogram :name="$member['name']" tone="staff" :self="$member['self']" :class="$member['self'] ? '' : 'ring-2 ring-white'" />
                    @endforeach
                </span>
                @if ($panel->count() > 3)
                    <span class="rounded-inset bg-neutral-100 px-1 text-caption font-semibold tabular-nums text-secondary-dark" aria-hidden="true">+{{ $panel->count() - 3 }}</span>
                @endif
                <span class="sr-only">{{ __('recruitment-applicant.interviews.board.panel') }}: {{ $panelNames }}</span>
            </div>
        @endif
    </div>

    @if ($lane === 'awaiting')
        @if ($pending)
            <div class="mt-2.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                <a href="{{ $showUrl }}#my-evaluation" wire:navigate
                   class="relative z-10 inline-flex min-h-11 items-center gap-1.5 rounded-control bg-neutral-900 px-3 text-small font-semibold text-white transition duration-150 hover:bg-neutral-800 motion-reduce:transition-none sm:min-h-8 {{ $focusRing }}">
                    {{ __('recruitment-applicant.interviews.board.evaluate') }}
                    <flux:icon.arrow-right variant="micro" class="size-3.5 rtl:rotate-180" />
                </a>
                <span class="text-small text-secondary-medium">{{ __('recruitment-applicant.evaluations.my_pending') }}</span>
            </div>
        @elseif ($submitted)
            <p class="mt-2.5 flex items-center gap-1 text-small text-secondary-dark">
                <flux:icon.check-circle variant="micro" class="size-3.5 text-neutral-700" />
                {{ __('recruitment-applicant.evaluations.my_submitted') }}
            </p>
        @endif

        @if ($interview->isOpen())
            <p class="mt-1.5 text-small text-secondary-medium">{{ __('recruitment-applicant.interviews.board.outcome_pending') }}</p>
        @endif
    @endif

    @if ($lane === 'closed' && $status === RecruitmentInterviewStatus::Cancelled && $interview->cancellation_reason)
        <p class="mt-2 text-small text-secondary-medium">{{ Lang::has($reasonKey) ? __($reasonKey) : $interview->cancellation_reason }}</p>
    @endif
</li>
