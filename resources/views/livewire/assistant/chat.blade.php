<x-slot:title>
    {{ __('assistant.title') }}
</x-slot:title>

<div
    class="mx-auto w-full max-w-3xl"
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
        scrollToEnd() {
            this.$refs.end?.scrollIntoView({ block: 'end', behavior: 'smooth' });
        },
    }"
>
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

    {{-- The conversation --}}
    <div class="space-y-4" aria-live="polite" aria-relevant="additions" data-testid="assistant-log">
        @if ($messages === [])
            <div x-show="! pending" class="rounded-surface border border-line-soft bg-card px-4 py-8 text-center shadow-e1 sm:px-8" data-state="empty">
                <span class="mx-auto inline-flex size-12 items-center justify-center rounded-full bg-surface text-ink-2" aria-hidden="true">
                    <x-icons.assistant class="size-6" />
                </span>
                <h2 class="mt-3 text-body font-semibold text-ink">{{ __('assistant.empty.title') }}</h2>
                <p class="mt-1 text-small text-ink-2">{{ __('assistant.empty.help') }}</p>

                <ul class="mt-5 grid gap-2 text-start sm:grid-cols-2">
                    @foreach ($suggestions as $suggestion)
                        <li>
                            <button
                                type="button"
                                class="flex min-h-11 w-full items-center gap-2 rounded-surface border border-line-soft bg-soft px-3 py-2 text-start text-small font-medium text-ink transition-colors hover:bg-hover"
                                x-on:click="submit(@js($suggestion))"
                                data-suggestion
                            >
                                <x-ic name="question" class="text-ink-3" />
                                <span>{{ $suggestion }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach ($messages as $index => $message)
            @if ($message['role'] === 'user')
                <div wire:key="msg-{{ $index }}" class="flex justify-end">
                    <div class="max-w-[85%] whitespace-pre-line break-words rounded-surface rounded-ee-sm bg-surface px-4 py-2.5 text-body text-ink" data-role="user">{{ $message['text'] }}</div>
                </div>
            @else
                <div wire:key="msg-{{ $index }}" class="flex items-start gap-3">
                    <span class="mt-1 inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-soft text-ink-2" aria-hidden="true">
                        <x-icons.assistant class="size-4" />
                    </span>
                    <div
                        class="min-w-0 flex-1 break-words rounded-surface rounded-ss-sm border border-line-soft bg-card px-4 py-3 text-body text-ink shadow-e1
                               [&_a]:font-semibold [&_a]:underline [&_blockquote]:border-s-2 [&_blockquote]:border-line [&_blockquote]:ps-3 [&_blockquote]:text-ink-2
                               [&_code]:rounded [&_code]:bg-soft [&_code]:px-1 [&_h1]:font-bold [&_h2]:font-bold [&_h3]:font-semibold [&_li]:my-0.5
                               [&_ol]:list-decimal [&_ol]:ps-5 [&_ul]:list-disc [&_ul]:ps-5 [&_table]:w-full [&_td]:border [&_td]:border-line-soft [&_td]:px-2 [&_th]:border [&_th]:border-line-soft [&_th]:px-2
                               [&>*+*]:mt-2"
                        data-role="assistant"
                    >{!! $this->toHtml($message['text']) !!}</div>
                </div>
            @endif
        @endforeach

        {{-- The question on its way, shown while the answer is written. --}}
        <template x-if="pending">
            <div class="space-y-4">
                <div class="flex justify-end">
                    <div class="max-w-[85%] whitespace-pre-line break-words rounded-surface rounded-ee-sm bg-surface px-4 py-2.5 text-body text-ink" x-text="pending"></div>
                </div>
                <div class="flex items-center gap-3" role="status">
                    <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-soft text-ink-2" aria-hidden="true">
                        <x-icons.assistant class="size-4" />
                    </span>
                    <span class="inline-flex items-center gap-2 rounded-surface border border-line-soft bg-card px-4 py-2.5 text-small text-ink-2">
                        <span class="hc-spin" aria-hidden="true"></span>
                        {{ __('assistant.thinking') }}
                    </span>
                </div>
            </div>
        </template>

        @if ($notice)
            <p class="flex items-start gap-2 rounded-surface border border-ink px-4 py-3 text-small text-ink" role="alert" data-testid="assistant-notice">
                <x-ic name="alert" class="mt-0.5" />
                <span>{{ $notice }}</span>
            </p>
        @endif
    </div>

    {{-- The question box --}}
    <form class="mt-6 rounded-surface border border-line-soft bg-card p-3 shadow-e1" x-on:submit.prevent="submit()">
        <label for="assistant-question" class="sr-only">{{ __('assistant.question') }}</label>
        <textarea
            id="assistant-question"
            wire:model="question"
            rows="2"
            maxlength="1000"
            class="hc-field w-full resize-y"
            placeholder="{{ __('assistant.placeholder') }}"
            aria-describedby="assistant-question-hint"
            x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); submit() }"
            x-bind:readonly="!! pending"
        ></textarea>
        <x-forms.input-error field="question" input-id="assistant-question" />

        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
            <p id="assistant-question-hint" class="text-caption text-ink-3">{{ __('assistant.hint') }}</p>
            <x-button variant="primary" type="submit" target="send" x-bind:disabled="!! pending">
                {{ __('assistant.send') }}
            </x-button>
        </div>
    </form>

    <div x-ref="end" class="h-px" aria-hidden="true"></div>
</div>
