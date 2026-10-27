<div>
    <livewire:layout.breadcrumbs :title="__('general.add-new')" :crumbs="[
        ['label' => trans_choice('general.employee', 2), 'url' => route('employees.index')],
        ['label' => __('general.add-new')],
    ]" />


    <x-tab.form activeTab="information">
        <x-tab.list>
            <x-tab.item :title="__('general.information')" href="{{route('employees.create.personal-info')}}"
                        :active="request()->routeIs('employees.create') || request()->routeIs('employees.create.personal-info')"
                        wire:key="tabitem-information">
                <x-icons.user/>

            </x-tab.item>
            <x-tab.item :title="__('general.education')" activeTab="education" wire:key="tabitem-education">
                <svg class="w-5 h-5 me-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                          clip-rule="evenodd"></path>
                </svg>
            </x-tab.item>
            <x-tab.item :title="__('general.courses')" activeTab="courses" wire:key="tabitem-courses">
                <svg class="w-5 h-5 me-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                          clip-rule="evenodd"></path>
                </svg>
            </x-tab.item>
            <x-tab.item :title="__('general.experience')" activeTab="experience" wire:key="tabitem-experience">
                <svg class="w-5 h-5 me-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                          clip-rule="evenodd"></path>
                </svg>
            </x-tab.item>
            <x-tab.item :title="__('general.relatives')" activeTab="relatives" wire:key="tabitem-relatives">
                <svg class="w-5 h-5 me-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                          clip-rule="evenodd"></path>
                </svg>
            </x-tab.item>
            <x-tab.item :title="__('general.badges')" activeTab="badges" wire:key="tabitem-badges">
                <svg class="w-5 h-5 me-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                          clip-rule="evenodd"></path>
                </svg>
            </x-tab.item>
            <x-tab.item :title="__('general.checklist')" activeTab="checklist" wire:key="tabitem-checklist">
                <svg class="w-5 h-5 me-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"
                          clip-rule="evenodd"></path>
                </svg>
            </x-tab.item>
        </x-tab.list>

        <div class="flex-grow w-full">
            <x-tab.container active-tab="information" wire:key="tab">
                {{$slot}}
            </x-tab.container>


        </div>
    </x-tab.form>
</div>
