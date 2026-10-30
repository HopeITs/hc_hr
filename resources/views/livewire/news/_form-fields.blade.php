<div class="grid grid-cols-2 gap-4">
    <div class="col-span-2 sm:col-span-1">
        <x-forms.input-label for="title_ar" required>@lang('general.title') (AR)</x-forms.input-label>
        <x-forms.input id="title_ar" type="text" wire:model.live="form.title_ar" dir="rtl"
            :examples="__('examples.news.title', [], 'ar')" :hint="__('examples.hints.news_title')" :hint-always="false" />
        <x-error-span>{{ $errors->first('form.title_ar') }}</x-error-span>
    </div>

    <div class="col-span-2 sm:col-span-1">
        <x-forms.input-label for="title_en">@lang('general.title') (EN)</x-forms.input-label>
        <x-forms.input id="title_en" type="text" wire:model.live="form.title_en" dir="ltr"
            :examples="__('examples.news.title', [], 'en')" :hint="__('examples.hints.news_title')" :hint-always="false" />
        <x-error-span>{{ $errors->first('form.title_en') }}</x-error-span>
    </div>

    <div class="col-span-2 sm:col-span-1">
        <x-forms.input-label for="description_ar" required>@lang('general.description') (AR)</x-forms.input-label>
        <x-forms.textarea id="description_ar" wire:model.live="form.description_ar" dir="rtl"
            :examples="__('examples.news.description', [], 'ar')" :hint="__('examples.hints.news_description')" :hint-always="false" />
        <x-error-span>{{ $errors->first('form.description_ar') }}</x-error-span>
    </div>

    <div class="col-span-2 sm:col-span-1">
        <x-forms.input-label for="description_en">@lang('general.description') (EN)</x-forms.input-label>
        <x-forms.textarea id="description_en" wire:model.live="form.description_en" dir="ltr"
            :examples="__('examples.news.description', [], 'en')" :hint="__('examples.hints.news_description')" :hint-always="false" />
        <x-error-span>{{ $errors->first('form.description_en') }}</x-error-span>
    </div>

    <div class="col-span-2 sm:col-span-1">
        <x-forms.input-label>@lang('general.target_type')</x-forms.input-label>
        <x-forms.select2-input wire:model.live="form.newsable_type">
            <option value="">@lang('general.all_org')</option>
            <option value="{{ \App\Models\City::class }}">{{ trans_choice('general.city', 1) }}</option>
            <option value="{{ \App\Models\Center::class }}">{{ trans_choice('general.center', 1) }}</option>
            <option value="{{ \App\Models\Program::class }}">{{ trans_choice('general.program', 1) }}</option>
            <option value="{{ \App\Models\Employee::class }}">{{ trans_choice('general.employee', 1) }}</option>
        </x-forms.select2-input>
        <x-error-span>{{ $errors->first('form.newsable_type') }}</x-error-span>
    </div>

    @if (!empty($form->newsable_type))
        <div class="col-span-2 sm:col-span-1" wire:key="entity-select-{{ $form->newsable_type }}">
            <x-forms.input-label>@lang('general.target_entity')</x-forms.input-label>
            <x-forms.select2-input wire:model.live="form.newsable_id">
                <option value="">@lang('general.select_entity')</option>
                @foreach ($this->form->entities() as $entity)
                    <option value="{{ $entity->id }}">{{ $entity->name }}</option>
                @endforeach
            </x-forms.select2-input>
            <x-error-span>{{ $errors->first('form.newsable_id') }}</x-error-span>
        </div>
    @endif

    <div class="col-span-2">
        <x-forms.input-label>@lang('general.photo')</x-forms.input-label>
        <x-forms.file-input wire:model.live="form.photo" name="form.photo" previewType="image"
            :oldFile="$form->photo ?: ($news?->getFirstMediaUrl('news-image') ?: null)"
            :fileName="$form->photo instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile
                ? $form->photo->getClientOriginalName() ?? null
                : $news?->getFirstMedia('news-image')?->file_name"
            accept=".jpg,.jpeg,.png,.gif,.webp" />
        <x-error-span>{{ $errors->first('form.photo') }}</x-error-span>
    </div>

    <div class="col-span-2 sm:col-span-1">
        <x-forms.input-label>@lang('general.display_until')</x-forms.input-label>
        <x-forms.input type="date" wire:model.live="form.display_until" />
        <x-error-span>{{ $errors->first('form.display_until') }}</x-error-span>
    </div>

    <div class="col-span-2 sm:col-span-1">
        <x-forms.input-label required>@lang('general.event_date')</x-forms.input-label>
        <x-forms.input type="date" wire:model.live="form.event_date" />
        <x-error-span>{{ $errors->first('form.event_date') }}</x-error-span>
    </div>
</div>
