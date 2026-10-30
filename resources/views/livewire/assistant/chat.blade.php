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
        submit(text) {
            if (this.pending) return;
            if (typeof text === 'string') $wire.$set('question', text, false);
            const question = String($wire.question ?? '').trim();
            if (! question) { $wire.send(); return; }
            this.pending = question;
            this.$nextTick(() => this.scrollToEnd());
            $wire.send().finally(() => {
                this.pending = '';
                this.$nextTick(() => requestAnimationFrame(() => this.scrollToEnd()));
            });
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
        <div class="min-h-0 flex-1 overflow-y-auto px-3 py-3" data-csb-exclude>
            <p class="mb-3 flex items-start gap-2 text-caption text-ink-3" data-testid="assistant-disclaimer">
                <x-ic name="info" class="mt-0.5 size-3.5 shrink-0" />
                <span>{{ __('assistant.disclaimer') }}</span>
            </p>

            @if ($messages !== [])
                <div class="mb-3 flex justify-end">
                    <x-button variant="quiet" size="sm" icon="reset" wire:click="clear">{{ __('assistant.clear') }}</x-button>
                </div>
            @endif

            @include('livewire.assistant.partials.conversation')
            <div x-ref="end" class="h-px" aria-hidden="true"></div>
        </div>

        <div class="border-t border-line-soft p-2">
            @include('livewire.assistant.partials.question-form')
        </div>
    @else
        <x-page-header :title="__('assistant.title')" :lead="__('assistant.lead')">
            @if ($messages !== [])
                <x-slot:actions>
                    <x-button variant="outline" size="sm" icon="reset" wire:click="clear">
                        {{ __('assistant.clear') }}
                    </x-button>
                </x-slot:actions>
            @endif
        </x-page-header>

        {{-- AI answers can be wrong: HR has the final word. --}}
        <p class="mb-4 flex items-start gap-2 rounded-surface border border-line-soft bg-soft px-4 py-3 text-small text-ink-2" data-testid="assistant-disclaimer">
            <x-ic name="info" class="mt-0.5 text-ink-3" />
            <span>{{ __('assistant.disclaimer') }}</span>
        </p>

        @include('livewire.assistant.partials.conversation')

        <div class="mt-6 rounded-surface border border-line-soft bg-card p-3 shadow-e1">
            @include('livewire.assistant.partials.question-form')
        </div>

        <div x-ref="end" class="h-px" aria-hidden="true"></div>
    @endif
</div>
