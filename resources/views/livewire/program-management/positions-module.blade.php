<div>
    <div class="flex items-center justify-between mb-6">
        <livewire:layout.breadcrumbs
            :title="__('general.positions')"
            :crumbs="[
        ['label' => __('general.centers'), 'url' => route('centers.index')],
        ['label' => (string) $center->name . ' (' . $center->city->name . ')'],
            ]"
            :showButton="true"
        />
        @activerole('HR' , 'super-admin' ,'general-manager')
        <div class="flex items-center gap-2">
            <x-primary-button wire:click="create">
                @lang('general.add-new') 
            </x-primary-button>
        </div>
        @endactiverole
    </div>

    <x-table>
        <x-slot name="headings">
            <x-table.heading wire:click="sortBy('id')" sortable
                             :direction="$sortField === 'id' ? $sortDirection : null">
                ID
            </x-table.heading>

            <x-table.heading>{{ trans_choice('general.required-positions', 2) }}</x-table.heading>
            <x-table.heading>@lang('general.center-name')</x-table.heading>
            <x-table.heading>@lang('general.responsibilities')</x-table.heading>

            <x-table.heading wire:click="sortBy('max_emp')" sortable
                             :direction="$sortField === 'max_emp' ? $sortDirection : null">
                @lang('general.max')
            </x-table.heading>

            <x-table.heading wire:click="sortBy('exist_emp')" sortable
                             :direction="$sortField === 'exist_emp' ? $sortDirection : null">
                @lang('general.existing')
            </x-table.heading>

            <x-table.heading wire:click="sortBy('is_active')" sortable
                             :direction="$sortField === 'is_active' ? $sortDirection : null">
                @lang('general.is-active')
            </x-table.heading>

            <x-table.heading/>
        </x-slot>


        <x-slot name="body">
            @forelse($rows as $row)
                <tr wire:key="pos-{{ $row->id }}">
                    {{-- ID --}}
                    <x-table.cell class="whitespace-nowrap">
                        {{ $row->id }}
                    </x-table.cell>

                    {{-- Required Position name --}}
                    <x-table.cell class="truncate max-w-64" title="{{ $row->requiredPosition->title ?? '-' }}">
                        {{ $row->requiredPosition->title ?? '-' }}
                    </x-table.cell>

                    {{-- Center name --}}
                    <x-table.cell class="truncate max-w-64" title="{{ optional($row->center)->name }}">
                        {{ optional($row->center)->name ?? '-' }}
                    </x-table.cell>

                    {{-- Responsibilities --}}
                    <x-table.cell class="max-w-96 line-clamp-1" title="{{ $row->responsibilities }}">
                        {{ $row->responsibilities ?? '-' }}
                    </x-table.cell>

                    <x-table.cell>{{ $row->max_emp }}</x-table.cell>
                    <x-table.cell>{{ $row->exist_emp }}</x-table.cell>

                    <x-table.cell-status
                        :active="$row->is_active"
                        :model-id="$row->id"
                        wire-event="confirmToggleItemStatus"
                    />

                    {{-- Actions --}}
                    <x-table.cell class="whitespace-nowrap">
                        <x-table.edit-btn wire:click="edit({{ $row->id }})" />
                        <x-table.delete-btn :modelId="$row->id" />
                    </x-table.cell>
                </tr>
            @empty
                <x-table.no-data />
            @endforelse
        </x-slot>


        <x-slot name="pagination">
            {{ $rows->onEachSide(1)->links() }}
        </x-slot>
    </x-table>

    {{-- Create/Edit Modal --}}
    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" wire:ignore.self>
        <form wire:submit.prevent="save" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <x-forms.input-label for="required_position_id">{{trans_choice('general.required-positions' , 2)}}</x-forms.input-label>
                    <x-forms.select-input id="required_position_id" wire:model="item.required_position_id">
                        <option value="">{{ __('general.select') }}</option>
                        @foreach($availableRequired as $rp)
                            <option value="{{ $rp->id }}">{{ $rp->title }}</option>
                        @endforeach
                    </x-forms.select-input>
                    <x-error-span>{{ $errors->first('item.required_position_id') }}</x-error-span>
                </div>

                <div class="col-span-2">
                    <x-forms.input-label for="responsibilities" required>@lang('general.responsibilities')</x-forms.input-label>
                    <x-forms.textarea id="responsibilities" rows="3" wire:model.defer="item.responsibilities" />
                    <x-error-span>{{ $errors->first('item.responsibilities') }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="max_emp" required>@lang('general.max')</x-forms.input-label>
                    <x-forms.input id="max_emp" type="number" min="0" wire:model.defer="item.max_emp" />
                    <x-error-span>{{ $errors->first('item.max_emp') }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="exist_emp">@lang('general.existing')</x-forms.input-label>
                    <x-forms.input id="exist_emp" type="number" min="0" wire:model.defer="item.exist_emp" />
                    <x-error-span>{{ $errors->first('item.exist_emp') }}</x-error-span>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
                <x-modal.cancel-btn />
            </div>
        </form>
    </x-modal.dialog>
</div>
