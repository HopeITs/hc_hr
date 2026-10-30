<x-slot:title>
    {{ $title ?? __('general.add-new') }}
</x-slot:title>

<div>
    <livewire:layout.breadcrumbs :title="$title ?? __('general.add-new')" :crumbs="[
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
    @php
        // Which tab holds each field, so a summary entry can open it (B2).
        $errorTabs = [
            'infoForm.' => 'information',
            'educationForm.' => 'education',
            'courseForm.' => 'courses',
            'experienceForm.' => 'experience',
            'relativeForm.' => 'relatives',
            'badgeForm.' => 'badges',
            'checklistForm.' => 'checklist',
            'attachmentForm.' => 'attachments',
        ];

        foreach (\App\Livewire\Forms\Employee\PersonalInfoForm::CONTACT_FIELDS as $contactField) {
            $errorTabs['infoForm.'.$contactField] = 'contact';
        }
    @endphp
    <x-forms.errors-validation-list :sections="$errorTabs" reveal="setActiveTab" />

    @include('livewire.employee.partials.onboarding-status')

    @unless ($isCreateMode)
        <livewire:employee.onboarding-journey-panel :employee="$employee" :key="'journey-'.$employee->id" />
        <livewire:employee.offboarding-journey-panel :employee="$employee" :key="'exit-journey-'.$employee->id" />
    @endunless

    @php
        // One definition feeds the desktop list and the phone selector, so the
        // two can never disagree about which sections exist or are unlocked.
        $hasEmployee = isset($infoForm->id);
        $sections = array_values(array_filter([
            ['key' => 'information', 'title' => __('general.information'), 'icon' => 'user', 'locked' => false],
            ['key' => 'contact', 'title' => __('general.contact'), 'icon' => 'contact', 'locked' => false],
            ['key' => 'education', 'title' => __('general.education'), 'icon' => 'education', 'locked' => ! $hasEmployee],
            ['key' => 'courses', 'title' => __('general.courses'), 'icon' => 'courses', 'locked' => ! $hasEmployee],
            ['key' => 'experience', 'title' => __('general.experience'), 'icon' => 'experience', 'locked' => ! $hasEmployee],
            ['key' => 'relatives', 'title' => __('general.relatives'), 'icon' => 'relatives', 'locked' => ! $hasEmployee],
            $canManageBadges ? ['key' => 'badges', 'title' => __('general.badges'), 'icon' => 'badges', 'locked' => ! $hasEmployee] : null,
            ['key' => 'checklist', 'title' => __('general.checklist'), 'icon' => 'checklist', 'locked' => ! $hasEmployee],
            ['key' => 'attachments', 'title' => __('general.attachments'), 'icon' => 'attachment', 'locked' => ! $hasEmployee],
            ['key' => 'items', 'title' => __('general.items'), 'icon' => 'items', 'locked' => ! $hasEmployee],
            $canViewContractHistory ? ['key' => 'contracts_history', 'title' => __('general.contracts-history'), 'icon' => 'contracts', 'locked' => ! $hasEmployee] : null,
        ]));
        $currentIndex = collect($sections)->search(fn ($section) => $section['key'] === $activeTab);
        $currentIndex = $currentIndex === false ? 0 : $currentIndex;
        $digits = fn ($n) => app()->getLocale() === 'ar' ? ar_digits($n) : (string) $n;
    @endphp

    <x-tab.form activeTab="information">
        {{--
            Phones: one labelled selector replaces the stack of eleven
            full-width buttons that pushed the form a screen and a half down.
            Sections that need a saved employee stay listed but unavailable,
            exactly as the desktop list shows them.
        --}}
        <div class="lg:hidden" data-employee-section-nav>
            <label for="employee-section-select" class="mb-1 flex items-baseline justify-between text-small font-semibold text-ink-2">
                <span>@lang('mobile.section')</span>
                <span class="font-normal" aria-live="polite">{{ $digits($currentIndex + 1) }} / {{ $digits(count($sections)) }}</span>
            </label>
            <select id="employee-section-select"
                class="min-h-11 w-full rounded-control border border-line bg-card px-3 text-body font-semibold text-ink shadow-e1"
                x-on:change="$wire.setActiveTab($event.target.value)">
                @foreach ($sections as $section)
                    <option value="{{ $section['key'] }}" @selected($section['key'] === $activeTab) @disabled($section['locked'])>{{ $section['title'] }}</option>
                @endforeach
            </select>
            @unless ($hasEmployee)
                <p class="mt-1 text-small text-ink-2">@lang('mobile.sections-unlock-after-save')</p>
            @endunless
        </div>

        <x-tab.list data-tour="employee.tabs" class="hidden lg:block">
            @foreach ($sections as $section)
                <x-tab.item :title="$section['title']" :activeTab="$section['key']" :disabled="$section['locked']" :active="$activeTab === $section['key']">
                    <x-dynamic-component :component="'icons.'.$section['icon']" />
                </x-tab.item>
            @endforeach
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
