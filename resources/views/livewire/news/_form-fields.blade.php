{{-- 1. Content: type, titles, descriptions, cover image. --}}
@php
    use App\Enums\News\NewsType;
    use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

    $uploaded = $form->photo instanceof TemporaryUploadedFile ? $form->photo : null;
@endphp

<section class="rounded-surface border border-line-soft bg-card shadow-e1" aria-labelledby="news-section-content">
    <header class="flex items-start gap-3 border-b border-line-soft px-5 py-4">
        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-ink text-small font-bold text-on-ink num" aria-hidden="true">1</span>
        <div class="min-w-0">
            <h2 id="news-section-content" class="text-h4 font-extrabold text-ink">@lang('news.form.sections.content')</h2>
            <p class="text-small text-ink-2">@lang('news.form.sections.content_hint')</p>
        </div>
    </header>

    <div class="space-y-6 p-5">
        {{-- Type: segmented radio cards. --}}
        <fieldset>
            <legend class="mb-1.5 block text-body font-bold text-ink">
                @lang('news.form.fields.type')
                <span class="text-primary ms-1" aria-hidden="true">*</span>
                <span class="sr-only">({{ __('general.a11y.required-field') }})</span>
            </legend>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach (NewsType::cases() as $type)
                    <label class="cursor-pointer" wire:key="news-type-{{ $type->value }}">
                        <input type="radio" class="peer sr-only" name="form.type" value="{{ $type->value }}"
                               wire:model.live="form.type">
                        <span class="hc-choice flex h-full items-start gap-3 p-3">
                            <x-ic :name="$type->icon()" class="mt-0.5 shrink-0 text-ink-2" />
                            <span class="min-w-0">
                                <span class="block font-bold text-ink">{{ $type->label() }}</span>
                                <span class="block text-small text-ink-2">{{ $type->hint() }}</span>
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            <x-error-span>{{ $errors->first('form.type') }}</x-error-span>
        </fieldset>

        {{-- Titles. --}}
        <div class="grid gap-x-4 gap-y-2 md:grid-cols-2">
            <div>
                <x-forms.input-label for="title_ar" required>@lang('news.form.fields.title_ar')</x-forms.input-label>
                <x-forms.input id="title_ar" type="text" wire:model.blur="form.title_ar" dir="rtl" lang="ar"
                    :examples="__('examples.news.title', [], 'ar')" :hint="__('examples.hints.news_title')" :hint-always="false" />
                <x-error-span id="title_ar-error">{{ $errors->first('form.title_ar') }}</x-error-span>
            </div>

            <div>
                <x-forms.input-label for="title_en">@lang('news.form.fields.title_en')</x-forms.input-label>
                <x-forms.input id="title_en" type="text" wire:model.blur="form.title_en" dir="ltr" lang="en"
                    :examples="__('examples.news.title', [], 'en')" :hint="__('examples.hints.news_title')" :hint-always="false" />
                <x-error-span id="title_en-error">{{ $errors->first('form.title_en') }}</x-error-span>
            </div>
        </div>

        {{-- Descriptions, side by side on md+. --}}
        <div class="grid gap-x-4 gap-y-2 md:grid-cols-2">
            <div>
                <x-forms.input-label for="description_ar" required>@lang('news.form.fields.description_ar')</x-forms.input-label>
                <x-forms.textarea id="description_ar" wire:model.blur="form.description_ar" dir="rtl" lang="ar" rows="7"
                    :examples="__('examples.news.description', [], 'ar')" :hint="__('examples.hints.news_description')" :hint-always="false" />
                <x-error-span>{{ $errors->first('form.description_ar') }}</x-error-span>
            </div>

            <div>
                <x-forms.input-label for="description_en">@lang('news.form.fields.description_en')</x-forms.input-label>
                <x-forms.textarea id="description_en" wire:model.blur="form.description_en" dir="ltr" lang="en" rows="7"
                    :examples="__('examples.news.description', [], 'en')" :hint="__('examples.hints.news_description')" :hint-always="false" />
                <x-error-span>{{ $errors->first('form.description_en') }}</x-error-span>
            </div>
        </div>

        {{-- Cover image. --}}
        <div>
            <x-forms.input-label for="news-photo">@lang('news.form.fields.photo')</x-forms.input-label>
            <x-forms.file-input id="news-photo" wire:model.live="form.photo" name="form.photo" previewType="image"
                :oldFile="$uploaded ?: ($news?->getFirstMediaUrl('news-image') ?: null)"
                :fileName="$uploaded ? $uploaded->getClientOriginalName() : $news?->getFirstMedia('news-image')?->file_name"
                accept=".jpg,.jpeg,.png,.gif,.webp" />
            {{-- The file input prints its own error (it has a name). --}}
            <p class="mt-1 text-small text-ink-2">@lang('news.form.help.photo')</p>
        </div>
    </div>
</section>
