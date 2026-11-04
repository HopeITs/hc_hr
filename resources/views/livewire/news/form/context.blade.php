{{--
    2. Tags & audience. Tags are optional free labels describing what the item
    is about; they never decide who sees it. Audience is who may see the
    item: its lists are what NewsForm offers (and the only ids its validation
    accepts).

    The tag input is pure Alpine on an entangled (deferred) array: typing and
    removing tags never calls the server. Enter or a comma adds the typed
    text (or the highlighted suggestion); suggestions are only a help, any
    text is allowed. NewsForm normalizes and validates again on save.
--}}
@php
    use App\Enums\News\AudienceType;
    use App\Models\News;
    use Illuminate\Support\Str;

    $audience = $form->audienceEnum();
    $selectedIds = array_map('strval', $form->audience_ids);
    $tagErrors = collect($errors->get('form.tags'))
        ->concat(collect($errors->getMessages())->filter(fn ($m, $k) => Str::startsWith($k, 'form.tags.'))->flatten())
        ->unique()->values();
@endphp

<section class="rounded-surface border border-line-soft bg-card shadow-e1" aria-labelledby="news-section-context">
    <header class="flex items-start gap-3 border-b border-line-soft px-5 py-4">
        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ink text-small font-bold text-on-ink num" aria-hidden="true">2</span>
        <div class="min-w-0">
            <h2 id="news-section-context" class="text-h4 font-extrabold text-ink">@lang('news.form.sections.context')</h2>
            <p class="text-small text-ink-2">@lang('news.form.sections.context_hint')</p>
        </div>
    </header>

    <div class="grid gap-6 p-5 md:grid-cols-2">
        {{-- Tags (optional, free text) --}}
        <div class="min-w-0">
            <x-forms.input-label for="news-tags-input">@lang('news.form.fields.tags')</x-forms.input-label>

            <div wire:ignore
                 x-data="{
                     tags: @entangle('form.tags'),
                     suggestions: @js($tagSuggestions),
                     max: {{ News::MAX_TAGS }},
                     maxLength: {{ News::MAX_TAG_LENGTH }},
                     msg: @js([
                         'added' => __('news.form.tags.added'),
                         'removed' => __('news.form.tags.removed'),
                         'full' => __('news.form.tags.full', ['max' => News::MAX_TAGS]),
                         'duplicate' => __('news.form.tags.duplicate'),
                     ]),
                     draft: '',
                     open: false,
                     active: -1,
                     status: '',
                     list() { return Array.isArray(this.tags) ? this.tags : [] },
                     norm(value) { return String(value ?? '').replace(/\s+/g, ' ').trim().slice(0, this.maxLength).trim() },
                     has(value) { const key = value.toLocaleLowerCase(); return this.list().some(t => String(t).toLocaleLowerCase() === key) },
                     full() { return this.list().length >= this.max },
                     options() {
                         const q = this.norm(this.draft).toLocaleLowerCase();
                         return this.suggestions.filter(s => ! this.has(s) && (q === '' || s.toLocaleLowerCase().includes(q))).slice(0, 8);
                     },
                     add(value) {
                         const tag = this.norm(value);
                         if (tag === '') return;
                         if (this.has(tag)) { this.status = this.msg.duplicate.replace(':tag', tag); return; }
                         if (this.full()) { this.status = this.msg.full; return; }
                         this.tags = [...this.list(), tag];
                         this.status = this.msg.added.replace(':tag', tag);
                     },
                     commit() {
                         this.draft.split(/[,،]/).forEach(part => this.add(part));
                         this.draft = '';
                         this.active = -1;
                     },
                     pick(option) {
                         this.add(option);
                         this.draft = '';
                         this.active = -1;
                         this.$refs.input.focus();
                     },
                     remove(index) {
                         const tag = this.list()[index];
                         this.tags = this.list().filter((_, i) => i !== index);
                         this.status = this.msg.removed.replace(':tag', tag);
                         this.$nextTick(() => this.$refs.input.focus());
                     },
                     onInput() {
                         if (/[,،]/.test(this.draft)) {
                             const parts = this.draft.split(/[,،]/);
                             const rest = parts.pop();
                             parts.forEach(part => this.add(part));
                             this.draft = rest;
                         }
                         this.open = true;
                         this.active = -1;
                     },
                     onKey(e) {
                         if (e.isComposing) return;
                         const options = this.options();
                         if (e.key === 'Enter' || e.key === ',' || e.key === '،') {
                             e.preventDefault();
                             if (e.key === 'Enter' && this.open && options[this.active]) { this.pick(options[this.active]); return; }
                             this.commit();
                         } else if (e.key === 'ArrowDown') {
                             e.preventDefault();
                             this.open = true;
                             this.active = options.length ? (this.active + 1) % options.length : -1;
                         } else if (e.key === 'ArrowUp') {
                             e.preventDefault();
                             this.open = true;
                             this.active = options.length ? (this.active <= 0 ? options.length - 1 : this.active - 1) : -1;
                         } else if (e.key === 'Escape' && this.open) {
                             e.preventDefault();
                             this.open = false;
                             this.active = -1;
                         } else if (e.key === 'Backspace' && this.draft === '' && this.list().length) {
                             this.remove(this.list().length - 1);
                         }
                     },
                 }"
                 x-on:click.outside="open = false; active = -1"
                 class="relative">
                <input id="news-tags-input" x-ref="input" type="text" class="hc-field" autocomplete="off"
                       role="combobox" aria-autocomplete="list" aria-controls="news-tags-listbox"
                       aria-describedby="news-tags-help"
                       x-bind:aria-expanded="open && options().length > 0 ? 'true' : 'false'"
                       x-bind:aria-activedescendant="open && active >= 0 ? 'news-tags-option-' + active : null"
                       maxlength="{{ News::MAX_TAG_LENGTH }}"
                       placeholder="{{ __('news.form.tags.placeholder') }}"
                       x-model="draft"
                       x-on:input="onInput()"
                       x-on:focus="open = true"
                       x-on:keydown="onKey($event)"
                       x-on:blur="commit(); open = false">

                <ul id="news-tags-listbox" role="listbox" aria-label="{{ __('news.form.tags.suggestions') }}"
                    x-show="open && options().length > 0" x-cloak
                    class="absolute inset-x-0 top-full z-20 mt-1 max-h-60 overflow-y-auto rounded-control border border-line bg-card py-1 shadow-e2">
                    <template x-for="(option, index) in options()" x-bind:key="option">
                        <li role="option" x-bind:id="'news-tags-option-' + index"
                            x-bind:aria-selected="index === active ? 'true' : 'false'"
                            x-on:mousedown.prevent="pick(option)"
                            x-on:mousemove="active = index"
                            x-bind:class="index === active ? 'bg-hover' : ''"
                            class="flex min-h-10 cursor-pointer items-center gap-2 px-3 text-body text-ink">
                            <x-ic name="tag" class="shrink-0 text-ink-3" />
                            <bdi class="min-w-0 truncate" x-text="option"></bdi>
                        </li>
                    </template>
                </ul>

                <ul class="mt-2 flex flex-wrap gap-1.5" role="list" aria-label="{{ __('news.form.tags.chosen') }}"
                    x-show="list().length > 0" x-cloak>
                    <template x-for="(tag, index) in list()" x-bind:key="tag">
                        <li class="hc-tag hc-tag--soft max-w-full whitespace-normal pe-0.5">
                            <bdi class="min-w-0 break-words" x-text="tag"></bdi>
                            <button type="button" x-on:click="remove(index)"
                                    x-bind:aria-label="@js(__('news.form.tags.remove')).replace(':tag', tag)"
                                    class="grid size-6 shrink-0 place-items-center rounded-inset text-ink-2 hover:bg-hover hover:text-ink">
                                <x-ic name="x" />
                            </button>
                        </li>
                    </template>
                </ul>

                <p class="sr-only" role="status" aria-live="polite" x-text="status"></p>
            </div>

            <p id="news-tags-help" class="mt-1 text-small text-ink-2">
                @lang('news.form.help.tags')
                <span class="text-ink-3">@lang('news.form.tags.how', ['max' => News::MAX_TAGS])</span>
            </p>
            <x-error-span>{{ $tagErrors->implode(' ') }}</x-error-span>
        </div>

        {{-- Audience --}}
        <div class="space-y-4">
            <div>
                <x-forms.input-label for="news-audience-type" required>@lang('news.form.fields.audience_type')</x-forms.input-label>
                <x-forms.select2-input id="news-audience-type" wire:model.live="form.audience_type" :hide-search="true"
                                       aria-describedby="news-audience-help">
                    @foreach (AudienceType::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-forms.select2-input>
                <p id="news-audience-help" class="mt-1 text-small text-ink-2">@lang('news.form.help.audience')</p>
                <x-error-span>{{ $errors->first('form.audience_type') }}</x-error-span>
            </div>

            @if ($audience->needsTargets())
                {{--
                    A searchable checkbox list: no multi-select component exists.
                    The filter is client-side only (Alpine); the server checks
                    every id against the same list.
                --}}
                <fieldset wire:key="news-audience-list-{{ $audience->value }}"
                          x-data="{
                              q: '',
                              shown: {{ $audienceOptions->count() }},
                              match(el) { return this.q.trim() === '' || el.dataset.name.includes(this.q.trim().toLowerCase()) },
                              count() { this.$nextTick(() => this.shown = [...this.$refs.list.querySelectorAll('[data-name]')].filter(el => this.match(el)).length) },
                              selectShown() {
                                  let ids = [...this.$refs.list.querySelectorAll('[data-name]')].filter(el => this.match(el)).map(el => el.dataset.id);
                                  let current = ($wire.form.audience_ids || []).map(String);
                                  $wire.$set('form.audience_ids', [...new Set([...current, ...ids])]);
                              },
                          }"
                          x-init="$watch('q', () => count())"
                          aria-describedby="news-audience-help">
                    <legend class="mb-1.5 block text-body font-bold text-ink">
                        {{ $audience->label() }}
                        <span class="text-primary ms-1" aria-hidden="true">*</span>
                        <span class="sr-only">({{ __('general.a11y.required-field') }})</span>
                    </legend>

                    @if ($audienceOptions->isEmpty())
                        <p class="rounded-control border border-dashed border-line p-4 text-small text-ink-2">@lang('news.form.no_options')</p>
                    @else
                        <div class="overflow-hidden rounded-control border border-line bg-field">
                            <div class="flex flex-wrap items-center gap-2 border-b border-line-soft p-2">
                                <label class="relative min-w-0 flex-1">
                                    <span class="sr-only">@lang('news.form.search')</span>
                                    <x-ic name="search" class="pointer-events-none absolute inset-y-0 start-3 my-auto text-ink-3" />
                                    <input type="search" x-model.debounce.150ms="q" placeholder="{{ __('news.form.search') }}"
                                           class="hc-field ps-9" autocomplete="off">
                                </label>
                                <span class="text-caption text-ink-2 num" aria-live="polite">
                                    {{ __('news.form.selected_count', ['count' => count($selectedIds)]) }}
                                </span>
                            </div>

                            <ul x-ref="list" class="max-h-72 divide-y divide-line-soft overflow-y-auto" role="list">
                                @foreach ($audienceOptions as $option)
                                    @php($optionId = 'news-audience-'.$audience->value.'-'.$option->id)
                                    <li wire:key="aud-{{ $audience->value }}-{{ $option->id }}"
                                        data-id="{{ $option->id }}"
                                        data-name="{{ Str::lower($option->name) }}"
                                        x-show="match($el)">
                                        <label for="{{ $optionId }}" class="flex min-h-11 cursor-pointer items-center gap-2.5 px-3 hover:bg-hover">
                                            <input id="{{ $optionId }}" type="checkbox" class="hc-check rounded-inset"
                                                   value="{{ $option->id }}" wire:model.live="form.audience_ids">
                                            <bdi class="min-w-0 truncate text-body text-ink">{{ $option->name }}</bdi>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>

                            <p x-show="shown === 0" x-cloak class="p-4 text-small text-ink-2">@lang('news.form.no_results')</p>

                            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-line-soft p-2">
                                <x-button variant="link" size="sm" x-on:click="selectShown()" :loading="false">@lang('news.form.select_shown')</x-button>
                                <x-button variant="link" size="sm" wire:click="$set('form.audience_ids', [])" :loading="false">@lang('news.form.clear')</x-button>
                            </div>
                        </div>
                    @endif

                    <x-error-span>{{ $errors->first('form.audience_ids') ?: $errors->first('form.audience_ids.*') }}</x-error-span>
                </fieldset>
            @endif
        </div>
    </div>
</section>
