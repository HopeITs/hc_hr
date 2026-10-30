{{--
    A departing volunteer's exit journey (DEC-09 increment 2, docs/ux/journeys.md §3.3).
    One row per step: status (icon + words + fill, never colour), who acts,
    due date, what blocks it, and the one action HR can take now. Every step
    but the exit interview is read from its own record. Nothing here is a
    permission: every action re-checks on the server.
--}}
@php
    use App\Services\OffboardingJourneys;

    $locale = app()->getLocale();
    $date = fn ($value) => $value?->timezone(config('app.timezone'))->translatedFormat('d M Y');
    $by = fn ($user) => $user?->profile?->nameForLocale($locale) ?? $user?->email ?? __('general.unknown');

    $tones = [
        OffboardingJourneys::STATE_DONE => ['done', 'check-circle'],
        OffboardingJourneys::STATE_TODO => ['attn', 'alert'],
        OffboardingJourneys::STATE_WAITING => ['wait', 'clock'],
        OffboardingJourneys::STATE_PROBLEM => ['stop', 'warning'],
    ];

    $required = collect($steps ?? [])->whereIn('key', OffboardingJourneys::REQUIRED_STEPS);
    $doneCount = $required->where('state', OffboardingJourneys::STATE_DONE)->count();
@endphp

<div>
    @if ($steps)
        <section data-testid="offboarding-journey" aria-labelledby="offboarding-journey-title"
            class="mb-4 rounded-control border border-line-soft bg-card p-4 text-body">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="offboarding-journey-title" class="font-semibold text-ink">@lang('offboarding.title')</h2>
                <p class="text-small text-ink-2">
                    @if ($journey?->completed_at)
                        {{ __('offboarding.completed-on', ['date' => $date($journey->completed_at)]) }}
                    @elseif ($calledOff)
                        <x-status tone="stop" icon="warning" :label="__('offboarding.called-off')" />
                    @else
                        {{ __('offboarding.progress', ['done' => $doneCount, 'total' => $required->count()]) }}
                    @endif
                </p>
            </div>

            @if ($calledOff)
                <p class="mt-2 text-small text-ink-2" data-testid="offboarding-called-off">@lang('offboarding.called-off-help')</p>
            @else
                <ol class="mt-3 divide-y divide-line-soft">
                    @foreach ($steps as $index => $step)
                        @php
                            [$tone, $icon] = $tones[$step['state']];
                            $key = $step['key'];
                            $isDone = $step['state'] === OffboardingJourneys::STATE_DONE;
                        @endphp
                        <li class="grid gap-2 py-3 sm:grid-cols-[minmax(0,1fr)_auto]" data-step="{{ $key }}" data-state="{{ $step['state'] }}">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                                    <span class="num text-ink-3" aria-hidden="true">{{ $index + 1 }}.</span>
                                    <span>@lang("offboarding.step.{$key}")</span>
                                    <x-status :tone="$tone" :icon="$icon" :label="__('offboarding.state.'.$step['state'])" />
                                    @if ($step['data']['optional'] ?? false)
                                        <x-tag variant="soft" :label="__('offboarding.optional')" />
                                    @endif
                                    @if ($step['late'])
                                        <x-tag variant="late" :label="__('offboarding.late')" />
                                    @endif
                                </p>

                                <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-small text-ink-2">
                                    <div class="flex gap-1">
                                        <dt>@lang('offboarding.owner'):</dt>
                                        <dd>@lang('offboarding.owner-'.$step['owner'])</dd>
                                    </div>
                                    @if ($step['due'])
                                        <div class="flex gap-1">
                                            <dt>@lang($key === 'contract' ? 'offboarding.ends' : 'offboarding.due'):</dt>
                                            <dd class="num">{{ $date($step['due']) }}</dd>
                                        </div>
                                    @endif
                                    @if ($step['blocked_by'])
                                        <div class="flex gap-1">
                                            <dt>@lang('offboarding.after'):</dt>
                                            <dd>@lang('offboarding.step.'.$step['blocked_by'])</dd>
                                        </div>
                                    @endif
                                </dl>

                                {{-- What this step is waiting on, or how it was done. --}}
                                @switch($key)
                                    @case('equipment')
                                        @if (! $isDone)
                                            <p class="mt-1 text-small text-ink-2">
                                                {{ trans_choice('offboarding.equipment-outstanding', $step['data']['outstanding'], ['count' => $step['data']['outstanding']]) }}
                                                @if ($step['data']['unrequested'] > 0)
                                                    {{ trans_choice('offboarding.equipment-unrequested', $step['data']['unrequested'], ['count' => $step['data']['unrequested']]) }}
                                                @endif
                                            </p>
                                        @endif
                                        @break

                                    @case('deactivated')
                                        @if ($step['note'])
                                            <p class="mt-1 text-small text-ink-2">@lang('offboarding.'.$step['note'])</p>
                                        @elseif (! $isDone && $step['data']['deactivate_on'])
                                            <p class="mt-1 text-small text-ink-2">{{ __('offboarding.deactivation-help', ['date' => $date($step['data']['deactivate_on'])]) }}</p>
                                        @endif
                                        @break

                                    @case('access')
                                        @if ($step['note'])
                                            <p class="mt-1 text-small text-ink-2">@lang('offboarding.'.$step['note'])</p>
                                        @endif
                                        @break

                                    @case('interview')
                                        @if ($isDone)
                                            <p class="mt-1 text-small text-ink-2">{{ __('offboarding.interview-done-by', ['date' => $date($step['data']['held_at']), 'name' => $by($step['data']['held_by'])]) }}</p>
                                        @else
                                            <p class="mt-1 text-small text-ink-2">@lang('offboarding.interview-help')</p>
                                        @endif
                                        @break
                                @endswitch
                            </div>

                            {{-- The one action for this step, if HR has one now. --}}
                            <div class="flex flex-wrap items-start gap-2 sm:justify-end">
                                @if ($key === 'contract' && $step['data']['contract_id'] && $canViewContracts)
                                    <x-button variant="link" size="sm" :href="route('contracts.show', $step['data']['contract_id'])">@lang('offboarding.open-contract')</x-button>
                                @elseif ($key === 'interview')
                                    @if ($isDone)
                                        <x-button variant="quiet" size="sm" wire:click="confirmReopen('interview')">@lang('offboarding.reopen')</x-button>
                                    @else
                                        <x-button variant="outline" size="sm" icon="check" wire:click="markExitInterviewHeld" data-testid="exit-interview-held">@lang('offboarding.interview-action')</x-button>
                                    @endif
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    @endif
</div>
