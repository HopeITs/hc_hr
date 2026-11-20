{{--
    Ready-made announcements (App\Livewire\Messages\AnnouncementComposer): pick one,
    fill the few things that change, read the email as it will go, then copy it or
    open it as a new message. The wording is Arabic whatever the interface language.
--}}
@php
    $control = 'block w-full rounded-control border border-line bg-card px-3 py-2 text-body text-ink';
    $copyJs = fn (string $text, string $done) => 'navigator.clipboard?.writeText('.\Illuminate\Support\Js::from($text).').then(() => window.dispatchEvent(new CustomEvent(\'toast\', { detail: { type: \'success\', message: '.\Illuminate\Support\Js::from($done).' } })))';
@endphp
<div>
    <x-modal.dialog id="announcement-composer" max-width="6xl" wire:model="open"
                    :title="$current ? $current->label() : __('messages.announcements.title')">
        @if (! $current)
            <p class="mb-4 text-small text-ink-2">@lang('messages.announcements.lead')</p>
            <ul class="grid gap-3 sm:grid-cols-2" data-testid="announcement-list">
                @foreach ($templates as $key => $template)
                    <li>
                        <button type="button" wire:click="choose('{{ $key }}')" data-testid="announcement-{{ $key }}"
                                class="flex h-full w-full flex-col items-start gap-1 rounded-control border border-line-soft bg-card p-4 text-start transition hover:border-line hover:bg-hover">
                            <span class="font-semibold text-ink">{{ $template->label() }}</span>
                            <span class="text-small text-ink-2">{{ $template->description() }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="mb-4">
                <x-button variant="link" size="sm" wire:click="back">@lang('messages.announcements.other')</x-button>
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                {{-- The fields. Arabic wording, so the inputs are written right to left. --}}
                <form wire:submit.prevent class="space-y-4" data-testid="announcement-fields" autocomplete="off">
                    @foreach ($current->fields() as $field)
                        @continue (! $current->isShown($field, $values))
                        @php($id = 'ann-'.$current->key().'-'.$field['name'])
                        <div wire:key="{{ $id }}">
                            @if ($field['type'] === 'toggle')
                                <label class="inline-flex items-center gap-2 font-semibold text-ink">
                                    <input type="checkbox" id="{{ $id }}" wire:model.live="values.{{ $field['name'] }}" class="size-4 rounded border-line">
                                    {{ $current->fieldLabel($field['name']) }}
                                </label>
                            @else
                                <x-forms.input-label for="{{ $id }}" :required="$field['required'] ?? false">{{ $current->fieldLabel($field['name']) }}</x-forms.input-label>

                                @switch ($field['type'])
                                    @case('choice')
                                        <select id="{{ $id }}" wire:model.live="values.{{ $field['name'] }}" class="{{ $control }}">
                                            @foreach ($field['options'] as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('textarea')
                                        <textarea id="{{ $id }}" rows="2" dir="rtl" wire:model.live.debounce.400ms="values.{{ $field['name'] }}" class="{{ $control }}"></textarea>
                                        @break
                                    @case('date')
                                        <input type="date" id="{{ $id }}" wire:model.live="values.{{ $field['name'] }}" class="{{ $control }}">
                                        @break
                                    @default
                                        <input type="text" id="{{ $id }}" dir="{{ $field['dir'] ?? 'rtl' }}" maxlength="500"
                                               wire:model.live.debounce.400ms="values.{{ $field['name'] }}" class="{{ $control }}">
                                @endswitch

                                @isset ($field['hint'])
                                    <p class="mt-1 text-small text-ink-2">@lang('messages.announcements.hints.'.$field['hint'])</p>
                                @endisset
                            @endif
                        </div>
                    @endforeach
                </form>

                {{-- The email as it will go. --}}
                <section class="min-w-0 rounded-control border border-line-soft p-4" aria-live="polite" data-testid="announcement-preview">
                    <div class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2 border-b border-line-soft pb-4">
                        <label for="ann-to" class="text-small text-ink-2">@lang('messages.announcements.to')</label>
                        <input id="ann-to" type="text" dir="ltr" wire:model.live.debounce.400ms="to" class="{{ $control }}" data-testid="announcement-to">
                        <label for="ann-cc" class="text-small text-ink-2">@lang('messages.announcements.cc')</label>
                        <input id="ann-cc" type="text" dir="ltr" wire:model.live.debounce.400ms="cc" class="{{ $control }}" data-testid="announcement-cc">
                        <span class="text-small text-ink-2">@lang('messages.subject')</span>
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="min-w-0 flex-1 font-semibold text-ink [overflow-wrap:anywhere]" dir="rtl" data-testid="announcement-subject">{{ $message['subject'] }}</span>
                            <x-button variant="outline" size="sm" x-on:click="{{ $copyJs($message['subject'], __('messages.announcements.copied-subject')) }}">@lang('messages.announcements.copy')</x-button>
                        </span>
                    </div>

                    <div class="whitespace-pre-wrap pt-4 text-body leading-relaxed text-ink" dir="rtl" lang="ar" data-testid="announcement-body">{{ $message['body'] }}</div>

                    @if ($message['problems'])
                        <ul class="mt-4 space-y-1 text-small text-primary" data-testid="announcement-problems">
                            @foreach ($message['problems'] as $problem)
                                <li>{{ $problem }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="mt-5 flex flex-wrap gap-3">
                        <x-button x-on:click="{{ $copyJs($message['body'], __('messages.announcements.copied-body')) }}" data-testid="announcement-copy-body">@lang('messages.announcements.copy-body')</x-button>
                        @if ($message['mailto'])
                            {{-- A link too long for a mail program opens with To, CC and subject only; the text is
                                 copied as it opens, to paste. --}}
                            <a href="{{ $message['mailto']['url'] }}" data-testid="announcement-mailto"
                               @if ($message['mailto']['trimmed']) x-on:click="{{ $copyJs($message['body'], __('messages.announcements.copied-paste')) }}" @endif
                               class="inline-flex min-h-11 items-center rounded-control border border-line px-4 font-semibold text-ink transition hover:bg-hover">@lang('messages.announcements.open-mail')</a>
                        @endif
                        @if ($message['web'])
                            <a href="{{ $message['web']['gmail'] }}" target="_blank" rel="noopener" data-testid="announcement-gmail"
                               class="inline-flex min-h-11 items-center rounded-control border border-line px-4 font-semibold text-ink transition hover:bg-hover">Gmail</a>
                            <a href="{{ $message['web']['outlook'] }}" target="_blank" rel="noopener" data-testid="announcement-outlook"
                               class="inline-flex min-h-11 items-center rounded-control border border-line px-4 font-semibold text-ink transition hover:bg-hover">Outlook</a>
                        @endif
                    </div>
                    <p class="mt-3 text-small text-ink-3">@lang('messages.announcements.nothing-sent')</p>
                </section>
            </div>
        @endif
    </x-modal.dialog>
</div>
