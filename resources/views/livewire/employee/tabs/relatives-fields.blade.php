<form wire:submit.prevent="saveRelatives" class="space-y-6">
    @foreach ($relativeForm->relatives as $index => $relative)
        <input type="hidden" wire:model="relativeForm.relatives.{{ $index }}.id">

        <div wire:key="relative-{{ $index }}" class="relative border-b pb-6 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="md:col-span-2 lg:col-span-3 flex justify-end">
                    <x-primary-button type="button"
                                      :showSpinner="false"
                        x-on:click="if ({{ filled($relative['id'] ?? null) ? 'await $confirm()' : 'true' }}) $wire.removeRelative({{ $index }}, {{ $relative['id'] ?? 'null' }})"
                        :title="__('general.delete')" :aria-label="__('general.delete')">
                        <x-icons.trash />
                    </x-primary-button>
                </div>

                <div>
                    <x-forms.input-label for="relative_name_{{ $index }}" required>@lang('general.name')</x-forms.input-label>
                    <x-forms.input id="relative_name_{{ $index }}" type="text"
                        wire:model.defer="relativeForm.relatives.{{ $index }}.name" />
                    <x-error-span>{{ $errors->first("relativeForm.relatives.$index.name") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="relation_{{ $index }}" required>@lang('general.relation')</x-forms.input-label>
                    <x-forms.select2-input id="relation_{{ $index }}"
                                          wire:model.defer="relativeForm.relatives.{{ $index }}.relation" >
                        @foreach(\App\Enums\KinshipType::getValues() as $key => $value)
                            <option value="{{$value}}">{{\App\Enums\KinshipType::getLocalizedName($value)}}</option>
                        @endforeach
                    </x-forms.select2-input>

                    <x-error-span>{{ $errors->first("relativeForm.relatives.$index.relation") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="phone_{{ $index }}">@lang('general.phone')</x-forms.input-label>
                    <x-forms.input id="phone_{{ $index }}" type="tel" dir="ltr"
                        wire:model.defer="relativeForm.relatives.{{ $index }}.phone" />
                    <x-error-span>{{ $errors->first("relativeForm.relatives.$index.phone") }}</x-error-span>
                </div>

            </div>
        </div>
    @endforeach

    <div class="flex justify-center">
        <x-primary-button type="button"
                          :showSpinner="false" wire:click="addRelative">
            + @lang('general.add-new')
        </x-primary-button>
    </div>

    <x-forms.dirty-gate watch="relativeForm" :active="! $isCreateMode"
        class="flex justify-end space-x-2 pt-4 mt-6 border-t">
        <x-primary-button type="submit" class="!w-auto" x-bind:disabled="! dirty">@lang('general.save-relatives')</x-primary-button>
    </x-forms.dirty-gate>
</form>
