{{-- The add/edit holiday dialog: the holidays page and, embedded, the team calendar. --}}
<x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>
    <form class="space-y-4" wire:submit.prevent="save">
        <div class="grid grid-cols-1 gap-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-forms.input-label for="holiday_name_en" required>@lang('general.name') (EN)</x-forms.input-label>
                    <x-forms.input type="text" wire:model="item.name_en" id="holiday_name_en" />
                    <x-error-span>{{ $errors->first('item.name_en') }}</x-error-span>
                </div>
                <div>
                    <x-forms.input-label for="holiday_name_ar" required>@lang('general.name') (AR)</x-forms.input-label>
                    <x-forms.input type="text" wire:model="item.name_ar" id="holiday_name_ar" dir="rtl" />
                    <x-error-span>{{ $errors->first('item.name_ar') }}</x-error-span>
                </div>
            </div>

            <div>
                <x-forms.input-label for="holiday_kind" required>@lang('holidays.kind')</x-forms.input-label>
                <select id="holiday_kind" wire:model.live="item.kind"
                    class="w-full rounded-control border border-line bg-card px-3 py-2 text-body shadow-e1">
                    <option value="official">@lang('holidays.kinds.official')</option>
                    <option value="grant">@lang('holidays.kinds.grant')</option>
                    @if ($isNationalHr)
                        <option value="birthday">@lang('holidays.kinds.birthday')</option>
                    @endif
                </select>
                <p class="mt-1 text-small text-ink-2">@lang('holidays.kind-hints.'.($item['kind'] ?? 'official'))</p>
                <x-error-span>{{ $errors->first('item.kind') }}</x-error-span>
            </div>

            @if ($isNationalHr)
                <div>
                    <x-forms.input-label required>@lang('holidays.applies-to')</x-forms.input-label>
                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2">
                            <input type="radio" wire:model.live="item.scope" value="national" class="text-ink">
                            @lang('holidays.national')
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" wire:model.live="item.scope" value="projects" class="text-ink">
                            @lang('holidays.selected-projects')
                        </label>
                    </div>
                    <x-error-span>{{ $errors->first('item.scope') }}</x-error-span>
                </div>
            @endif

            @if (($item['scope'] ?? null) === 'projects')
                <div>
                    <x-forms.input-label required>@lang('holidays.projects')</x-forms.input-label>

                    @if (count($governorates) > 1)
                        <div class="mb-2 flex gap-2">
                            <select wire:model="governorate" aria-label="@lang('holidays.governorate')"
                                class="flex-1 rounded-control border border-line bg-card px-3 py-2 text-body shadow-e1">
                                <option value="">@lang('holidays.governorate')</option>
                                @foreach ($governorates as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <x-secondary-button type="button" wire:click="selectGovernorate">@lang('holidays.select-governorate')</x-secondary-button>
                        </div>
                    @endif

                    <div class="max-h-48 space-y-1 overflow-y-auto rounded-control border border-line-soft p-2">
                        @forelse ($projectOptions as $key => $label)
                            <label class="flex items-center gap-2 text-small" wire:key="project-{{ $key }}">
                                <input type="checkbox" wire:model="item.projects" value="{{ $key }}" class="rounded-inset text-ink">
                                {{ $label }}
                            </label>
                        @empty
                            <p class="text-small text-ink-2">@lang('holidays.no-projects-to-pick')</p>
                        @endforelse
                    </div>
                    <x-error-span>{{ $errors->first('item.projects') ?: $errors->first('item.projects.*') }}</x-error-span>
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-forms.input-label for="holiday_start" required>@lang(($item['kind'] ?? '') === 'birthday' ? 'holidays.valid-from' : 'holidays.from')</x-forms.input-label>
                    <x-forms.date-input wire:model="item.start_date" id="holiday_start" />
                    <x-error-span>{{ $errors->first('item.start_date') }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="holiday_end" required>@lang(($item['kind'] ?? '') === 'birthday' ? 'holidays.valid-to' : 'holidays.to')</x-forms.input-label>
                    <x-forms.date-input wire:model="item.end_date" id="holiday_end" />
                    <x-error-span>{{ $errors->first('item.end_date') }}</x-error-span>
                </div>
            </div>

            @if (($item['kind'] ?? null) !== 'grant')
                <label class="flex items-center gap-2">
                    <x-forms.toggle-input wire:model="item.is_recurring" :active="$item['is_recurring'] ?? false" />
                    <span>
                        @lang('holidays.recurring')
                        <span class="block text-small text-ink-2">@lang('holidays.recurring-hint')</span>
                    </span>
                </label>
            @endif
        </div>

        <div class="flex justify-end space-x-2">
            <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
            <x-modal.cancel-btn />
        </div>
    </form>
</x-modal.dialog>
