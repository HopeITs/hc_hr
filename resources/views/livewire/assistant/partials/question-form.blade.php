{{-- The question box, shared by the page and the panel (livewire/assistant/chat). --}}
@php
    $fieldId = 'assistant-question-'.$mode;
@endphp

<form x-on:submit.prevent="submit()">
    <label for="{{ $fieldId }}" class="sr-only">{{ __('assistant.question') }}</label>
    <textarea
        id="{{ $fieldId }}"
        wire:model="question"
        rows="2"
        maxlength="1000"
        @class(['hc-field w-full', 'resize-y' => ! $isPanel, 'resize-none' => $isPanel])
        placeholder="{{ __('assistant.placeholder') }}"
        aria-describedby="{{ $fieldId }}-hint"
        x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); submit() }"
        x-bind:readonly="!! pending"
    ></textarea>
    <x-forms.input-error field="question" :input-id="$fieldId" />

    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
        <p id="{{ $fieldId }}-hint" @class(['text-caption text-ink-3', 'sr-only' => $isPanel])>{{ __('assistant.hint') }}</p>
        <x-button variant="primary" :size="$isPanel ? 'sm' : 'md'" type="submit" target="send" class="ms-auto" x-bind:disabled="!! pending">
            {{ __('assistant.send') }}
        </x-button>
    </div>
</form>
