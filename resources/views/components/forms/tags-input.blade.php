@props([
    'placeholder' => 'Add tag',
    'suggestions' => [],
    'forceSuggestion' => false,
    // Without this the inner text field falls back to id="input", so every
    // tags-input on a page collides and their labels point at nothing.
    'inputId' => null,
])

@php
    // Normalize suggestions into [{key, label}] format
    $suggestions = explode(',',$suggestions);
    $normalized = collect($suggestions)->map(function ($value, $key) {
        return is_string($key)
            ? ['key' => $key, 'label' => $value]
            : ['key' => $value, 'label' => $value];
    })->values();
@endphp

<div
    x-data="tagsInput({
        model: @entangle($attributes->wire('model')),
        suggestions: @js($normalized),
        forceSuggestion: {{ $forceSuggestion ? 'true' : 'false' }},
    })"
    class="flex flex-wrap items-center gap-2 relative"
>
    <x-forms.input
        x-ref="input"
        type="text"
        :id="$inputId"
        :placeholder="$placeholder"
        @focus="showAllSuggestions()"
        @click="showAllSuggestions()"
        @input="filterSuggestions($event.target.value)"
        @keydown.enter.prevent="addFromInput()"
        @keydown.tab.prevent="addFromInput()"
        @keydown.backspace="maybeRemove()"
        @blur="addFromInput(); hideSuggestionsLater()"
        @paste.prevent="paste($event)"
        class="flex-1"
    />

    <!-- suggestions -->
    <template x-if="showSuggestions && filtered.length">
        <ul
            class="absolute top-full left-0 z-10 w-full bg-white border border-neutral-300 mt-1 rounded-control shadow-e2 max-h-40 overflow-y-auto"
        >
            <template x-for="(s, i) in filtered" :key="i">
                <li
                    @mousedown.prevent="selectSuggestion(s)"
                    class="px-3 py-1 cursor-pointer hover:bg-neutral-100"
                    x-text="s.label"
                ></li>
            </template>
        </ul>
    </template>

    <!-- current tags -->
    <template x-for="(tag, i) in tags" :key="i">
        <span
            class="inline-flex mt-2 items-center shadow-e2 gap-1 text-secondary-dark rounded-full bg-neutral-100 px-2 py-1 text-body"
        >
            <span x-text="displayLabel(tag)"></span>
            <button type="button" @click="remove(i)" class=" hover:text-primary">×</button>
        </span>
    </template>
</div>

<script nonce="{{ Vite::cspNonce() }}">
    // alpine:init has already fired when wire:navigate swaps this page in, so
    // register straight away if Alpine is up and on alpine:init otherwise.
    (() => {
    const register = () => {
        Alpine.data('tagsInput', ({ model, suggestions = [], forceSuggestion = false }) => ({
            model,
            tags: [],
            suggestions,
            filtered: [],
            showSuggestions: false,
            forceSuggestion,

            init() {
                this.syncFromModel();
                this.$watch('model', () => this.syncFromModel());
                this.$watch('tags', () => this.syncToModel());
            },
            showAllSuggestions() {
                this.filtered = this.suggestions
                    .filter(s => !this.tags.includes(s.key.toLowerCase()))
                    .slice(0, 10);

                this.showSuggestions = this.filtered.length > 0;
            },
            syncFromModel() {
                this.tags = typeof this.model === 'string'
                    ? this.model.split(',').map(t => t.trim().toLowerCase()).filter(Boolean)
                    : [];
            },

            syncToModel() {
                this.model = this.tags.join(',');
            },

            add(tag) {
                tag = String(tag || '').trim().toLowerCase();
                if (!tag) return;

                const match = this.suggestions.find(s =>
                    s.key.toLowerCase() === tag || s.label.toLowerCase() === tag
                );

                const value = match ? match.key.toLowerCase() : tag;

                if (this.forceSuggestion && !match) {
                    this.$refs.input.value = '';
                    this.hideSuggestions();
                    return;
                }

                if (!this.tags.includes(value)) this.tags.push(value);
                this.hideSuggestions();
            },

            addFromInput() {
                const v = this.$refs.input.value.trim();
                if (!v) return;
                this.add(v);
                this.$refs.input.value = '';
            },

            remove(i) { this.tags.splice(i, 1); },

            maybeRemove() {
                if (this.$refs.input.value === '' && this.tags.length)
                    this.remove(this.tags.length - 1);
            },

            paste(e) {
                const text = (e.clipboardData || window.clipboardData).getData('text');
                this.add(text);
                this.$refs.input.value = '';
            },

            // suggestions
            filterSuggestions(value) {
                const v = value.trim().toLowerCase();
                if (!v) { this.filtered = []; this.showSuggestions = false; return; }

                this.filtered = this.suggestions
                    .filter(s =>
                        s.label.toLowerCase().includes(v) &&
                        !this.tags.includes(s.key.toLowerCase())
                    )
                    .slice(0, 10);

                this.showSuggestions = this.filtered.length > 0;
            },

            selectSuggestion(s) {
                this.add(s.key);
                this.$refs.input.value = '';
                this.hideSuggestions();
            },

            hideSuggestions() { this.showSuggestions = false; },
            hideSuggestionsLater() { setTimeout(() => this.hideSuggestions(), 150); },

            displayLabel(key) {
                const match = this.suggestions.find(s => s.key.toLowerCase() === key.toLowerCase());
                return match ? match.label : key;
            },
        }))
    };

    window.Alpine ? register() : document.addEventListener('alpine:init', register, { once: true });
    })();
</script>
