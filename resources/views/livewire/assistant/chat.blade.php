{{--
    The assistant (App\Livewire\Assistant\Chat), in one of two modes:
      page  — the full page (route `assistant`): header, disclaimer, conversation, question box;
      panel — inside the floating widget (<x-assistant.widget>): the same, compact and scrolling.
    The conversation comes from the session, so both show the same one.
--}}
@php
    $isPanel = $mode === 'panel';
@endphp

<div
    @class([
        'flex min-h-0 flex-1 flex-col' => $isPanel,
        'mx-auto w-full max-w-3xl' => ! $isPanel,
    ])
    @unless ($isPanel) data-assistant-page @endunless
    data-mode="{{ $mode }}"
    x-data="{
        pending: '',
        history: false,
        {{-- A relayed round waiting for the employee to sign in to Puter. --}}
        signIn: null,
        puterScript: @js(config('assistant.puter.script')),
        async submit(text) {
            if (this.pending) return;
            if (typeof text === 'string') $wire.$set('question', text, false);
            const question = String($wire.question ?? '').trim();
            if (! question) { $wire.send(); return; }
            this.pending = question;
            this.history = false;
            this.$nextTick(() => this.scrollToEnd());
            try {
                await this.follow(await $wire.send());
            } finally {
                this.settle();
            }
        },
        {{-- When the server hands the question over, ask Puter round by round. --}}
        async follow(result) {
            let relay = result?.relay;
            while (relay) {
                const puter = window.hcAssistantPuter;
                let message;
                try {
                    if (! await puter.isSignedIn(this.puterScript)) {
                        this.signIn = relay;
                        this.$nextTick(() => this.scrollToEnd());
                        return;
                    }
                    message = await puter.chat(this.puterScript, relay);
                } catch (error) {
                    await $wire.relayFailed(relay.id, puter?.reason(error) ?? 'failed');
                    return;
                }
                relay = (await $wire.relayStep(relay.id, message))?.relay;
            }
        },
        async signInAndContinue() {
            const relay = this.signIn;
            if (! relay) return;
            try {
                await window.hcAssistantPuter.signIn(this.puterScript);
                this.signIn = null;
                await this.follow({ relay });
            } catch (error) {
                this.signIn = null;
                await $wire.relayFailed(relay.id, 'signin');
            } finally {
                this.settle();
            }
        },
        abandon() {
            const relay = this.signIn;
            this.signIn = null;
            this.settle();
            if (relay) $wire.relayFailed(relay.id, 'signin');
        },
        {{-- After an answer: show it, scroll to it and put the cursor back in the box. --}}
        settle() {
            if (this.signIn) return;
            this.pending = '';
            this.$nextTick(() => requestAnimationFrame(() => {
                this.scrollToEnd();
                this.$el.querySelector('textarea')?.focus({ preventScroll: true });
            }));
        },
        scrollToEnd(smooth = true) {
            this.$refs.end?.scrollIntoView({ block: 'end', behavior: smooth ? 'smooth' : 'auto' });
        },
    }"
    @if ($isPanel)
        x-init="$nextTick(() => { $el.querySelector('textarea')?.focus({ preventScroll: true }); scrollToEnd(false) })"
        {{-- The page may have moved the conversation on since the panel last showed it. --}}
        x-on:assistant-panel-opened.window="$wire.$refresh().then(() => $nextTick(() => scrollToEnd(false)))"
    @endif
>
    @if ($isPanel)
        <div class="flex items-center gap-2 border-b border-line-soft px-3 py-1.5">
            <x-button variant="quiet" size="sm" icon="plus" wire:click="clear" x-on:click="history = false" data-testid="assistant-new">{{ __('assistant.clear') }}</x-button>
            <x-button variant="link" size="sm" icon="clock" class="ms-auto" x-on:click="history = ! history" x-bind:aria-expanded="history.toString()" aria-expanded="false" data-testid="assistant-history-toggle">{{ __('assistant.history.title') }}</x-button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-3 py-3" data-csb-exclude>
            @include('livewire.assistant.partials.history')

            <div x-show="! history">
                <p class="mb-3 flex items-start gap-2 text-caption text-ink-3" data-testid="assistant-disclaimer">
                    <x-ic name="info" class="mt-0.5 size-3.5 shrink-0" />
                    <span>{{ __('assistant.disclaimer') }}</span>
                    @if ($usesPuter)
                        <a href="https://developer.puter.com" target="_blank" rel="noopener" class="underline">{{ __('assistant.puter.credit') }}</a>
                    @endif
                </p>

                @include('livewire.assistant.partials.conversation')
            </div>
            <div x-ref="end" class="h-px" aria-hidden="true"></div>
        </div>

        <div class="border-t border-line-soft p-2">
            @include('livewire.assistant.partials.question-form')
        </div>
    @else
        <x-page-header :title="__('assistant.title')" :lead="__('assistant.lead')">
            <x-slot:actions>
                <x-button variant="outline" size="sm" icon="clock" x-on:click="history = ! history" x-bind:aria-expanded="history.toString()" aria-expanded="false" data-testid="assistant-history-toggle">
                    {{ __('assistant.history.title') }}
                </x-button>
                <x-button variant="outline" size="sm" icon="plus" wire:click="clear" x-on:click="history = false" data-testid="assistant-new">
                    {{ __('assistant.clear') }}
                </x-button>
            </x-slot:actions>
        </x-page-header>

        <div class="mb-4">
            @include('livewire.assistant.partials.history')
        </div>

        <div x-show="! history">
            {{-- AI answers can be wrong: HR has the final word. --}}
            <p class="mb-4 flex items-start gap-2 rounded-surface border border-line-soft bg-soft px-4 py-3 text-small text-ink-2" data-testid="assistant-disclaimer">
                <x-ic name="info" class="mt-0.5 text-ink-3" />
                <span>{{ __('assistant.disclaimer') }}</span>
                @if ($usesPuter)
                    <a href="https://developer.puter.com" target="_blank" rel="noopener" class="underline">{{ __('assistant.puter.credit') }}</a>
                @endif
            </p>

            @include('livewire.assistant.partials.conversation')
        </div>

        <div class="mt-6 rounded-surface border border-line-soft bg-card p-3 shadow-e1">
            @include('livewire.assistant.partials.question-form')
        </div>

        <div x-ref="end" class="h-px" aria-hidden="true"></div>
    @endif
</div>
