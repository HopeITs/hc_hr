<form wire:submit.prevent="saveAttachments" class="space-y-6">

    @foreach ($attachmentForm->attachments as $index => $attachment)
        <input type="hidden" wire:model="attachmentForm.attachments.{{ $index }}.media_id">

        <div wire:key="attachment-{{ $index }}" class="relative border-b pb-6 mb-6">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <div class="md:col-span-2 flex justify-end">
                    <x-primary-button type="button" :showSpinner="false"
                        x-on:click="if ({{ filled($attachment['media_id'] ?? null) ? 'await $confirm()' : 'true' }}) $wire.removeAttachment({{ $index }}, {{ $attachment['media_id'] ?? 'null' }})"
                        :title="__('general.delete')" :aria-label="__('general.delete')">
                        <x-icons.trash />
                    </x-primary-button>
                </div>

                <div>
                    <x-forms.input-label for="attachment_name_{{ $index }}">@lang('general.name')</x-forms.input-label>
                    <x-forms.input type="text" id="attachment_name_{{ $index }}"
                        wire:model.defer="attachmentForm.attachments.{{ $index }}.name" />
                    @error("attachmentForm.attachments.{$index}.name")
                        <x-error-span>{{ $message }}</x-error-span>
                    @enderror
                </div>

                <div>
                    <x-forms.input-label for="attachment_category_{{ $index }}">@lang('general.category')</x-forms.input-label>
                    <x-forms.select2-input id="attachment_category_{{ $index }}"
                        wire:model.defer="attachmentForm.attachments.{{ $index }}.category">
                        <option value="">{{ __('general.choose') }}</option>
                        @foreach (\App\Enums\AttachmentCategory::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    @error("attachmentForm.attachments.{$index}.category")
                        <x-error-span>{{ $message }}</x-error-span>
                    @enderror
                </div>

                <div>
                    <x-forms.input-label>@lang('general.file')</x-forms.input-label>
                    {{--
                        A just-picked file takes precedence over the stored
                        one. Passing only `old_file_url` here meant the
                        preview kept showing the OLD attachment after you
                        chose a replacement, as if the pick had not taken.
                    --}}
                    <x-forms.file-input wire:model="attachmentForm.attachments.{{ $index }}.file"
                        preview-type="pdf" :old-file="$attachment['file'] ?: ($attachment['old_file_url'] ?? null)" :file-name="$attachment['file_name'] ?? null" accept=".pdf, .jpg, .jpeg, .png" />
                    @error("attachmentForm.attachments.{$index}.file")
                        <x-error-span>{{ $message }}</x-error-span>
                    @enderror
                </div>

            </div>
        </div>
    @endforeach

    <div class="flex justify-center">
        <x-primary-button type="button" :showSpinner="false" wire:click="addAttachment">
            + @lang('general.add-new')
        </x-primary-button>
    </div>

    <x-forms.dirty-gate watch="attachmentForm" :active="! $isCreateMode"
        class="flex justify-end space-x-2 pt-4 mt-6 border-t">
        <x-primary-button type="submit" x-bind:disabled="! dirty">@lang('general.save-attachments')</x-primary-button>
    </x-forms.dirty-gate>
</form>
