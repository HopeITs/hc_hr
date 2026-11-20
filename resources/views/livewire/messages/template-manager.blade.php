<div>
    <x-slot:title>{{ __('messages.title') }}</x-slot:title>

    <x-page-header :title="__('messages.title')" :lead="__('messages.lead')">
        <x-slot:actions>
            <x-button variant="outline" x-on:click="Livewire.dispatch('announcement-compose')" data-testid="open-announcements">@lang('messages.announcements.title')</x-button>
            <x-button wire:click="newTemplate">@lang('messages.new')</x-button>
        </x-slot:actions>
    </x-page-header>

    @if($formOpen)
        <x-card class="mb-6">
            <form wire:submit.prevent="save" class="space-y-4" data-testid="template-form">
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="md:col-span-1">
                        <x-forms.input-label for="tpl-name" required>@lang('messages.name')</x-forms.input-label>
                        <x-forms.input id="tpl-name" wire:model="name" />
                        <x-forms.input-error field="name" input-id="tpl-name" />
                    </div>
                    <div>
                        <x-forms.input-label for="tpl-channel" required>@lang('messages.channel')</x-forms.input-label>
                        <x-forms.select-input id="tpl-channel" wire:model.live="channel">
                            <option value="email">@lang('messages.email')</option>
                            <option value="whatsapp">WhatsApp</option>
                        </x-forms.select-input>
                    </div>
                    <div>
                        <x-forms.input-label for="tpl-language" required>@lang('messages.language')</x-forms.input-label>
                        <x-forms.select-input id="tpl-language" wire:model="language">
                            <option value="en">English</option>
                            <option value="ar">العربية</option>
                        </x-forms.select-input>
                    </div>
                </div>

                @if($channel === 'email')
                    <div>
                        <x-forms.input-label for="tpl-subject" required>@lang('messages.subject')</x-forms.input-label>
                        <x-forms.input id="tpl-subject" wire:model="subject" />
                        <x-forms.input-error field="subject" input-id="tpl-subject" />
                    </div>
                @endif

                <div>
                    <x-forms.input-label for="tpl-body" required>@lang('messages.body')</x-forms.input-label>
                    <textarea id="tpl-body" wire:model="body" rows="8" dir="auto"
                              class="block w-full rounded-control border border-line bg-card px-3 py-2 text-body text-ink"></textarea>
                    <x-forms.input-error field="body" input-id="tpl-body" />
                    <p class="mt-2 text-small text-ink-2">
                        @lang('messages.variables-help')
                        @foreach($variables as $variable)
                            <code class="mx-0.5 rounded bg-soft px-1" dir="ltr">{{ '{'.$variable.'}' }}</code>
                        @endforeach
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-6">
                    @if($channel === 'email')
                        <label class="inline-flex items-center gap-2 text-body text-ink">
                            <input type="checkbox" wire:model="cc_manager" class="size-4 rounded border-line">
                            @lang('messages.cc-manager')
                        </label>
                    @endif
                    <label class="inline-flex items-center gap-2 text-body text-ink">
                        <input type="checkbox" wire:model="is_active" class="size-4 rounded border-line">
                        @lang('messages.active')
                    </label>
                </div>

                <div class="flex items-center gap-2">
                    <x-button type="submit">@lang('general.save')</x-button>
                    <x-button variant="quiet" :loading="false" wire:click="resetForm">@lang('general.cancel')</x-button>
                </div>
            </form>
        </x-card>
    @endif

    @if($templates->isEmpty())
        <x-center-empty-state variant="empty" :title="__('messages.empty')" :description="__('messages.empty-help')" />
    @else
        <div class="overflow-x-auto rounded-surface border border-line-soft bg-card">
            <table class="w-full text-start text-body">
                <thead class="bg-soft text-small text-ink-2">
                    <tr>
                        <th class="px-4 py-2 text-start">@lang('messages.name')</th>
                        <th class="px-4 py-2 text-start">@lang('messages.channel')</th>
                        <th class="px-4 py-2 text-start">@lang('messages.language')</th>
                        <th class="px-4 py-2 text-start">@lang('messages.active')</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($templates as $template)
                        <tr class="border-t border-line-soft" wire:key="tpl-{{ $template->id }}">
                            <td class="px-4 py-2 font-semibold text-ink"><bdi>{{ $template->name }}</bdi></td>
                            <td class="px-4 py-2">{{ $template->channel === 'email' ? __('messages.email') : 'WhatsApp' }}</td>
                            <td class="px-4 py-2">{{ $template->language === 'ar' ? 'العربية' : 'English' }}</td>
                            <td class="px-4 py-2">
                                <button type="button" wire:click="toggleActive({{ $template->id }})"
                                        class="underline text-small text-ink-2 hover:text-ink">
                                    {{ $template->is_active ? __('messages.on') : __('messages.off') }}
                                </button>
                            </td>
                            <td class="px-4 py-2 text-end whitespace-nowrap">
                                <button type="button" wire:click="edit({{ $template->id }})" class="px-2 underline text-small text-ink hover:text-primary">@lang('general.edit')</button>
                                <button type="button" wire:click="delete({{ $template->id }})"
                                        wire:confirm="{{ __('messages.delete-confirm') }}"
                                        class="px-2 underline text-small text-ink-2 hover:text-primary">@lang('general.delete')</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
