{{--
    The employee's renewal journeys (DEC-09 increment 2, docs/ux/journeys.md
    §3.2). One block per expiring contract, one row per step: status (icon +
    words + fill, never colour), who acts, due date, what it waits on, and the
    one action HR can take now. Nothing here is a permission: every action
    re-checks on the server. No pay figure is read or shown.
--}}
@php
    use App\Models\RenewalJourney;
    use App\Services\RenewalJourneys;

    $locale = app()->getLocale();
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->translatedFormat('d M Y') : null;
    $by = fn ($user) => $user?->profile?->nameForLocale($locale) ?? $user?->email ?? __('general.unknown');

    $tones = [
        RenewalJourneys::STATE_DONE => ['done', 'check-circle'],
        RenewalJourneys::STATE_TODO => ['attn', 'alert'],
        RenewalJourneys::STATE_WAITING => ['wait', 'clock'],
        RenewalJourneys::STATE_PROBLEM => ['stop', 'warning'],
        RenewalJourneys::STATE_SKIPPED => ['stop', 'minus'],
    ];
@endphp

<div>
    @foreach ($rows as $row)
        @php
            $journey = $row['journey'];
            $contract = $journey->contract;
            $steps = $row['steps'];
            $doneCount = collect($steps)->whereIn('state', [RenewalJourneys::STATE_DONE, RenewalJourneys::STATE_SKIPPED])->count();
            $titleId = 'renewal-journey-title-'.$journey->id;
        @endphp
        <section data-testid="renewal-journey" data-journey="{{ $journey->id }}" aria-labelledby="{{ $titleId }}"
            wire:key="renewal-journey-{{ $journey->id }}"
            class="mb-4 rounded-control border border-line-soft bg-card p-4 text-body">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="{{ $titleId }}" class="font-semibold text-ink">
                    {{ __('renewal.title', ['position' => $contract->position?->title ?? __('general.unknown'), 'date' => $date($contract->end_date)]) }}
                </h2>
                <p class="text-small text-ink-2">
                    @if ($journey->completed_at)
                        {{ __('renewal.completed-on', ['date' => $date($journey->completed_at)]) }}
                    @else
                        {{ __('renewal.progress', ['done' => $doneCount, 'total' => count($steps)]) }}
                    @endif
                </p>
            </div>

            <ol class="mt-3 divide-y divide-line-soft">
                @foreach ($steps as $index => $step)
                    @php
                        [$tone, $icon] = $tones[$step['state']];
                        $key = $step['key'];
                        $data = $step['data'];
                    @endphp
                    <li class="grid gap-2 py-3 sm:grid-cols-[minmax(0,1fr)_auto]" data-step="{{ $key }}" data-state="{{ $step['state'] }}">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                                <span class="num text-ink-3" aria-hidden="true">{{ $index + 1 }}.</span>
                                <span>@lang("renewal.step.{$key}")</span>
                                <x-status :tone="$tone" :icon="$icon" :label="__('renewal.state.'.$step['state'])" />
                                @if ($step['late'])
                                    <x-tag variant="late" :label="__('renewal.late')" />
                                @endif
                            </p>

                            <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-small text-ink-2">
                                <div class="flex gap-1">
                                    <dt>@lang('renewal.owner'):</dt>
                                    <dd>@lang('renewal.owner-'.$step['owner'])</dd>
                                </div>
                                @if ($step['due'])
                                    <div class="flex gap-1">
                                        <dt>@lang('renewal.due'):</dt>
                                        <dd class="num">{{ $date($step['due']) }}</dd>
                                    </div>
                                @endif
                                @if ($step['blocked_by'])
                                    <div class="flex gap-1">
                                        <dt>@lang('renewal.after'):</dt>
                                        <dd>@lang('renewal.step.'.$step['blocked_by'])</dd>
                                    </div>
                                @endif
                            </dl>

                            {{-- How this step was done, or what it waits on. --}}
                            @switch($key)
                                @case('warning')
                                    @if ($data['last_sent_on'])
                                        <p class="mt-1 text-small text-ink-2">{{ __('renewal.warning-sent', ['date' => $date($data['last_sent_on']), 'count' => $data['count']]) }}</p>
                                    @elseif ($step['note'])
                                        <p class="mt-1 text-small text-ink-2">@lang('renewal.note.'.$step['note'])</p>
                                    @endif
                                    @break

                                @case('decision')
                                    @if ($data['decision'])
                                        <p class="mt-1 text-small text-ink-2">{{ __('renewal.decided-by', ['decision' => __('renewal.decision.'.$data['decision']), 'date' => $date($data['decided_at']), 'name' => $by($data['decided_by'])]) }}</p>
                                    @elseif ($step['note'])
                                        <p class="mt-1 text-small text-ink-2">@lang('renewal.note.'.$step['note'])</p>
                                    @else
                                        <p class="mt-1 text-small text-ink-2">{{ $data['auto_renew'] ? __('renewal.auto-renew-on') : __('renewal.auto-renew-off') }}</p>
                                    @endif
                                    @break

                                @case('successor')
                                    @if ($data['contract_id'])
                                        <p class="mt-1 text-small text-ink-2">{{ __('renewal.successor-from', ['date' => $date($data['start_date'])]) }}</p>
                                    @elseif ($step['note'] === 'renews-automatically')
                                        <p class="mt-1 text-small text-ink-2">{{ __('renewal.note.renews-automatically', ['date' => $date($data['auto_renew_on'])]) }}</p>
                                    @elseif ($step['note'])
                                        <p class="mt-1 text-small text-ink-2">@lang('renewal.note.'.$step['note'])</p>
                                    @endif
                                    @break

                                @case('leave')
                                    @if ($step['note'])
                                        <p class="mt-1 text-small text-ink-2">@lang('renewal.note.'.$step['note'])</p>
                                    @endif
                                    @break
                            @endswitch
                        </div>

                        {{-- The one action for this step, if HR has one now. --}}
                        <div class="flex flex-wrap items-start gap-2 sm:justify-end">
                            @if ($key === 'decision')
                                @if ($data['can_decide'])
                                    <x-button size="sm" icon="check" wire:click="decideRenew({{ $journey->id }})" data-testid="renewal-renew">@lang('renewal.renew')</x-button>
                                    @if ($data['can_stop'])
                                        <x-button variant="outline" size="sm" wire:click="confirmStop({{ $journey->id }})" data-testid="renewal-stop">@lang('renewal.stop')</x-button>
                                    @endif
                                @elseif ($data['can_undo'])
                                    <x-button variant="quiet" size="sm" wire:click="confirmUndo({{ $journey->id }})" data-testid="renewal-undo">@lang('renewal.undo')</x-button>
                                @endif
                            @elseif ($key === 'successor')
                                @if ($data['contract_id'] && $canViewContracts)
                                    <x-button variant="link" size="sm" :href="route('contracts.show', $data['contract_id'])">@lang('renewal.open-contract')</x-button>
                                @elseif ($data['can_renew_by_hand'] && $row['canRenew'])
                                    <x-button variant="outline" size="sm" :href="route('contracts.renew', $data['source_id'])" data-testid="renewal-renew-by-hand">@lang('renewal.renew-by-hand')</x-button>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endforeach
</div>
