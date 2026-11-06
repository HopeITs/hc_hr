{{-- 3. Publishing: now or scheduled, the event's own dates, Display until. --}}
@php
    $isEvent = $form->typeEnum()->isEvent();
    $scheduled = $form->isScheduled();
    $modes = [
        'now' => ['icon' => 'send', 'label' => __('news.form.modes.now'), 'hint' => __('news.form.modes.now_hint')],
        'schedule' => ['icon' => 'clock', 'label' => __('news.form.modes.schedule'), 'hint' => __('news.form.modes.schedule_hint')],
    ];
@endphp

<section class="rounded-surface border border-line-soft bg-card shadow-e1" aria-labelledby="news-section-publishing">
    <header class="flex items-start gap-3 border-b border-line-soft px-5 py-4">
        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ink text-small font-bold text-on-ink num" aria-hidden="true">3</span>
        <div class="min-w-0">
            <h2 id="news-section-publishing" class="text-h4 font-extrabold text-ink">@lang('news.form.sections.publishing')</h2>
            <p class="text-small text-ink-2">@lang('news.form.sections.publishing_hint')</p>
        </div>
    </header>

    <div class="space-y-6 p-5">
        {{-- Publish immediately / Schedule --}}
        <fieldset @unless ($scheduled) aria-describedby="news-publish-help" @endunless>
            <legend class="mb-1.5 block text-body font-bold text-ink">@lang('news.form.fields.publish_mode')</legend>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($modes as $value => $mode)
                    <label class="cursor-pointer" wire:key="news-mode-{{ $value }}">
                        <input type="radio" class="peer sr-only" name="form.publish_mode" value="{{ $value }}"
                               wire:model.live="form.publish_mode">
                        <span class="hc-choice flex h-full items-start gap-3 p-3">
                            <x-ic :name="$mode['icon']" class="mt-0.5 shrink-0 text-ink-2" />
                            <span class="min-w-0">
                                <span class="block font-bold text-ink">{{ $mode['label'] }}</span>
                                <span class="block text-small text-ink-2">{{ $mode['hint'] }}</span>
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            <x-error-span>{{ $errors->first('form.publish_mode') }}</x-error-span>
        </fieldset>

        <div class="grid gap-x-4 gap-y-6 md:grid-cols-2">
            @if ($scheduled)
                <div wire:key="news-publish-at">
                    <x-forms.input-label for="news-publish-at" required>@lang('news.form.fields.publish_at')</x-forms.input-label>
                    <x-forms.input id="news-publish-at" type="datetime-local" wire:model.blur="form.publish_at"
                                   :hint="__('news.form.help.publish_at')" />
                    <x-error-span id="news-publish-at-error">{{ $errors->first('form.publish_at') }}</x-error-span>
                </div>
            @else
                <p id="news-publish-help" class="flex items-start gap-2 self-start rounded-control bg-soft p-3 text-small text-ink-2 md:col-span-2">
                    <x-ic name="info" class="mt-0.5 shrink-0" />
                    <span>@lang('news.form.help.publish_at')</span>
                </p>
            @endif
        </div>

        @if ($isEvent)
            <div class="grid gap-x-4 gap-y-6 md:grid-cols-2" wire:key="news-event-dates">
                <div>
                    <x-forms.input-label for="news-event-starts" required>@lang('news.form.fields.event_starts_at')</x-forms.input-label>
                    <x-forms.input id="news-event-starts" type="datetime-local" wire:model.blur="form.event_starts_at"
                                   :hint="__('news.form.help.event')" />
                    <x-error-span id="news-event-starts-error">{{ $errors->first('form.event_starts_at') }}</x-error-span>
                </div>
                <div>
                    <x-forms.input-label for="news-event-ends">@lang('news.form.fields.event_ends_at')</x-forms.input-label>
                    <x-forms.input id="news-event-ends" type="datetime-local" wire:model.blur="form.event_ends_at"
                                   :hint="__('news.form.help.event_ends_at')" />
                    <x-error-span id="news-event-ends-error">{{ $errors->first('form.event_ends_at') }}</x-error-span>
                </div>
            </div>
        @endif

        <div class="grid gap-x-4 gap-y-6 md:grid-cols-2">
            <div>
                <x-forms.input-label for="news-display-until">@lang('news.form.fields.display_until')</x-forms.input-label>
                <x-forms.input id="news-display-until" type="datetime-local" wire:model.blur="form.display_until"
                               :hint="__('news.form.help.display_until').($isEvent ? ' '.__('news.form.help.display_until_event') : '')" />
                <x-error-span id="news-display-until-error">{{ $errors->first('form.display_until') }}</x-error-span>
            </div>
        </div>
    </div>
</section>
