<div class="relative" x-data="{
    rtl: {{ app()->getLocale() === 'ar' ? 'true' : 'false' }},
    open: @entangle('open').live
}" x-cloak>
    <aside x-cloak data-tour="nav" @click.outside="open = false"
        class="sidebar-material fixed top-0 z-40 h-full pt-4 border-e border-black/[0.06] shadow-e3 transition-[width,transform] duration-[400ms] ease-fluid md:translate-x-0"
        :class="[
            sidebarCollapsed ? 'md:w-20' : 'md:w-64',
            open ? 'w-64 translate-x-0' : 'w-64',
            !open && !rtl ? '-translate-x-full' : '',
            !open && rtl ? 'translate-x-full' : '',
        ]"
    >
        <button type="button"
                x-on:click="$dispatch('sidebar-collapse-toggle')"
                class="nav-press absolute z-50 items-center justify-center hidden bg-white border border-neutral-200 rounded-full shadow-e2 top-7 size-8 text-secondary-medium hover:border-secondary-light hover:bg-secondary-soft hover:text-primary md:flex"
                :class="rtl ? '-left-4' : '-right-4'"
                :title="sidebarCollapsed ? @js(__('general.expand-sidebar')) : @js(__('general.collapse-sidebar'))">
            <svg class="transition-transform duration-[400ms] ease-fluid size-4"
                 :class="sidebarCollapsed ? (rtl ? 'rotate-0' : 'rotate-180') : (rtl ? 'rotate-180' : 'rotate-0')"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"/>
            </svg>
        </button>

        <div class="flex flex-col h-full">
            {{-- Fixed header: logo stays put; scrolling begins below the divider --}}
            <a href="{{ route('dashboard') }}" class="flex items-center justify-start ps-6 h-16 shrink-0 border-b border-black/[0.06]">
                <x-app-logo class="w-[8.25rem] h-auto object-fit" x-show="!sidebarCollapsed || open" />
                <img src="{{ asset('assets/img/hope-center-icon.svg') }}"
                     alt="{{ config('app.name') }}"
                     x-show="sidebarCollapsed && !open"
                     class="hidden object-contain size-8 md:block" />
            </a>

            {{-- Scrollable navigation area (starts directly below the header) --}}
            <div class="flex-1 min-h-0 px-3 pt-3 pb-4 overflow-x-hidden overflow-y-auto">
                <ul class="space-y-1 font-medium" :class="sidebarCollapsed && !open ? 'md:flex md:flex-col md:items-center' : ''">

                    {{-- Group: Main --}}
                    <li x-show="!sidebarCollapsed || open" aria-hidden="true" class="px-3 py-[3px]">
                        <span class="text-caption font-semibold uppercase tracking-wider text-secondary-light">{{ app()->getLocale() === 'ar' ? 'الرئيسية' : 'Main' }}</span>
                    </li>

                    {{-- 1. Employee Dashboard (Seen by all) --}}
                    <x-layouts.app.sidebar.single-item data-tour="nav.dashboard" :href="route('dashboard')" :active="request()->routeIs('dashboard') || request()->routeIs('employee.dashboard')">
                        <x-slot name="icon">
                            <x-icons.dashboard class="size-4" />
                        </x-slot>
                        @lang('general.dashboard')
                    </x-layouts.app.sidebar.single-item>

                    {{-- Statistics (HR + super-admin only - kept as per logic) --}}
                    @activepermission('statistics.view')
                        <x-layouts.app.sidebar.dropdown-nav-list data-tour="nav.statistics" id="statistics" title="{{ __('general.statistics') }}"
                            :active="request()->routeIs('statistics.general') ||
                                request()->routeIs('statistics.contract')">
                            <x-slot name="icon">
                                <x-icons.statistics />
                            </x-slot>
                            <x-slot name="list">

                                <x-layouts.app.sidebar.nav-item :href="route('statistics.general')" :active="request()->routeIs('statistics.general')">
                                    {{ __('general.general-statistics') }}
                                </x-layouts.app.sidebar.nav-item>

                                <x-layouts.app.sidebar.nav-item :href="route('statistics.contract')" :active="request()->routeIs('statistics.contract')">
                                    {{ __('general.contract-statistics') }}
                                </x-layouts.app.sidebar.nav-item>
                            </x-slot>
                        </x-layouts.app.sidebar.dropdown-nav-list>
                    @endactivepermission

                    @activepermission('positions.view')
                        <x-layouts.app.sidebar.single-item data-tour="nav.org-chart" :href="route('org.chart')" :active="request()->routeIs('org.chart')">
                            <x-slot name="icon">
                                <x-icons.code-fork class="size-5" />
                            </x-slot>
                            {{ __('general.orgn-chart') }}
                        </x-layouts.app.sidebar.single-item>
                    @endactivepermission

                    {{-- Group: Management --}}
                    <li x-show="!sidebarCollapsed || open" aria-hidden="true" class="px-3 py-[3px]">
                        <span class="text-caption font-semibold uppercase tracking-wider text-secondary-light">{{ app()->getLocale() === 'ar' ? 'الإدارة' : 'Management' }}</span>
                    </li>

                    {{-- 2. Project Basics --}}
                    @activepermission('project-basics.view')
                    <x-layouts.app.sidebar.dropdown-nav-list data-tour="nav.project-basics" id="basic-program" :title="__('general.project-basics')" :active="request()->routeIs('cities.*') ||
                        request()->routeIs('contract-types.*') ||
                        request()->routeIs('leave-types.*') ||
                        request()->routeIs('annual-leave-rules.*') ||
                        request()->routeIs('basic-checklist-items.*') ||
                        request()->routeIs('departments.*') ||
                        request()->routeIs('course-categories.*') ||
                        request()->routeIs('job-levels.*')  ||   request()->routeIs('termination-reasons.*')  "
                        >
                        <x-slot name="icon">
                            <x-icons.home />
                        </x-slot>
                        <x-slot name="list">
                            {{-- Cities (All) --}}
                            <x-layouts.app.sidebar.nav-item :href="route('cities.index')" :active="request()->routeIs('cities.index')">
                                {{ trans_choice('general.city', 2) }}
                            </x-layouts.app.sidebar.nav-item>
                            <x-layouts.app.sidebar.nav-item :href="route('contract-types.index')" :active="request()->routeIs('contract-types.index')">
                                {{ trans_choice('general.contract-type', 2) }}
                            </x-layouts.app.sidebar.nav-item>

                            {{-- Leave Types (All) --}}
                            <x-layouts.app.sidebar.nav-item :href="route('leave-types.index')" :active="request()->routeIs('leave-types.index')">
                                {{ trans_choice('general.leave-type', 2) }}
                            </x-layouts.app.sidebar.nav-item>
                            <x-layouts.app.sidebar.nav-item :href="route('annual-leave-rules.index')" :active="request()->routeIs('annual-leave-rules.*')" wire:navigate>
                                {{ __('general.annual_leave_rules') }}
                            </x-layouts.app.sidebar.nav-item>
                            <x-layouts.app.sidebar.nav-item :href="route('termination-reasons.index')" :active="request()->routeIs('termination-reasons.*')" wire:navigate>
                                {{ __('general.termination_reasons') }}
                            </x-layouts.app.sidebar.nav-item>

                            {{-- Checklist items (All) --}}
                            <x-layouts.app.sidebar.nav-item :href="route('basic-checklist-items.index')" :active="request()->routeIs('basic-checklist-items.index')">
                                {{ trans_choice('general.checklist-items', 2) }}
                            </x-layouts.app.sidebar.nav-item>
                            <x-layouts.app.sidebar.nav-item :href="route('job-levels.index')" :active="request()->routeIs('job-levels.index')">
                                {{ trans_choice('general.job-level', 2) }}
                            </x-layouts.app.sidebar.nav-item>

                            {{-- Departments (All) --}}
                            <x-layouts.app.sidebar.nav-item :href="route('departments.index')" :active="request()->routeIs('departments.index')" wire:navigate>
                                {{ trans_choice('general.department', 2) }}
                            </x-layouts.app.sidebar.nav-item>
                            <x-layouts.app.sidebar.nav-item :href="route('course-categories.index')" :active="request()->routeIs('course-categories.index')" wire:navigate>
                                {{ trans_choice('general.course-category', 2) }}
                            </x-layouts.app.sidebar.nav-item>
                        </x-slot>
                    </x-layouts.app.sidebar.dropdown-nav-list>
                    @endactivepermission


                    {{-- 3. Program Management --}}
                    {{-- Dropdown visible to HR & super-admin --}}
                    @if ($user->hasActivePermission('programs.view') || $user->hasActivePermission('positions.view'))
                        <x-layouts.app.sidebar.dropdown-nav-list data-tour="nav.programs" id="program-management" :title="__('general.programs-management')"
                            :active="request()->routeIs('programs.*') ||
                                request()->routeIs('positions.*') ||
                                request()->routeIs('centers.*')">

                            <x-slot name="icon">
                                <x-icons.layers />
                            </x-slot>

                            <x-slot name="list">
                                @activepermission('programs.view')
                                <x-layouts.app.sidebar.nav-item :href="route('programs.index')" :active="request()->routeIs('programs.*')">
                                    {{ trans_choice('general.program', 2) }}
                                </x-layouts.app.sidebar.nav-item>
                                @endactivepermission
                                @activepermission('positions.view')
                                    <x-layouts.app.sidebar.nav-item :href="route('positions.index')" :active="request()->routeIs('positions.*')">
                                        {{ __('general.positions') }}
                                    </x-layouts.app.sidebar.nav-item>
                                @endactivepermission

                                @activepermission('centers.view')
                                <x-layouts.app.sidebar.nav-item :href="route('centers.index')" :active="request()->routeIs('centers.*')" wire:navigate>
                                    {{ trans_choice('general.center', 2) }}
                                </x-layouts.app.sidebar.nav-item>
                                @endactivepermission
                            </x-slot>
                        </x-layouts.app.sidebar.dropdown-nav-list>
                    @endif

                    {{-- Centers for everyone else (Single item) --}}
                    @if ($user->hasActivePermission('centers.view') && ! $user->hasActivePermission('programs.view') && ! $user->hasActivePermission('positions.view'))
                        <x-layouts.app.sidebar.single-item data-tour="nav.centers" :href="route('centers.index')" :active="request()->routeIs('centers.*')">
                            <x-slot name="icon">
                                <x-icons.layers class="size-5" />
                            </x-slot>
                            {{ trans_choice('general.center', 2) }}
                        </x-layouts.app.sidebar.single-item>
                    @endif

                    {{-- 4. Employee Management (super-admin + HR) --}}
                    @if ($user->hasActivePermission('employees.view') || $user->hasActivePermission('contracts.view') || $user->hasActivePermission('checklists.manage'))
                        <x-layouts.app.sidebar.dropdown-nav-list data-tour="nav.employees" id="employees-management" :title="trans_choice('general.employee-management', 2)"
                            :active="request()->routeIs('employees.*') ||
                                request()->routeIs('contracts.*') ||
                                request()->routeIs('default-deliverables.*')">
                            <x-slot name="icon">
                                <x-icons.users />
                            </x-slot>
                            <x-slot name="list">
                                @activepermission('employees.view')
                                <x-layouts.app.sidebar.nav-item :href="route('employees.index')" :active="request()->routeIs('employees.*')">
                                    {{ trans_choice('general.employee', 2) }}
                                </x-layouts.app.sidebar.nav-item>
                                @endactivepermission

                                @activepermission('checklists.manage')
                                <x-layouts.app.sidebar.nav-item :href="route('default-deliverables.index')" :active="request()->routeIs('default-deliverables.index')">
                                    {{ trans_choice('general.default-deliverables', 2) }}
                                </x-layouts.app.sidebar.nav-item>
                                @endactivepermission

                                @activepermission('contracts.view')
                                <x-layouts.app.sidebar.nav-item :href="route('contracts.index')" :active="request()->routeIs('contracts.*')">
                                    {{ trans_choice('general.contracts', 2) }}
                                </x-layouts.app.sidebar.nav-item>
                                @endactivepermission
                            </x-slot>
                        </x-layouts.app.sidebar.dropdown-nav-list>
                    @endif

                    {{-- Group: Time & Leave --}}
                    <li x-show="!sidebarCollapsed || open" aria-hidden="true" class="px-3 py-[3px]">
                        <span class="text-caption font-semibold uppercase tracking-wider text-secondary-light">{{ app()->getLocale() === 'ar' ? 'الإجازات' : 'Time & Leave' }}</span>
                    </li>

                    {{-- 5. Leave Management (Holidays and leaves) --}}
                    {{-- Visible to: HR, super-admin, AND Managers --}}
                    @if ($user->hasActivePermission('leaves.view-all') || $user->hasActivePermission('leaves.manage-balances'))
                        <x-layouts.app.sidebar.dropdown-nav-list data-tour="nav.leaves-management" id="leaves-management"
                            title="{{ __('general.leaves-management') }}" :active="request()->routeIs('leaves.all') || request()->routeIs('leaves.balance')">
                            <x-slot name="icon">
                                <x-icons.calendar />
                            </x-slot>
                            <x-slot name="list">
                                {{-- All Leaves: Managers, HRs, super-admin --}}
                                @activepermission('leaves.view-all')
                                <x-layouts.app.sidebar.nav-item :href="route('leaves.all')" :active="request()->routeIs('leaves.all')">
                                    {{ __('general.all-leaves') }}
                                </x-layouts.app.sidebar.nav-item>
                                @endactivepermission

                                {{-- Balance: ONLY HR + super-admin (Managers excluded) --}}
                                @activepermission('leaves.manage-balances')
                                    <x-layouts.app.sidebar.nav-item :href="route('leaves.balance')" :active="request()->routeIs('leaves.balance')">
                                        {{ __('general.balance') }}
                                    </x-layouts.app.sidebar.nav-item>
                                @endactivepermission
                            </x-slot>
                        </x-layouts.app.sidebar.dropdown-nav-list>
                    @endif

                    {{-- My Leaves: Single Item OUTSIDE for ALL --}}
                    @activepermission('leaves.view-own')
                    <x-layouts.app.sidebar.single-item data-tour="nav.my-leaves" :href="route('leaves.index')" :active="request()->routeIs('leaves.index')">
                        <x-slot name="icon">
                            <x-icons.my-leaves />

                        </x-slot>
                        {{ __('general.my-leaves') }}
                    </x-layouts.app.sidebar.single-item>
                    @endactivepermission

                    {{-- Group: Files & Tasks --}}
                    <li x-show="!sidebarCollapsed || open" aria-hidden="true" class="px-3 py-[3px]">
                        <span class="text-caption font-semibold uppercase tracking-wider text-secondary-light">{{ app()->getLocale() === 'ar' ? 'الملفات والمهام' : 'Files & Tasks' }}</span>
                    </li>

                    {{-- 6. Archived Files (All) --}}
                    @activepermission('archives.view')
                    <x-layouts.app.sidebar.dropdown-nav-list data-tour="nav.archives" id="archived-files" :title="__('general.archived-files')" :active="request()->routeIs('my-folders.*') || request()->routeIs('shared-folders.*')">
                        <x-slot name="icon">
                            <x-icons.archived />
                        </x-slot>
                        <x-slot name="list">


                            <x-layouts.app.sidebar.nav-item :href="route('my-folders.index')" :active="request()->routeIs('my-folders.*')">
                                {{ __('general.my-folders') }}
                            </x-layouts.app.sidebar.nav-item>


                            <x-layouts.app.sidebar.nav-item :href="route('shared-folders.index')" :active="request()->routeIs('shared-folders.*')">
                                {{ __('general.shared-folders') }}
                            </x-layouts.app.sidebar.nav-item>
                        </x-slot>
                    </x-layouts.app.sidebar.dropdown-nav-list>
                    @endactivepermission


                    @if ($isDeliverResponsible && ($user->hasActivePermission('checklists.deliver') || $user->hasActivePermission('checklists.receive') || $user->hasActivePermission('checklists.manage')))
                        <x-layouts.app.sidebar.dropdown-nav-list data-tour="nav.checklists" id="checklist-items" :title="__('general.checklist_items')"
                            :active="request()->routeIs('checklist-items.*')">
                            <x-slot name="icon">
                                <x-icons.clipboard-list class="size-4" />
                            </x-slot>
                            <x-slot name="list">

                                @activepermission('checklists.deliver')
                                <x-layouts.app.sidebar.nav-item :href="route('checklist-items.pending-delivers')" :active="request()->routeIs('checklist-items.pending-delivers')">
                                    {{ __('general.pending-delivers') }}
                                    @livewire('checklist-items.pending-delivers-counter')
                                </x-layouts.app.sidebar.nav-item>
                                @endactivepermission

                                @activepermission('checklists.receive')
                                <x-layouts.app.sidebar.nav-item :href="route('checklist-items.pending-returns')" :active="request()->routeIs('checklist-items.pending-returns')">
                                    {{ __('general.pending_returns') }}
                                    @livewire('checklist-items.pending-returns-counter')
                                </x-layouts.app.sidebar.nav-item>
                                @endactivepermission

                                @activepermission('checklists.manage')
                                <x-layouts.app.sidebar.nav-item :href="route('checklist-items.done-requests')" :active="request()->routeIs('checklist-items.done-requests')">
                                    {{ __('general.done_requests') }}
                                </x-layouts.app.sidebar.nav-item>
                                @endactivepermission


                            </x-slot>
                        </x-layouts.app.sidebar.dropdown-nav-list>
                    @endif



                    {{-- 7. Checklist Items -> My items (All, Single) --}}
                    @activepermission('checklists.view-own')
                    <x-layouts.app.sidebar.single-item data-tour="nav.my-items" :href="route('checklist-items.index')" :active="request()->routeIs('checklist-items.index')">
                        <x-slot name="icon">
                            <x-icons.clipboard-list class="size-4" />
                        </x-slot>
                        @lang('general.my-items')
                    </x-layouts.app.sidebar.single-item>
                    @endactivepermission

                    {{-- Group: More (only for roles that can see any item below) --}}
                    @activerole('HR', 'super-admin', 'general-manager', 'manager')
                        <li x-show="!sidebarCollapsed || open" aria-hidden="true" class="px-3 py-[3px]">
                            <span class="text-caption font-semibold uppercase tracking-wider text-secondary-light">{{ app()->getLocale() === 'ar' ? 'أخرى' : 'More' }}</span>
                        </li>
                    @endactiverole

                    {{-- 8. News Management (super-admin, Managers, HRs) --}}
                    @activepermission('news.view')
                        <x-layouts.app.sidebar.single-item data-tour="nav.news" :href="route('news.index')" :active="request()->routeIs('news.*')">
                            <x-slot name="icon">
                                <x-icons.newspaper class="size-4" />
                            </x-slot>
                            {{ __('general.news-management') }}
                        </x-layouts.app.sidebar.single-item>
                    @endactivepermission


                    @activepermission('badges.view')
                        <x-layouts.app.sidebar.single-item data-tour="nav.badges" :href="route('badges.index')" :active="request()->routeIs('badges.*')">
                            <x-slot name="icon" class="text-secondary-light">
                                <x-icons.badges class="size-4" />
                            </x-slot>
                            {{ __('general.all-badges') }}
                        </x-layouts.app.sidebar.single-item>
                    @endactivepermission

                    @activepermission('activity-log.view')
                        <x-layouts.app.sidebar.single-item data-tour="nav.activity-log" :href="route('activity-log.index')" :active="request()->routeIs('activity-log.*')">
                            <x-slot name="icon">
                                <x-icons.activity-log class="size-4" />
                            </x-slot>
                            {{ __('general.activity-log') }}
                        </x-layouts.app.sidebar.single-item>
                    @endactivepermission

                    @activepermission('access-management.view')
                        <x-layouts.app.sidebar.single-item data-tour="nav.access" :href="route('access-management.index')" :active="request()->routeIs('access-management.*')">
                            <x-slot name="icon">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.1a7.5 7.5 0 0 1 15 0M18 8.25h3m-1.5-1.5v3"/>
                                </svg>
                            </x-slot>
                            {{ __('access.title') }}
                        </x-layouts.app.sidebar.single-item>
                    @endactivepermission

                </ul>
            </div>
        </div>
    </aside>
    {{-- Dim to focus: the drawer is a modal layer on mobile, so the page behind
         it is both dimmed and pushed out of focus. --}}
    <div x-show="open" x-transition.opacity @click="open = false"
        class="fixed inset-0 z-30 bg-neutral-950/30 backdrop-blur-sm md:hidden" x-cloak></div>
</div>
