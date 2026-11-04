{{--
    2. Context & audience. Related To is the subject only; Audience is who
    may see the item. Each dependent list is what NewsForm offers (and the
    only ids its validation accepts).
--}}
@php
    use App\Enums\News\AudienceType;
    use App\Enums\News\RelatedTo;
    use Illuminate\Support\Str;

    $related = $form->relatedEnum();
    $audience = $form->audienceEnum();
    $selectedIds = array_map('strval', $form->audience_ids);
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
        {{-- Related To --}}
        <div class="space-y-4">
            <div>
                <x-forms.input-label for="news-related-to" required>@lang('news.form.fields.related_to')</x-forms.input-label>
                <x-forms.select2-input id="news-related-to" wire:model.live="form.related_to" :hide-search="true"
                                       aria-describedby="news-related-to-help">
                    @foreach (RelatedTo::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-forms.select2-input>
                <p id="news-related-to-help" class="mt-1 text-small text-ink-2">@lang('news.form.help.related_to')</p>
                <x-error-span>{{ $errors->first('form.related_to') }}</x-error-span>
            </div>

            @if ($related->needsEntity())
                <div wire:key="news-related-entity-{{ $related->value }}">
                    <x-forms.input-label for="news-related-id" required>{{ $related->label() }}</x-forms.input-label>
                    <x-forms.select2-input id="news-related-id" wire:model="form.related_id">
                        <option value="">@lang('news.form.choose')</option>
                        @foreach ($relatedOptions as $entity)
                            <option value="{{ $entity->id }}">{{ $entity->name }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first('form.related_id') }}</x-error-span>
                </div>
            @endif
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
