{{--
    The guided flow after a hire: what is done and what is left around the new
    employee (OnboardingChecklist). $steps: key, done, url, problem. Reads and
    links only; each link's page checks its own permission.
--}}
@if ($steps !== [])
    <section class="space-y-3 rounded-surface border border-line-soft bg-card p-6" data-testid="onboarding">
        <div>
            <h2 class="text-h4 font-semibold text-ink">{{ __('recruitment-applicant.conversions.onboarding.title') }}</h2>
            <p class="text-small text-ink-2">{{ __('recruitment-applicant.conversions.onboarding.hint') }}</p>
        </div>

        <ol class="divide-y divide-line-soft">
            @foreach ($steps as $step)
                @php($t = 'recruitment-applicant.conversions.onboarding.')
                <li class="flex flex-wrap items-center justify-between gap-3 py-3" data-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? '1' : '0' }}">
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 font-semibold text-ink">
                            <x-ic :name="$step['done'] ? 'check' : 'clock'" class="size-4 shrink-0 text-ink-2" />
                            {{ __($t.$step['key']) }}
                            <span class="text-caption font-normal text-ink-2">{{ $step['done'] ? __($t.'done') : __($t.'todo') }}</span>
                        </p>
                        @if ($step['key'] === 'account' && ! $step['done'])
                            <p class="text-small text-ink-2">{{ __($t.'account_hint') }}</p>
                        @elseif ($step['key'] === 'card')
                            <p class="text-small text-ink-2">{{ $step['problem'] ?? __($t.'card_ready') }}</p>
                        @elseif ($step['key'] === 'welcome')
                            <p class="text-small text-ink-2">{{ __($t.'welcome_hint') }}</p>
                        @endif
                    </div>

                    <div class="shrink-0">
                        @if ($step['key'] === 'welcome')
                            <x-message-template-menu :employee="$conversion->employee" />
                        @elseif ($step['url'])
                            <x-button variant="outline" size="sm" :href="$step['url']" :wire:navigate="false">
                                {{ $step['done'] ? __($t.'open') : __($t.'fix') }}
                            </x-button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
@endif
