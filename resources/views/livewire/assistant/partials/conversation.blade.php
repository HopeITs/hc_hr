{{-- The conversation, shared by the page and the panel (livewire/assistant/chat). --}}
<div @class(['space-y-4' => ! $isPanel, 'space-y-3' => $isPanel]) aria-live="polite" aria-relevant="additions" data-testid="assistant-log">
    @if ($messages === [])
        <div x-show="! pending" @class([
            'text-center',
            'rounded-surface border border-line-soft bg-card px-4 py-8 shadow-e1 sm:px-8' => ! $isPanel,
            'px-1 py-2' => $isPanel,
        ]) data-state="empty">
            <span class="mx-auto inline-flex size-12 items-center justify-center rounded-full bg-surface text-ink-2" aria-hidden="true">
                <x-icons.assistant class="size-6" />
            </span>
            <h2 class="mt-3 text-body font-semibold text-ink">{{ __('assistant.empty.title') }}</h2>
            <p class="mt-1 text-small text-ink-2">{{ __('assistant.empty.help') }}</p>

            <ul @class(['mt-5 grid gap-2 text-start', 'sm:grid-cols-2' => ! $isPanel])>
                @foreach ($suggestions as $suggestion)
                    <li>
                        <button
                            type="button"
                            class="flex min-h-11 w-full items-center gap-2 rounded-surface border border-line-soft bg-soft px-3 py-2 text-start text-small font-medium text-ink transition-colors hover:bg-hover"
                            x-on:click="submit(@js($suggestion))"
                            data-suggestion
                        >
                            <x-ic name="question" class="shrink-0 text-ink-3" />
                            <span>{{ $suggestion }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @foreach ($messages as $index => $message)
        @php
            $kind = $message['kind'] ?? null;
            $proposal = $message['proposal'] ?? null;
        @endphp

        @if ($message['role'] === 'user')
            <div wire:key="msg-{{ $index }}" class="flex justify-end">
                <div class="max-w-[85%] whitespace-pre-line break-words rounded-surface rounded-ee-sm bg-surface px-4 py-2.5 text-body text-ink" data-role="user">{{ $message['text'] }}</div>
            </div>
        @elseif ($kind !== null)
            {{-- A line the page added itself (a draft submitted, cancelled or refused). --}}
            <div wire:key="msg-{{ $index }}" class="flex items-start gap-3" data-role="note" data-kind="{{ $kind }}">
                <span @class([
                    'mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-full',
                    'bg-ink text-on-ink' => $kind === 'submitted',
                    'border border-ink text-ink' => $kind === 'error',
                    'border border-dashed border-line text-ink-2' => $kind === 'cancelled',
                ]) aria-hidden="true">
                    <x-ic :name="match ($kind) { 'submitted' => 'check', 'error' => 'alert', default => 'ban' }" />
                </span>
                <div @class([
                    'min-w-0 flex-1 break-words rounded-surface px-4 py-2.5 text-small [&_li]:my-0.5 [&_ul]:list-disc [&_ul]:ps-5 [&>*+*]:mt-1',
                    'border border-line-soft bg-card text-ink' => $kind !== 'cancelled',
                    'text-ink-2' => $kind === 'cancelled',
                ]) @if ($kind === 'error') role="alert" @endif>
                    {!! $this->toHtml($message['text']) !!}
                    {{-- Where to see what was created (lines saved before other kinds existed are leave requests). --}}
                    @if ($kind === 'submitted' && ($message['url'] ?? ! isset($message['link'])))
                        <p>
                            <a href="{{ $message['url'] ?? route('leaves.index') }}" wire:navigate class="font-semibold text-ink underline" data-testid="assistant-record-link">
                                {{ $message['link'] ?? __('assistant.leave.open-my-leaves') }}
                            </a>
                        </p>
                    @endif
                </div>
            </div>
        @else
            <div wire:key="msg-{{ $index }}" class="flex items-start gap-3">
                <span class="mt-1 inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-soft text-ink-2" aria-hidden="true">
                    <x-icons.assistant class="size-4" />
                </span>
                <div class="min-w-0 flex-1 space-y-3">
                    <div
                        class="break-words rounded-surface rounded-ss-sm border border-line-soft bg-card px-4 py-3 text-body text-ink shadow-e1
                               [&_a]:font-semibold [&_a]:underline [&_blockquote]:border-s-2 [&_blockquote]:border-line [&_blockquote]:ps-3 [&_blockquote]:text-ink-2
                               [&_code]:rounded [&_code]:bg-soft [&_code]:px-1 [&_h1]:font-bold [&_h2]:font-bold [&_h3]:font-semibold [&_li]:my-0.5
                               [&_ol]:list-decimal [&_ol]:ps-5 [&_ul]:list-disc [&_ul]:ps-5 [&_table]:w-full [&_td]:border [&_td]:border-line-soft [&_td]:px-2 [&_th]:border [&_th]:border-line-soft [&_th]:px-2
                               [&>*+*]:mt-2"
                        data-role="assistant"
                    >{!! $this->toHtml($message['text']) !!}</div>

                    @if (is_array($proposal) && ($proposal['type'] ?? null) === 'choice')
                        @include('livewire.assistant.partials.create-choice', ['proposal' => $proposal, 'isLatest' => $loop->last])
                    @elseif (is_array($proposal))
                        @include('livewire.assistant.partials.draft-proposal', ['proposal' => $proposal])
                    @endif
                </div>
            </div>
        @endif
    @endforeach

    {{-- The question on its way, shown while the answer is written. --}}
    <template x-if="pending">
        <div @class(['space-y-4' => ! $isPanel, 'space-y-3' => $isPanel])>
            <div class="flex justify-end">
                <div class="max-w-[85%] whitespace-pre-line break-words rounded-surface rounded-ee-sm bg-surface px-4 py-2.5 text-body text-ink" x-text="pending"></div>
            </div>
            <div class="flex items-center gap-3" role="status">
                <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-soft text-ink-2" aria-hidden="true">
                    <x-icons.assistant class="size-4" />
                </span>
                <span x-show="! signIn" class="inline-flex items-center gap-2 rounded-surface border border-line-soft bg-card px-4 py-2.5 text-small text-ink-2">
                    <span class="hc-spin" aria-hidden="true"></span>
                    {{ __('assistant.thinking') }}
                </span>
                {{-- Puter answers under the employee's own account: its sign-in popup needs a click. --}}
                <div x-show="signIn" x-cloak class="min-w-0 flex-1 rounded-surface border border-ink bg-card px-4 py-3 text-small text-ink" data-testid="assistant-puter-signin">
                    <p>{{ __('assistant.puter.signin-help') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <x-button variant="primary" size="sm" x-on:click="signInAndContinue()">{{ __('assistant.puter.signin') }}</x-button>
                        <x-button variant="quiet" size="sm" x-on:click="abandon()">{{ __('assistant.leave.cancel') }}</x-button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    @if ($notice)
        <p class="flex items-start gap-2 rounded-surface border border-ink px-4 py-3 text-small text-ink" role="alert" data-testid="assistant-notice">
            <x-ic name="alert" class="mt-0.5 shrink-0" />
            <span>{{ $notice }}</span>
        </p>
    @endif
</div>
