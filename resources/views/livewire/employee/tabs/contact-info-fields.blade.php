@php
    $computedWorkEmailUsername = $infoForm->username ?? '';
@endphp
<form wire:submit.prevent="saveInformation" class="space-y-6">
    <div class="grid md:grid-cols-2 gap-x-6 gap-y-3 border-b pb-8 mb-8">
        <x-heading-label>@lang('general.employment_info')</x-heading-label>

        <div>
            <x-forms.input-label for="username" required>@lang('general.username')</x-forms.input-label>
            <div class="flex rtl:flex-row-reverse">
                <x-forms.input type="text" wire:model="infoForm.username" id="username"
                    @class(['rounded-r-none' => data_get($infoForm, 'create_account')])
                    dir="ltr" placeholder="{{ __('employees.form.username-placeholder') }}" />
                @if (data_get($infoForm, 'create_account'))
                    <span
                        class="inline-flex items-center px-3 ltr:rounded-e-md rtl:rounded-s-md shadow-e2 border ltr:border-s-0 rtl:border-e-0 border-neutral-300 bg-neutral-50 text-secondary-medium sm:text-body">
                        <bdi dir="ltr">@hcsyria.org</bdi>
                    </span>
                @endif
            </div>
            <x-error-span>{{ $errors->first('infoForm.username') }}</x-error-span>

            {{-- Optional: create a platform login account (work email) --}}
            <label class="flex items-start gap-2 mt-3 cursor-pointer">
                <x-forms.input type="checkbox" wire:model.live="infoForm.create_account" value="1"
                    id="create_account" class="mt-1" />
                <span>
                    <span class="text-body text-secondary-dark">@lang('general.create_login_account')</span>
                    @if (data_get($infoForm, 'create_account'))
                        <span class="block text-small text-secondary-light">@lang('general.create_login_account_hint')</span>
                    @endif
                </span>
            </label>
            <x-error-span>{{ $errors->first('infoForm.email') }}</x-error-span>
        </div>

        <div>
            <x-forms.input-label for="work_phone">@lang('general.work_phone')</x-forms.input-label>
            <x-forms.input type="tel" wire:model="infoForm.work_phone" id="work_phone" dir="ltr"
                inputmode="numeric" maxlength="10" autocomplete="tel"
                placeholder="{{ __('general.phone_placeholder') }}" />
            <x-error-span>{{ $errors->first('infoForm.work_phone') }}</x-error-span>
        </div>

    </div>

    <div class="grid md:grid-cols-2 gap-x-6 gap-y-3 border-b pb-8 mb-8">
        <x-heading-label>@lang('general.contact_info')</x-heading-label>

        <div>
            <x-forms.input-label for="personal_phone">@lang('general.personal_phone')</x-forms.input-label>
            <x-forms.input type="tel" wire:model="infoForm.personal_phone" id="personal_phone" dir="ltr"
                inputmode="numeric" maxlength="10" autocomplete="tel"
                placeholder="{{ __('general.phone_placeholder') }}" />
            <x-error-span>{{ $errors->first('infoForm.personal_phone') }}</x-error-span>
        </div>

        <div>
            <x-forms.input-label for="personal_email">@lang('general.personal_email')</x-forms.input-label>
            <x-forms.input type="email" wire:model="infoForm.personal_email" id="personal_email" dir="ltr" />
            <x-error-span>{{ $errors->first('infoForm.personal_email') }}</x-error-span>
        </div>

        <div>
            <x-forms.input-label for="whatsapp_work">{{ __('employees.form.work-channel', ['channel' => 'WhatsApp']) }}</x-forms.input-label>
            <x-forms.input type="text" wire:model="infoForm.whatsapp_work" id="whatsapp_work" dir="ltr" />
            <x-error-span>{{ $errors->first('infoForm.whatsapp_work') }}</x-error-span>
        </div>

        <div>
            <x-forms.input-label for="telegram_work">{{ __('employees.form.work-channel', ['channel' => 'Telegram']) }}</x-forms.input-label>
            <x-forms.input type="text" wire:model="infoForm.telegram_work" id="telegram_work" dir="ltr" />
            <x-error-span>{{ $errors->first('infoForm.telegram_work') }}</x-error-span>
        </div>

        <div>
            <x-forms.input-label for="facebook">Facebook</x-forms.input-label>
            <x-forms.input type="url" wire:model="infoForm.facebook" id="facebook" dir="ltr"
                placeholder="https://www.facebook.com/your-page" />
            <x-error-span>{{ $errors->first('infoForm.facebook') }}</x-error-span>
        </div>

        <div>
            <x-forms.input-label for="instagram">Instagram</x-forms.input-label>
            <x-forms.input type="url" wire:model="infoForm.instagram" id="instagram" dir="ltr"
                placeholder="https://www.instagram.com/your-handle" />
            <x-error-span>{{ $errors->first('infoForm.instagram') }}</x-error-span>
        </div>

        <div class="md:col-span-2">
            <x-forms.input-label for="linkedin">LinkedIn</x-forms.input-label>
            <x-forms.input type="url" wire:model="infoForm.linkedin" id="linkedin" dir="ltr"
                placeholder="https://www.linkedin.com/in/your-profile" />
            <x-error-span>{{ $errors->first('infoForm.linkedin') }}</x-error-span>
        </div>
    </div>

    <x-forms.dirty-gate watch="infoForm" :active="! $isCreateMode" class="flex justify-end space-x-2">
        <x-primary-button type="submit" class="!w-auto" x-bind:disabled="! dirty">@lang('general.save')</x-primary-button>
        @if ($isCreateMode)
            <x-secondary-button :href="route('employees.index')" class="!w-auto">@lang('general.cancel')</x-secondary-button>
        @endif
    </x-forms.dirty-gate>


</form>

