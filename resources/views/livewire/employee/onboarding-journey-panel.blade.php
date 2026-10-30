{{--
    A new volunteer's onboarding journey (D4-I, DEC-09, docs/ux/journeys.md).
    One row per step: status (icon + words + fill, never colour), who acts,
    due date, what blocks it, and the one action HR can take now. Nothing
    here is a permission: every action re-checks on the server.
--}}
@php
    use App\Services\OnboardingJourneys;

    $locale = app()->getLocale();
    $date = fn ($value) => $value?->timezone(config('app.timezone'))->translatedFormat('d M Y');
    $by = fn ($user) => $user?->profile?->nameForLocale($locale) ?? $user?->email ?? __('general.unknown');

    $tones = [
        OnboardingJourneys::STATE_DONE => ['done', 'check-circle'],
        OnboardingJourneys::STATE_TODO => ['attn', 'alert'],
        OnboardingJourneys::STATE_WAITING => ['wait', 'clock'],
        OnboardingJourneys::STATE_PROBLEM => ['stop', 'warning'],
    ];

    $doneCount = collect($steps ?? [])->where('state', OnboardingJourneys::STATE_DONE)->count();
@endphp

<div>
    @if ($steps)
        <section data-testid="onboarding-journey" aria-labelledby="onboarding-journey-title"
            class="mb-4 rounded-control border border-line-soft bg-card p-4 text-body">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="onboarding-journey-title" class="font-semibold text-ink">@lang('employees.journey.title')</h2>
                <p class="text-small text-ink-2">
                    @if ($journey?->completed_at)
                        {{ __('employees.journey.completed-on', ['date' => $date($journey->completed_at)]) }}
                    @else
                        {{ __('employees.journey.progress', ['done' => $doneCount, 'total' => count($steps)]) }}
                    @endif
                </p>
            </div>

            <ol class="mt-3 divide-y divide-line-soft">
                @foreach ($steps as $index => $step)
                    @php
                        [$tone, $icon] = $tones[$step['state']];
                        $key = $step['key'];
                        $isDone = $step['state'] === OnboardingJourneys::STATE_DONE;
                    @endphp
                    <li class="grid gap-2 py-3 sm:grid-cols-[minmax(0,1fr)_auto]" data-step="{{ $key }}" data-state="{{ $step['state'] }}">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                                <span class="num text-ink-3" aria-hidden="true">{{ $index + 1 }}.</span>
                                <span>@lang("employees.journey.step.{$key}")</span>
                                <x-status :tone="$tone" :icon="$icon" :label="__('employees.journey.state.'.$step['state'])" />
                                @if ($step['late'])
                                    <x-tag variant="late" :label="__('employees.journey.late')" />
                                @endif
                            </p>

                            <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-small text-ink-2">
                                <div class="flex gap-1">
                                    <dt>@lang('employees.journey.owner'):</dt>
                                    <dd>@lang('employees.journey.owner-'.$step['owner'])</dd>
                                </div>
                                @if ($step['due'])
                                    <div class="flex gap-1">
                                        <dt>@lang('employees.journey.due'):</dt>
                                        <dd class="num">{{ $date($step['due']) }}</dd>
                                    </div>
                                @endif
                                @if ($step['blocked_by'])
                                    <div class="flex gap-1">
                                        <dt>@lang('employees.journey.after'):</dt>
                                        <dd>@lang('employees.journey.step.'.$step['blocked_by'])</dd>
                                    </div>
                                @endif
                            </dl>

                            {{-- What this step is waiting on, or how it was done. --}}
                            @switch($key)
                                @case('account')
                                    @if ($step['note'])
                                        <p class="mt-1 text-small text-ink-2">@lang('employees.journey.account-missing')</p>
                                    @endif
                                    @break

                                @case('card')
                                    @if ($isDone)
                                        <p class="mt-1 text-small text-ink-2">{{ __('employees.journey.card-done-by', ['date' => $date($step['data']['handed_over_at']), 'name' => $by($step['data']['handed_over_by'])]) }}</p>
                                    @elseif ($step['note'])
                                        <p class="mt-1 text-small text-ink-2">{{ __($step['note']) }}</p>
                                    @elseif ($step['data']['digital_ready'])
                                        <p class="mt-1 text-small text-ink-2">@lang('employees.journey.card-help')</p>
                                    @endif
                                    @break

                                @case('orientation')
                                    @if ($isDone)
                                        <p class="mt-1 text-small text-ink-2">{{ __('employees.journey.orientation-done-by', ['date' => $date($step['data']['scheduled_for']), 'name' => $by($step['data']['completed_by'])]) }}</p>
                                    @elseif ($step['data']['scheduled_for'])
                                        <p class="mt-1 text-small text-ink-2">{{ __('employees.journey.orientation-on', ['date' => $date($step['data']['scheduled_for'])]) }}</p>
                                    @endif
                                    @break
                            @endswitch
                        </div>

                        {{-- The one action for this step, if HR has one now. --}}
                        <div class="flex flex-wrap items-start gap-2 sm:justify-end">
                            @if ($key === 'contract')
                                @if ($isDone && $step['data']['contract_id'] && $canViewContracts)
                                    <x-button variant="link" size="sm" :href="route('contracts.show', $step['data']['contract_id'])">@lang('employees.journey.open-contract')</x-button>
                                @elseif ($step['state'] === OnboardingJourneys::STATE_TODO && $canCreateContracts)
                                    <x-button variant="outline" size="sm" :href="route('contracts.create')">@lang('employees.onboarding.create-contract')</x-button>
                                @endif
                            @elseif ($key === 'card' && $journey)
                                @if ($isDone)
                                    <x-button variant="quiet" size="sm" wire:click="confirmReopen('card')">@lang('employees.journey.reopen')</x-button>
                                @elseif ($step['state'] === OnboardingJourneys::STATE_TODO)
                                    <x-button variant="outline" size="sm" icon="check" wire:click="markCardHandedOver" data-testid="card-handed-over">@lang('employees.journey.card-action')</x-button>
                                @endif
                            @elseif ($key === 'orientation' && $journey)
                                @if ($isDone)
                                    <x-button variant="quiet" size="sm" wire:click="confirmReopen('orientation')">@lang('employees.journey.reopen')</x-button>
                                @else
                                    <form wire:submit="scheduleOrientation" class="flex flex-wrap items-end gap-2">
                                        <div>
                                            <label for="orientation-date" class="block text-small text-ink-2">@lang('employees.journey.orientation-date')</label>
                                            <x-forms.date-input id="orientation-date" wire:model="orientationDate" class="!w-auto"
                                                aria-describedby="orientation-date-error" :aria-invalid="$errors->has('orientationDate') ? 'true' : 'false'" />
                                        </div>
                                        <x-button variant="outline" size="sm" type="submit" target="scheduleOrientation">
                                            {{ $step['data']['scheduled_for'] ? __('employees.journey.reschedule') : __('employees.journey.schedule') }}
                                        </x-button>
                                        @if ($step['data']['can_complete'])
                                            <x-button size="sm" icon="check" wire:click="completeOrientation" data-testid="orientation-done">@lang('employees.journey.orientation-action')</x-button>
                                        @endif
                                    </form>
                                    <p id="orientation-date-error" class="w-full text-small text-primary" role="alert">@error('orientationDate'){{ $message }}@enderror</p>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif
</div>
