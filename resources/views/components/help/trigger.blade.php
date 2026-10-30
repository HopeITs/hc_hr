@props([
    'topic',  // a key of App\Support\HelpTopics::TOPICS
    'label',  // the field it explains, for the accessible name "Help: <label>"
])

{{--
    A small "?" beside a difficult field (D3). Opens the help panel at $topic.

    Placed BESIDE a <label>/<legend>, never inside one: inside, its name would
    become part of the field's own accessible name. Always type="button" and
    never wire:*, so inside a form it can neither submit it nor reach the
    Livewire component: the form keeps its values. The visible circle is 24px;
    the ::before layer gives it a 44px hit area without moving the label.
--}}
<button type="button"
        x-data
        x-on:click="$dispatch('help-open', { topic: @js($topic) })"
        data-help-trigger
        data-help-topic="{{ $topic }}"
        aria-haspopup="dialog"
        aria-label="{{ __('help.trigger', ['field' => $label]) }}"
        title="{{ __('help.trigger', ['field' => $label]) }}"
        {{ $attributes->merge(['class' => "relative inline-grid size-6 shrink-0 place-items-center rounded-round text-ink-2 transition before:absolute before:-inset-2.5 before:content-[''] hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-1 motion-reduce:transition-none"]) }}>
    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.4M12 17h.01"/>
    </svg>
</button>
