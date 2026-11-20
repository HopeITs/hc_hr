{{--
    My first days (MyFirstDays): the volunteer's own onboarding journey. What
    asks something of them comes first; then every step with its status (icon
    + words, never colour), who does it and when.
--}}
@php
    $date = fn ($value) => $value?->timezone(config('app.timezone'))->translatedFormat('l d M Y');
    $total = $steps->count();
@endphp

<div class="mx-auto max-w-3xl space-y-6">
    <x-page-header :title="__('onboarding.first-days.title')" :lead="__('onboarding.first-days.lead')" />

    @if ($steps->isEmpty())
        <x-card><p class="text-body text-ink-2">@lang('onboarding.first-days.none')</p></x-card>
    @else
        {{-- Where they stand, in one line. --}}
        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-3" data-testid="first-days-progress" data-tour="first-days.progress">
                <p class="font-semibold text-ink">
                    @if ($journey?->completed_at)
                        @lang('onboarding.first-days.complete')
                    @else
                        {{ __('onboarding.first-days.progress', ['done' => $done, 'total' => $total]) }}
                    @endif
                </p>
                <span class="block h-2 w-40 overflow-hidden rounded-full bg-surface" aria-hidden="true"><span class="block h-full rounded-full bg-ink" style="inline-size: {{ $total > 0 ? (int) round($done / $total * 100) : 0 }}%"></span></span>
            </div>
        </x-card>

        @if ($yours->isNotEmpty())
            <section aria-labelledby="first-days-yours" class="rounded-surface border border-s-4 border-line-soft border-s-primary bg-card p-4" data-testid="first-days-yours" data-tour="first-days.yours">
                <h2 id="first-days-yours" class="font-semibold text-ink">{{ trans_choice('onboarding.first-days.yours-title', $yours->count(), ['count' => $yours->count()]) }}</h2>
                <ul class="mt-3 space-y-2">
                    @foreach ($yours as $step)
                        <li class="flex flex-wrap items-center justify-between gap-2" wire:key="yours-{{ $step['key'] }}">
                            <span class="text-ink">@lang('onboarding.first-days.step.'.$step['key'])</span>
                            @if ($step['view']['action'])
                                <x-button size="sm" :href="$step['view']['action']['url']">{{ $step['view']['action']['label'] }}</x-button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <x-card>
            <h2 class="font-semibold text-ink" data-tour="first-days.steps">@lang('onboarding.first-days.steps-title')</h2>
            <ol class="mt-2 divide-y divide-line-soft">
                @foreach ($steps as $index => $step)
                    @php $view = $step['view']; @endphp
                    <li class="grid gap-1 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:gap-3" data-step="{{ $step['key'] }}" wire:key="first-day-{{ $step['key'] }}">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                                <span class="num text-ink-3" aria-hidden="true">{{ $index + 1 }}.</span>
                                <span>@lang('onboarding.first-days.step.'.$step['key'])</span>
                                @if ($step['late'])
                                    <x-tag variant="late" :label="__('employees.journey.late')" />
                                @endif
                            </p>
                            <p class="mt-0.5 text-small text-ink-2">@lang('onboarding.first-days.about.'.$step['key'])</p>
                            @if ($view['date'])
                                <p class="mt-0.5 text-small text-ink-2">{{ $view['date_label'] }}: <span class="num">{{ $date($view['date']) }}</span></p>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-start gap-2 sm:justify-end">
                            <x-status :tone="$view['tone']" :icon="$view['icon']" :label="$view['label']" />
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-card>

        <p class="text-small text-ink-2">@lang('onboarding.first-days.help')</p>
    @endif
</div>
