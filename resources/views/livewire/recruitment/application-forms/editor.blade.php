<x-slot:title>{{ __('recruitment.application_form.title') }}</x-slot:title>

<div x-data="{ addPanel: false }">
    <livewire:layout.breadcrumbs
        :title="__('recruitment.application_form.title')"
        :crumbs="[
            ['label' => __('recruitment-campaign.campaigns'), 'url' => route('recruitment.campaigns.index')],
            ['label' => __('recruitment-campaign.page.campaign_number', ['id' => $campaign->id]), 'url' => route('recruitment.campaigns.show', ['campaignId' => $campaign->id])],
            ['label' => __('recruitment.application_form.title')],
        ]"
    />

    <x-recruitment.editor-context
        :campaign="$campaign"
        :subtitle="__('recruitment.application_form.subtitle')"
        :version-number="$versionNumber"
        :version-status="$versionStatus"
        :published-version-number="$publishedVersionNumber"
        :just-published="$justPublished"
        :next="$nextStep"
    />

    <div class="sticky top-0 z-20 mb-5 flex flex-wrap items-center justify-between gap-3 rounded-surface border border-secondary-soft bg-white/95 p-3 shadow-e2 backdrop-blur">
        <div class="flex flex-wrap gap-2">
            <button type="button" x-on:click="addPanel = !addPanel" class="inline-flex cursor-pointer items-center gap-2 rounded-control bg-primary px-4 py-2 text-body font-semibold text-white transition hover:bg-primary-hover">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
                {{ __('recruitment.application_form.add_question') }}
            </button>
            <button type="button" x-on:click="$dispatch('open-recruitment-confirmation', { scope: 'application-form-editor', action: 'applyReferenceTemplate', message: @js(__('recruitment.application_form.template_confirm')), confirmLabel: @js(__('recruitment.application_form.use_pdf_template')) })" class="cursor-pointer rounded-control border border-secondary-light bg-white px-4 py-2 text-body font-semibold text-secondary-dark hover:bg-secondary-soft/30">
                {{ __('recruitment.application_form.use_pdf_template') }}
            </button>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="saveDraft" wire:target="saveDraft" wire:loading.attr="disabled" class="cursor-pointer rounded-control border border-primary bg-white px-4 py-2 text-body font-semibold text-primary hover:bg-secondary-soft/30 disabled:opacity-60">
                {{ __('recruitment.job_post.save_draft') }}
            </button>
            <button type="button" x-on:click="$dispatch('open-recruitment-confirmation', { scope: 'application-form-editor', action: 'publish', message: @js(__('recruitment.application_form.publish_confirm')), confirmLabel: @js(__('recruitment.job_post.publish')) })" class="cursor-pointer rounded-control bg-secondary-dark px-4 py-2 text-body font-semibold text-white hover:bg-secondary-dark">
                {{ __('recruitment.job_post.publish') }}
            </button>
        </div>
    </div>

    @if ($hasConflict)
        <button type="button" wire:click="reloadLatest" class="mb-5 cursor-pointer text-body font-semibold text-primary underline">
            {{ __('recruitment.job_post.reload_latest') }}
        </button>
    @endif

    <section class="mb-5 rounded-surface border border-secondary-soft bg-white p-5 shadow-e1">
        <div class="grid items-center gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(420px,1.1fr)]">
            <div>
                <h2 class="font-bold text-secondary-dark">{{ __('recruitment.application_form.reuse_form') }}</h2>
                <p class="mt-1 text-small leading-6 text-secondary-medium">{{ __('recruitment.application_form.reuse_form_help') }}</p>
            </div>

            @if ($availableTemplates !== [])
                {{-- Select and button share one row, so they always line up;
                     the label sits above the row and the error below it. --}}
                <div>
                    <label for="published-form-template" class="mb-1 block text-small font-medium text-secondary-dark">{{ __('recruitment.application_form.choose_form_template') }}</label>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-stretch">
                        <select id="published-form-template" wire:model="selectedTemplateVersionId" class="min-w-0 flex-1 rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary">
                            <option value="">{{ __('recruitment.application_form.choose_form_template') }}</option>
                            @foreach ($availableTemplates as $template)
                                @php
                                    $templateTitle = $template['title'][app()->getLocale()] ?: $template['title']['en'];
                                    $templateCity = $template['city'][app()->getLocale()] ?: $template['city']['en'];
                                @endphp
                                <option value="{{ $template['id'] }}">
                                    {{ $templateTitle }} — {{ $templateCity }} — {{ __('recruitment.application_form.template_version', ['version' => $template['version']]) }}
                                </option>
                            @endforeach
                        </select>
                        <button
                            type="button"
                            x-on:click="$dispatch('open-recruitment-confirmation', { scope: 'application-form-editor', action: 'applyPublishedTemplate', message: @js(__('recruitment.application_form.reuse_confirm')), confirmLabel: @js(__('recruitment.application_form.use_template')) })"
                            class="shrink-0 cursor-pointer whitespace-nowrap rounded-control bg-secondary-dark px-5 py-2.5 text-body sm:py-0 font-semibold text-white hover:bg-secondary-dark disabled:cursor-wait disabled:opacity-50"
                        >{{ __('recruitment.application_form.use_template') }}</button>
                    </div>
                    <x-error-span>{{ $errors->first('selectedTemplateVersionId') }}</x-error-span>
                </div>
            @else
                <p class="rounded-control bg-secondary-soft/30 px-4 py-3 text-small text-secondary-medium">{{ __('recruitment.application_form.no_reusable_forms') }}</p>
            @endif
        </div>
    </section>

    <section x-show="addPanel" x-collapse x-cloak class="mb-5 overflow-hidden rounded-surface border border-secondary-soft bg-white shadow-e1">
        <div class="border-b border-secondary-soft bg-secondary-soft/30 px-5 py-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-subtitle font-bold text-secondary-dark">{{ __('recruitment.application_form.add_question') }}</h2>
                    <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment.application_form.add_question_help') }}</p>
                </div>
                <button type="button" x-on:click="addPanel = false" class="rounded-full p-2 text-secondary-medium hover:bg-secondary-soft" aria-label="{{ __('recruitment.application_form.close') }}">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="m6 6 12 12M18 6 6 18"/></svg>
                </button>
            </div>
        </div>

        <div class="grid gap-6 p-5 xl:grid-cols-2">
            <div>
                <h3 class="font-semibold text-secondary-dark">{{ __('recruitment.application_form.question_library') }}</h3>
                <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment.application_form.question_library_help') }}</p>

                <div class="mt-4 max-h-80 space-y-4 overflow-y-auto pe-1">
                    @forelse ($sections as $sectionKey => $section)
                        @php $sectionAvailable = $availableFields->where('section', $sectionKey); @endphp
                        @if ($sectionAvailable->isNotEmpty())
                            <div>
                                <p class="mb-2 text-small font-semibold text-secondary-dark">{{ $section['label'][app()->getLocale()] ?? $section['label']['en'] }}</p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($sectionAvailable as $key => $definition)
                                        <button type="button" wire:click="addField('{{ $key }}')" class="rounded-full border border-secondary-light bg-white px-3 py-1.5 text-small font-medium text-secondary-dark hover:border-primary hover:text-primary">
                                            + {{ $definition['label'][app()->getLocale()] ?? $definition['label']['en'] }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @empty
                        <p class="rounded-control bg-secondary-soft/30 p-4 text-small text-secondary-medium">{{ __('recruitment.application_form.library_empty') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="border-t border-secondary-soft pt-5 xl:border-s xl:border-t-0 xl:ps-6 xl:pt-0">
                <h3 class="font-semibold text-secondary-dark">{{ __('recruitment.application_form.custom_question') }}</h3>
                <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment.application_form.custom_question_help') }}</p>

                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <x-forms.input-label for="custom-type" required>{{ __('recruitment.application_form.question_type') }}</x-forms.input-label>
                        <select id="custom-type" wire:model.live="customType" class="w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary">
                            @foreach ($types as $key => $type)
                                <option value="{{ $key }}">{{ $type['label'][app()->getLocale()] ?? $type['label']['en'] }}</option>
                            @endforeach
                        </select>
                        <x-error-span>{{ $errors->first('customType') }}</x-error-span>
                    </div>
                    <div>
                        <x-forms.input-label for="custom-section" required>{{ __('recruitment.application_form.section') }}</x-forms.input-label>
                        <select id="custom-section" wire:model="customSection" class="w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary">
                            @foreach ($sections as $key => $section)
                                <option value="{{ $key }}">{{ $section['label'][app()->getLocale()] ?? $section['label']['en'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-forms.input-label for="custom-label-en" required>{{ __('recruitment.application_form.label_en') }}</x-forms.input-label>
                        <x-forms.input id="custom-label-en" model="customLabelEn" dir="ltr" :placeholder="__('recruitment.application_form.label_en_placeholder')" required />
                        <x-error-span>{{ $errors->first('customLabelEn') }}</x-error-span>
                    </div>
                    <div>
                        <x-forms.input-label for="custom-label-ar" required>{{ __('recruitment.application_form.label_ar') }}</x-forms.input-label>
                        <x-forms.input id="custom-label-ar" model="customLabelAr" dir="rtl" :placeholder="__('recruitment.application_form.label_ar_placeholder')" required />
                        <x-error-span>{{ $errors->first('customLabelAr') }}</x-error-span>
                    </div>
                    <div>
                        <x-forms.input-label for="custom-help-en">{{ __('recruitment.application_form.help_en') }}</x-forms.input-label>
                        <x-forms.input id="custom-help-en" model="customHelpEn" dir="ltr" />
                    </div>
                    <div>
                        <x-forms.input-label for="custom-help-ar">{{ __('recruitment.application_form.help_ar') }}</x-forms.input-label>
                        <x-forms.input id="custom-help-ar" model="customHelpAr" dir="rtl" />
                    </div>

                    @if (in_array($customType, ['select', 'radio'], true))
                        <div>
                            <x-forms.input-label for="custom-options-en" required>{{ __('recruitment.application_form.options_en') }}</x-forms.input-label>
                            <textarea id="custom-options-en" wire:model="customOptionsEn" rows="4" dir="ltr" placeholder="Option 1&#10;Option 2" class="w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary"></textarea>
                            <x-error-span>{{ $errors->first('customOptionsEn') }}</x-error-span>
                        </div>
                        <div>
                            <x-forms.input-label for="custom-options-ar">{{ __('recruitment.application_form.options_ar') }}</x-forms.input-label>
                            <textarea id="custom-options-ar" wire:model="customOptionsAr" rows="4" dir="rtl" placeholder="الخيار 1&#10;الخيار 2" class="w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary"></textarea>
                        </div>
                    @endif
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <label class="inline-flex items-center gap-2 text-body text-secondary-dark">
                        <input type="checkbox" wire:model="customRequired" class="rounded border-secondary-light text-primary focus:ring-primary">
                        {{ __('recruitment.application_form.required') }}
                    </label>
                    <button type="button" wire:click="addCustomField" class="rounded-control bg-primary px-4 py-2 text-body font-semibold text-white hover:bg-primary/90">
                        {{ __('recruitment.application_form.add_custom_question') }}
                    </button>
                </div>
            </div>
        </div>
    </section>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(500px,0.92fr)_minmax(520px,1.08fr)]">
        <section class="min-w-0 space-y-4">
            @foreach ($sections as $sectionKey => $section)
                @php $sectionFields = collect($fields)->filter(fn ($field) => $field['section'] === $sectionKey); @endphp
                <details wire:key="application-form-section-{{ $sectionKey }}" class="group rounded-surface border border-secondary-soft bg-white shadow-e1" @if ($expandedSection === $sectionKey) open @endif>
                    <summary wire:click.prevent="toggleSection('{{ $sectionKey }}')" class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4">
                        <div>
                            <h3 class="font-bold text-secondary-dark">{{ $section['label'][app()->getLocale()] ?? $section['label']['en'] }}</h3>
                            <p class="mt-0.5 text-small text-secondary-medium">{{ $section['description'][app()->getLocale()] ?? $section['description']['en'] }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="rounded-full bg-secondary-soft/60 px-2.5 py-1 text-small font-semibold text-secondary-medium">{{ $sectionFields->count() }}</span>
                            <svg class="size-5 text-secondary-medium transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                        </div>
                    </summary>

                    <div class="space-y-3 border-t border-secondary-soft bg-secondary-soft/30 p-4">
                        @forelse ($sectionFields as $index => $field)
                            <article wire:key="form-field-{{ $field['key'] }}" class="rounded-lg border border-secondary-soft border-s-4 border-s-primary bg-white p-4 shadow-sm">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                                        <span class="font-semibold text-secondary-dark">{{ $field['label'][app()->getLocale()] ?? $field['label']['en'] }}</span>
                                        <span class="rounded-full bg-secondary-soft/60 px-2.5 py-1 text-small font-medium text-secondary-medium">{{ $types[$field['type']]['label'][app()->getLocale()] ?? $types[$field['type']]['label']['en'] }}</span>
                                        @if ($field['locked'])
                                            <span class="inline-flex items-center gap-1 text-small text-secondary-light">
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                                                {{ __('recruitment.application_form.protected') }}
                                            </span>
                                        @endif
                                    </div>

                                    <div class="flex items-center gap-1">
                                        <button type="button" wire:click="moveField({{ $index }}, -1)" class="rounded p-2 text-secondary-medium hover:bg-secondary-soft/60 hover:text-secondary-dark" aria-label="{{ __('recruitment.application_form.move_up') }}">
                                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 3a.75.75 0 0 1 .53.22l4 4a.75.75 0 0 1-1.06 1.06L10.75 5.56V16a.75.75 0 0 1-1.5 0V5.56L6.53 8.28a.75.75 0 0 1-1.06-1.06l4-4A.75.75 0 0 1 10 3Z" clip-rule="evenodd"/></svg>
                                        </button>
                                        <button type="button" wire:click="moveField({{ $index }}, 1)" class="rounded p-2 text-secondary-medium hover:bg-secondary-soft/60 hover:text-secondary-dark" aria-label="{{ __('recruitment.application_form.move_down') }}">
                                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 17a.75.75 0 0 1-.53-.22l-4-4a.75.75 0 1 1 1.06-1.06l2.72 2.72V4a.75.75 0 0 1 1.5 0v10.44l2.72-2.72a.75.75 0 1 1 1.06 1.06l-4 4A.75.75 0 0 1 10 17Z" clip-rule="evenodd"/></svg>
                                        </button>
                                        @unless ($field['locked'])
                                            <button type="button" x-on:click="$dispatch('open-recruitment-confirmation', { scope: 'application-form-editor', action: 'removeField', args: [{{ $index }}], message: @js(__('recruitment.application_form.remove_confirm')), confirmLabel: @js(__('recruitment.application_form.remove')), tone: 'danger' })" class="cursor-pointer rounded p-2 text-secondary-medium hover:bg-secondary-soft/60 hover:text-primary" aria-label="{{ __('recruitment.application_form.remove') }}">
                                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v5m4-5v5"/></svg>
                                            </button>
                                        @endunless
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-3 md:grid-cols-2">
                                    <div>
                                        <x-forms.input-label for="field-en-{{ $index }}" required>{{ __('recruitment.application_form.label_en') }}</x-forms.input-label>
                                        <x-forms.input id="field-en-{{ $index }}" model="fields.{{ $index }}.label.en" update="blur" dir="ltr" required />
                                        <x-error-span>{{ $errors->first("fields.$index.label.en") }}</x-error-span>
                                    </div>
                                    <div>
                                        <x-forms.input-label for="field-ar-{{ $index }}" required>{{ __('recruitment.application_form.label_ar') }}</x-forms.input-label>
                                        <x-forms.input id="field-ar-{{ $index }}" model="fields.{{ $index }}.label.ar" update="blur" dir="rtl" required />
                                        <x-error-span>{{ $errors->first("fields.$index.label.ar") }}</x-error-span>
                                    </div>
                                    <div>
                                        <x-forms.input-label for="field-help-en-{{ $index }}">{{ __('recruitment.application_form.help_en') }}</x-forms.input-label>
                                        <x-forms.input id="field-help-en-{{ $index }}" model="fields.{{ $index }}.help.en" update="blur" dir="ltr" />
                                    </div>
                                    <div>
                                        <x-forms.input-label for="field-help-ar-{{ $index }}">{{ __('recruitment.application_form.help_ar') }}</x-forms.input-label>
                                        <x-forms.input id="field-help-ar-{{ $index }}" model="fields.{{ $index }}.help.ar" update="blur" dir="rtl" />
                                    </div>
                                </div>

                                @if (in_array($field['type'], ['select', 'radio'], true))
                                    <div class="mt-4 rounded-control bg-secondary-soft/30 p-3">
                                        <div class="mb-3 flex items-center justify-between gap-3">
                                            <p class="text-small font-semibold text-secondary-dark">{{ __('recruitment.application_form.options') }}</p>
                                            <button
                                                type="button"
                                                wire:key="add-option-{{ $field['key'] }}"
                                                wire:click.stop="addOption({{ $index }})"
                                                wire:loading.attr="disabled"
                                                wire:target="addOption({{ $index }})"
                                                x-on:click="$el.blur()"
                                                class="text-small font-semibold text-primary hover:underline disabled:cursor-wait disabled:opacity-50"
                                            >+ {{ __('recruitment.application_form.add_option') }}</button>
                                        </div>
                                        <div class="space-y-2">
                                            @foreach ($field['options'] as $optionIndex => $option)
                                                <div wire:key="field-{{ $field['key'] }}-option-{{ $optionIndex }}" class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                                                    <input type="text" wire:model.blur="fields.{{ $index }}.options.{{ $optionIndex }}.label.en" dir="ltr" class="min-w-0 rounded-control border-secondary-light text-small focus:border-primary focus:ring-primary" aria-label="{{ __('recruitment.application_form.option_en') }}">
                                                    <input type="text" wire:model.blur="fields.{{ $index }}.options.{{ $optionIndex }}.label.ar" dir="rtl" class="min-w-0 rounded-control border-secondary-light text-small focus:border-primary focus:ring-primary" aria-label="{{ __('recruitment.application_form.option_ar') }}">
                                                    <button type="button" wire:click="removeOption({{ $index }}, {{ $optionIndex }})" class="cursor-pointer rounded p-2 text-secondary-light hover:bg-secondary-soft/60 hover:text-primary" aria-label="{{ __('recruitment.application_form.remove') }}">
                                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <div class="mt-4 flex flex-wrap items-center justify-end gap-4 border-t border-secondary-soft pt-4">
                                    <label class="inline-flex items-center gap-2 text-small font-medium text-secondary-dark">
                                        <span>{{ __('recruitment.application_form.required') }}</span>
                                        <input type="checkbox" wire:model.live="fields.{{ $index }}.required" @disabled($field['locked']) class="size-5 rounded border-secondary-light text-primary focus:ring-primary">
                                    </label>
                                </div>
                            </article>
                        @empty
                            <div class="rounded-control border border-dashed border-secondary-light bg-white p-6 text-center text-small text-secondary-medium">
                                {{ __('recruitment.application_form.no_questions_section') }}
                            </div>
                        @endforelse
                    </div>
                </details>
            @endforeach

            <div class="rounded-surface border border-secondary-soft bg-white p-5 shadow-e1">
                <x-forms.input-label for="privacy-version" required>{{ __('recruitment.application_form.privacy_version') }}</x-forms.input-label>
                <x-forms.input id="privacy-version" model="privacyNoticeVersion" dir="ltr" required />
                <x-error-span>{{ $errors->first('privacyNoticeVersion') }}</x-error-span>
                <p class="mt-2 text-small text-secondary-medium">{{ __('recruitment.application_form.privacy_help') }}</p>
            </div>
        </section>

        <aside class="min-w-0 xl:sticky xl:top-24">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-surface border border-secondary-soft bg-white p-3 shadow-e1">
                <div>
                    <p class="font-semibold text-secondary-dark">{{ __('recruitment.application_form.live_preview') }}</p>
                    <p class="text-small text-secondary-medium">{{ __('recruitment.application_form.live_preview_help') }}</p>
                </div>
                <div class="inline-flex rounded-control bg-secondary-soft/60 p-1" role="group" aria-label="{{ __('recruitment.application_form.preview_language') }}">
                    <button type="button" wire:click="setPreviewLocale('en')" class="rounded px-3 py-1.5 text-small font-semibold {{ $previewLocale === 'en' ? 'bg-white text-primary shadow-sm' : 'text-secondary-medium' }}">EN</button>
                    <button type="button" wire:click="setPreviewLocale('ar')" class="rounded px-3 py-1.5 text-small font-semibold {{ $previewLocale === 'ar' ? 'bg-white text-primary shadow-sm' : 'text-secondary-medium' }}">AR</button>
                </div>
            </div>

            <div
                id="application-form-preview-scroll"
                data-csb-exclude
                class="max-h-[calc(100vh-8rem)] touch-pan-y overflow-y-auto overscroll-contain rounded-surface border border-secondary-soft bg-secondary-soft/30 p-3 shadow-inner [scrollbar-color:#939598_transparent] [scrollbar-width:thin] sm:p-5"
            >
                <x-recruitment.application-form-preview
                    :campaign="$campaign"
                    :fields="$fields"
                    :sections="$sections"
                    :context="$formContext"
                    :locale="$previewLocale"
                    :active-section="$previewSection"
                />
            </div>

            <div class="mt-3 rounded-control bg-primary/5 p-3 text-small leading-6 text-secondary-medium">
                {{ __('recruitment.application_form.safety_note') }}
            </div>
        </aside>
    </div>
    <x-recruitment.confirmation-modal scope="application-form-editor" />
</div>
