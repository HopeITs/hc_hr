{{--
    D3 feedback dialog (DEC-08). Opened from the user menu with
    Livewire.dispatch('open-feedback'); mounted once in the app layout, so it
    never navigates or reloads the page underneath. Editable dialog (A5-M):
    the backdrop is ignored, and Cancel / Escape / close ask requestClose().
--}}
<div>
    <x-modal.dialog id="showFeedback" :title="__('feedback.form.title')" maxWidth="lg"
                    wire:model="showFeedback" close-action="requestClose" wire:ignore.self>
        <form wire:submit="submit" class="space-y-4 sheet:flex-1 sheet:flex sheet:flex-col" novalidate>
            {{-- What is sent besides the text, shown before anything is sent. --}}
            <section id="feedback-disclosure" aria-labelledby="feedback-disclosure-title"
                     class="rounded-surface border border-neutral-200 bg-neutral-50 p-3 text-body text-neutral-800">
                <h3 id="feedback-disclosure-title" class="font-semibold text-neutral-900">
                    @lang('feedback.disclosure.title')
                </h3>
                <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                    <dt class="text-secondary-medium">@lang('feedback.disclosure.page')</dt>
                    <dd class="min-w-0 break-words" data-feedback-context="page">
                        @if ($pageRoute)
                            <bdi dir="ltr" class="font-mono text-small">{{ $pageRoute }}</bdi>
                        @else
                            @lang('feedback.disclosure.page-unknown')
                        @endif
                    </dd>
                    <dt class="text-secondary-medium">@lang('feedback.disclosure.language')</dt>
                    <dd data-feedback-context="language">{{ $localeLabel }}</dd>
                    <dt class="text-secondary-medium">@lang('feedback.disclosure.role')</dt>
                    <dd data-feedback-context="role"><bdi>{{ $roleLabel }}</bdi></dd>
                    <dt class="text-secondary-medium">@lang('feedback.disclosure.account')</dt>
                    <dd>@lang('feedback.disclosure.account-value')</dd>
                </dl>
                <ul class="mt-3 list-disc space-y-1 ps-5">
                    <li>@lang('feedback.disclosure.text-only')</li>
                    <li class="font-semibold">@lang('feedback.disclosure.no-personal')</li>
                    <li>@lang('feedback.disclosure.retention')</li>
                    <li>@lang('feedback.disclosure.readers')</li>
                </ul>
            </section>

            <div>
                <x-forms.input-label for="feedback-category">@lang('feedback.form.category')</x-forms.input-label>
                <select id="feedback-category" wire:model="category"
                        class="mt-1 min-h-11 w-full rounded-control border border-neutral-300 bg-white px-3 text-body shadow-e1 focus:border-primary focus:ring-secondary-light"
                        aria-describedby="feedback-category-error">
                    <option value="">@lang('feedback.form.category-none')</option>
                    @foreach (config('feedback.categories') as $value)
                        <option value="{{ $value }}">@lang('feedback.categories.'.$value)</option>
                    @endforeach
                </select>
                <x-error-span id="feedback-category-error">{{ $errors->first('category') }}</x-error-span>
            </div>

            <div x-data="{ count: $wire.message.length }">
                <x-forms.input-label for="feedback-message" required>@lang('feedback.form.message')</x-forms.input-label>
                <x-forms.textarea id="feedback-message" wire:model="message" rows="6" dir="auto"
                                  maxlength="{{ config('feedback.max_length') }}"
                                  x-on:input="count = $el.value.length"
                                  :placeholder="__('feedback.form.placeholder')"
                                  aria-required="true"
                                  aria-invalid="{{ $errors->has('message') ? 'true' : 'false' }}"
                                  aria-describedby="feedback-message-help feedback-message-error"
                                  class="mt-1"></x-forms.textarea>
                <p id="feedback-message-help" class="mt-1 flex justify-between gap-2 text-small text-secondary-medium">
                    <span>@lang('feedback.form.help')</span>
                    <span aria-live="off"><bdi dir="ltr"><span x-text="count">0</span>/{{ config('feedback.max_length') }}</bdi></span>
                </p>
                <x-error-span id="feedback-message-error">{{ $errors->first('message') }}</x-error-span>
            </div>

            <x-modal.actions>
                <x-primary-button type="submit" class="!w-auto" wire:loading.attr="disabled" wire:target="submit">
                    @lang('feedback.form.send')
                </x-primary-button>
                <x-modal.cancel-btn/>
            </x-modal.actions>
        </form>
    </x-modal.dialog>
</div>
