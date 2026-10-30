<x-slot:title>
    {{ $title }} - {{ trans_choice('general.program', 1) }}
</x-slot:title>

<div>
    <livewire:layout.breadcrumbs
        :title="__('general.programs-management')"
        :crumbs="[
            ['label' => trans_choice('general.program', 2), 'url' => route('programs.index')],
            ['label' => $title]
        ]"
        :showButton="true"
    />

    <x-forms.form-body wire:submit.prevent="save">
        <div class="grid grid-cols-2 gap-4">
            <div class="col-span-2">
                <x-forms.input-label for="logo">@lang('general.logo')</x-forms.input-label>
                <x-forms.file-input
                    id="logo"
                    wire:model="form.logo"
                    accept="image/*"
                    name="form.logo"
                    :old-file="$form->logo"
                />
            </div>

            <div>
                <x-forms.input-label for="code" required>@lang('general.code')</x-forms.input-label>
                <x-forms.input type="text" id="code" wire:model.defer="form.code" />
                <x-error-span>{{ $errors->first('form.code') }}</x-error-span>
            </div>

            <div>
                <x-forms.input-label for="slug" required>@lang('general.slug')</x-forms.input-label>
                <x-forms.input type="text" id="slug" wire:model.defer="form.slug" />
                <x-error-span>{{ $errors->first('form.slug') }}</x-error-span>
            </div>

            <div class="col-span-2">
                <x-forms.input-label for="name" required>@lang('general.name')</x-forms.input-label>
                <x-forms.input type="text" id="name" wire:model.defer="form.name" />
                <x-error-span>{{ $errors->first('form.name') }}</x-error-span>
            </div>



            @foreach (config('app.supported_locales') as $locale)
                <div class="col-span-1">
                    <x-forms.input-label for="description_{{ $locale }}">
                        @lang('general.description') ({{ strtoupper($locale) }})
                    </x-forms.input-label>
                    <x-forms.textarea
                        type="text"
                        id="description_{{ $locale }}"
                        wire:model.defer="form.description_{{ $locale }}"
                    />
                    <x-error-span>{{ $errors->first('form.description_' . $locale) }}</x-error-span>
                </div>
            @endforeach

            <div>
                <x-forms.input-label for="start_date" required>@lang('general.start-date')</x-forms.input-label>
                <x-forms.date-input id="start_date" wire:model.defer="form.start_date" />
                <x-error-span>{{ $errors->first('form.start_date') }}</x-error-span>
            </div>

            <div>
                <x-forms.input-label for="end_date">@lang('general.end-date')</x-forms.input-label>
                <x-forms.date-input id="end_date" wire:model.defer="form.end_date" />
                <x-error-span>{{ $errors->first('form.end_date') }}</x-error-span>
            </div>
        </div>

        <div class="flex justify-end space-x-2 pt-4">
            <x-button type="submit">@lang('general.save')</x-button>
            <x-button variant="outline" :href="route('programs.index')">@lang('general.cancel')</x-button>

        </div>
    </x-forms.form-body>
</div>
