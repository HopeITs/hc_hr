<x-slot:title>
    {{ $title ?? __('general.add-new') }}
</x-slot:title>

<div>
    <livewire:layout.breadcrumbs :title="$title ?? __('general.add-new')" :crumbs="[
        ['label' => trans_choice('general.employee-management', 2)],
        ['label' => trans_choice('general.employee', 2), 'url' => route('employees.index')],
        ['label' => $title ?? __('general.add-new')],
    ]" />

    @unless ($isCreateMode)
        {{--
            The only exit from the edit form. Every tab's save now stays put,
            so this button is the single way back to the employees table.
        --}}
        <div class="flex justify-end mb-4">
            <x-secondary-button :href="route('employees.index')" wire:navigate class="!w-auto" data-tour="employee.back">
                @lang('general.back_to_employees')
            </x-secondary-button>
        </div>
    @endunless

    {{--
        Rendered outside the tab container on purpose: only the active tab is
        rendered below, so an error list living inside a tab is invisible
        whenever the offending field belongs to a different tab.
    --}}
    <x-forms.errors-validation-list />

    <x-tab.form activeTab="information">
        <x-tab.list data-tour="employee.tabs">
            <x-tab.item :title="__('general.information')" activeTab="information" :active="$activeTab === 'information'">
                <x-icons.user />
            </x-tab.item>
            <x-tab.item :title="__('general.contact')" activeTab="contact" :active="$activeTab === 'contact'">
                <x-icons.contact/>
            </x-tab.item>
            <x-tab.item :title="__('general.education')" :disabled="!isset($infoForm->id)" activeTab="education" :active="$activeTab === 'education'">
                <x-icons.education />
            </x-tab.item>
            <x-tab.item :title="__('general.courses')" activeTab="courses" :disabled="!isset($infoForm->id)" :active="$activeTab === 'courses'">
                <x-icons.courses />
            </x-tab.item>
            <x-tab.item :title="__('general.experience')" activeTab="experience" :disabled="!isset($infoForm->id)" :active="$activeTab === 'experience'">
                <x-icons.experience />
            </x-tab.item>
            <x-tab.item :title="__('general.relatives')" activeTab="relatives" :disabled="!isset($infoForm->id)" :active="$activeTab === 'relatives'">
                <x-icons.relatives />
            </x-tab.item>
            @if($canManageBadges)
                <x-tab.item :title="__('general.badges')" activeTab="badges" :disabled="!isset($infoForm->id)" :active="$activeTab === 'badges'">
                    <x-icons.badges />
                </x-tab.item>
            @endif
            <x-tab.item :title="__('general.checklist')" activeTab="checklist" :disabled="!isset($infoForm->id)" :active="$activeTab === 'checklist'">
                <x-icons.checklist  />
            </x-tab.item>
            <x-tab.item :title="__('general.attachments')" activeTab="attachments" :disabled="!isset($infoForm->id)" :active="$activeTab === 'attachments'">
                <x-icons.attachment />
            </x-tab.item>
            <x-tab.item :title="__('general.items')" activeTab="items" :disabled="!isset($infoForm->id)" :active="$activeTab === 'items'">
                <x-icons.items />
            </x-tab.item>
            @if ($canViewContractHistory)
                <x-tab.item :title="__('general.contracts-history')" activeTab="contracts_history" :disabled="!isset($infoForm->id)" :active="$activeTab === 'contracts_history'">
                    <x-icons.contracts />
                </x-tab.item>
            @endif
        </x-tab.list>

        <div class="flex-grow w-full">
            <x-tab.container activeTab="information">
                @if ($activeTab === 'information')
                    @include('livewire.employee.tabs.personal-info-tab')
                @elseif($activeTab === 'contact')
                    @include('livewire.employee.tabs.contact-info-fields')
                @elseif($activeTab === 'education')
                    @include('livewire.employee.tabs.education-fields')
                @elseif($activeTab === 'courses')
                    @include('livewire.employee.tabs.courses-fields')
                @elseif($activeTab === 'experience')
                    @include('livewire.employee.tabs.experience-fields')
                @elseif($activeTab === 'relatives')
                    @include('livewire.employee.tabs.relatives-fields')
                @elseif($activeTab === 'badges' && $canManageBadges)
                    @include('livewire.employee.tabs.badges-fields')
                @elseif($activeTab === 'checklist')
                    @include('livewire.employee.tabs.checklist-fields')
                @elseif($activeTab === 'attachments')
                    @include('livewire.employee.tabs.attachments-fields')
                @elseif($activeTab === 'items')
                    @include('livewire.employee.tabs.items-fields')
                @elseif($activeTab === 'contracts_history' && $canViewContractHistory)
                    @include('livewire.employee.tabs.contracts-history')
                @else
                    @include('livewire.employee.tabs.personal-info-tab')
                @endif
            </x-tab.container>
        </div>
    </x-tab.form>
</div>
