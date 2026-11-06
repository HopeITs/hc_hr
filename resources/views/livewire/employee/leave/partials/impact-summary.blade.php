{{-- Live leave-impact summary beside the dates (A3 step 2).
     $impact comes from MyLeaveRequestModule::leaveImpact(): strings already
     worded and priced on the server by LeaveRequestPricing. Hours and dates
     only — never pay. Shown to the requester only.
     $context (optional): what was chosen — category, icon, subtype, period —
     so the summary reads as one card in the guided request form. --}}
@php $context = $context ?? []; @endphp
@if(! $impact['ready'])
    <p class="rounded-control border border-dashed border-line bg-soft px-3 py-2 text-small text-ink-2"
       data-testid="leave-impact-hint">
        @lang('leave_quote.preview.hint')
    </p>
@else
    <section aria-labelledby="leave-impact-title"
             data-testid="leave-impact"
             class="space-y-3 rounded-surface border border-line-soft bg-soft p-3 sm:p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            {{-- In the guided form the step heading already says "Summary": the title stays for screen readers. --}}
            <h3 id="leave-impact-title" @class(['font-semibold text-ink', 'sr-only' => filled($context['category'] ?? null)])>@lang('leave_quote.preview.title')</h3>
            <p class="text-caption text-ink-2">@lang('leave_quote.preview.estimate')</p>
        </div>

        @if(filled($context['category'] ?? null))
            <div class="flex items-start gap-3 rounded-control bg-card p-2.5 ring-1 ring-line-soft" data-testid="leave-impact-choice">
                @if(filled($context['icon'] ?? null))
                    <span class="grid size-9 shrink-0 place-items-center rounded-round bg-soft text-ink">
                        <x-ic :name="$context['icon']" class="size-5" />
                    </span>
                @endif
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 font-semibold text-ink">
                        <span>{{ $context['category'] }}</span>
                        @if(filled($context['subtype'] ?? null))
                            <x-tag variant="soft" :label="$context['subtype']" />
                        @endif
                    </p>
                    @if(filled($context['period'] ?? null))
                        <p class="mt-0.5 text-small text-ink-2 num">
                            <span class="sr-only">@lang('leaves.request.period'):</span>
                            <bdi>{{ $context['period'] }}</bdi>
                        </p>
                    @endif
                </div>
            </div>
        @endif

        {{-- One short spoken line per change instead of the whole panel. --}}
        <p class="sr-only" aria-live="polite" aria-atomic="true">
            @lang('leave_quote.preview.requested'): {{ $impact['requested'] }}.
            @if($impact['entitled'])
                @lang('leave_quote.preview.projected'): {{ $impact['projected'] }}.
            @endif
        </p>

        @if($impact['entitled'])
            <dl @class(['grid grid-cols-2 gap-2', 'sm:grid-cols-4' => $impact['has_pending'], 'sm:grid-cols-3' => ! $impact['has_pending']])>
                <div class="rounded-control bg-card p-2 ring-1 ring-line-soft">
                    <dt class="text-caption text-ink-2">@lang('leave_quote.preview.available')</dt>
                    <dd class="mt-0.5 text-small font-semibold text-ink"><bdi>{{ $impact['available'] }}</bdi></dd>
                </div>
                {{-- Pending requests reserve balance; shown only when there are some. --}}
                @if($impact['has_pending'])
                    <div class="rounded-control bg-card p-2 ring-1 ring-line-soft">
                        <dt class="text-caption text-ink-2">@lang('leave_quote.preview.pending')</dt>
                        <dd class="mt-0.5 text-small font-semibold text-ink"><bdi>{{ $impact['pending'] }}</bdi></dd>
                    </div>
                @endif
                <div class="rounded-control bg-card p-2 ring-1 ring-line-soft">
                    <dt class="text-caption text-ink-2">@lang('leave_quote.preview.requested')</dt>
                    <dd class="mt-0.5 text-small font-semibold text-ink" data-testid="leave-impact-requested">
                        <bdi>{{ $impact['requested'] }}</bdi>
                    </dd>
                    <dd class="text-caption text-ink-2">
                        @unless($impact['hourly'])
                            <span class="block">{{ __('leave_quote.preview.'.($impact['calendar_days'] ? 'calendar_days' : 'working_days')) }}: <bdi>{{ $impact['counted_days'] }}</bdi></span>
                        @endunless
                        <span class="block">@lang('leave_quote.preview.in_hours'): <bdi>{{ $impact['requested_hours'] }}</bdi></span>
                    </dd>
                </div>
                <div @class([
                        'col-span-2 rounded-control p-2 ring-1 sm:col-span-1',
                        'bg-card ring-2 ring-primary' => $impact['short'],
                        'bg-card ring-line-soft' => ! $impact['short'],
                    ])>
                    <dt class="text-caption text-ink-2">@lang('leave_quote.preview.projected')</dt>
                    <dd @class([
                            'mt-0.5 text-small font-semibold',
                            'text-primary' => $impact['short'],
                            'text-ink' => ! $impact['short'],
                        ])><bdi>{{ $impact['projected'] }}</bdi></dd>
                </div>
            </dl>

            {{-- A slim bar: what is left of the available balance after this request. --}}
            @php
                $availableHours = (float) ($impact['available_hours'] ?? 0);
                $leftPercent = $availableHours > 0
                    ? (int) round(max(0, min(1, ((float) ($impact['projected_hours'] ?? 0)) / $availableHours)) * 100)
                    : 0;
            @endphp
            @if($availableHours > 0)
                <div data-testid="leave-impact-bar">
                    <div class="h-1.5 overflow-hidden rounded-round bg-line-soft"
                         role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $leftPercent }}"
                         aria-label="{{ __('leaves.request.balance') }}">
                        <div @class(['h-full rounded-round', 'bg-ink' => ! $impact['short'], 'bg-primary' => $impact['short']])
                             style="width: {{ $impact['short'] ? 100 : $leftPercent }}%"></div>
                    </div>
                    @if($impact['short'])
                        <p class="mt-1 text-caption font-semibold text-primary"><bdi>{{ $impact['projected'] }}</bdi></p>
                    @else
                        <p class="mt-1 text-caption text-ink-2">
                            {{ __('leaves.request.balance-after', ['after' => $impact['projected'], 'available' => $impact['available']]) }}
                        </p>
                    @endif
                </div>
            @endif
        @endif

        @if($impact['excluded'] !== [])
            <div>
                <p class="text-caption font-medium text-ink">@lang('leave_quote.preview.excluded')</p>
                <ul class="mt-1 flex flex-wrap gap-1.5">
                    @foreach(array_slice($impact['excluded'], 0, 6) as $excluded)
                        <li class="rounded-full bg-card px-2 py-0.5 text-caption text-ink ring-1 ring-line">
                            <bdi dir="ltr">{{ $excluded['date'] }}</bdi>
                            <span>{{ $excluded['day'] }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ $excluded['reason'] }}</span>
                        </li>
                    @endforeach
                    @if(count($impact['excluded']) > 6)
                        <li class="px-1 py-0.5 text-caption text-ink-2">
                            {{ __('leave_quote.preview.more_excluded', ['count' => count($impact['excluded']) - 6]) }}
                        </li>
                    @endif
                </ul>
            </div>
        @endif

        @if($impact['errors'] !== [])
            <div class="rounded-control border-s-4 border-primary bg-card p-2" data-testid="leave-impact-errors">
                <p class="flex items-center gap-1.5 text-small font-bold text-primary"><x-ic name="alert" class="size-4" />@lang('leave_quote.preview.errors')</p>
                <ul class="mt-1 list-disc space-y-0.5 ps-5 text-small text-ink">
                    @foreach($impact['errors'] as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($impact['warnings'] !== [])
            <div class="rounded-control border-s-4 border-ink bg-soft p-2" data-testid="leave-impact-warnings">
                <p class="flex items-center gap-1.5 text-small font-bold text-ink"><x-ic name="warning" class="size-4" />@lang('leave_quote.preview.warnings')</p>
                <ul class="mt-1 list-disc space-y-0.5 ps-5 text-small text-ink">
                    @foreach($impact['warnings'] as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- C5: who else is away, from TeamCoverage (the team calendar's reader).
             A count only, never names; advisory, never an error (§4.5). --}}
        @php $team = $impact['team'] ?? null; @endphp
        @if($team && ($team['colleagues'] > 0 || $team['manager_away']))
            <div class="rounded-control border-s-4 border-ink bg-card p-2 ring-1 ring-line-soft" data-testid="leave-impact-team" role="note">
                <ul class="space-y-0.5 text-small text-ink">
                    @if($team['colleagues'] > 0)
                        <li data-testid="leave-impact-team-count">{{ trans_choice('team_calendar.hint.colleagues_away', $team['colleagues'], ['count' => $team['colleagues']]) }}</li>
                    @endif
                    @if($team['manager_away'])
                        <li data-testid="leave-impact-manager-away">{{ __('team_calendar.hint.manager_away') }}</li>
                    @endif
                </ul>
                <p class="mt-1 text-caption text-ink-2">{{ __('team_calendar.hint.advisory') }}</p>
            </div>
        @endif

        <details class="text-small text-ink">
            <summary class="inline-flex min-h-11 cursor-pointer items-center rounded-control font-semibold text-ink underline underline-offset-4">
                @lang('leave_quote.preview.how')
            </summary>
            <ul class="mt-1 space-y-0.5">
                @foreach($impact['explanation'] as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </details>
    </section>
@endif
