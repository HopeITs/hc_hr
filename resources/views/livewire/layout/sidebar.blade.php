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
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center justify-start ps-6 h-16 shrink-0 border-b border-black/[0.06]">
                <x-app-logo class="w-[8.25rem] h-auto object-fit" x-show="!sidebarCollapsed || open" />
                <img src="{{ asset('assets/img/hope-center-icon.svg') }}"
                     alt="{{ config('app.name') }}"
                     x-show="sidebarCollapsed && !open"
                     class="hidden object-contain size-8 md:block" />
            </a>

            {{-- C1: which role the menu below belongs to. A user with several roles
                 switches it in the avatar menu; the whole menu follows the active role. --}}
            <div x-show="!sidebarCollapsed || open" class="px-6 pt-3 text-caption text-secondary-medium" data-testid="nav-active-role">
                {{ __('general.nav.acting-as') }}: <span class="font-semibold text-secondary-dark">{{ role_label($user->myRole()) }}</span>
            </div>

            {{-- Scrollable navigation area. Structure and visibility: App\Support\Navigation (C0 §4). --}}
            <div class="flex-1 min-h-0 px-3 pt-3 pb-4 overflow-x-hidden overflow-y-auto">
                <ul class="space-y-1 font-medium" :class="sidebarCollapsed && !open ? 'md:flex md:flex-col md:items-center' : ''">

                    @if ($nav['inbox'])
                        <x-layouts.app.sidebar.single-item data-tour="nav.inbox" data-nav="nav.inbox"
                            :href="route($nav['inbox']['route'])" :active="false" :badge="$nav['inbox']['count']"
                            :badge-label="__('general.nav.work-inbox-count', ['count' => $nav['inbox']['count']])">
                            <x-slot name="icon">
                                <x-icons.notification class="size-4" />
                            </x-slot>
                            {{ __('general.nav.work-inbox') }}
                        </x-layouts.app.sidebar.single-item>
                    @endif

                    @foreach ($nav['areas'] as $area)
                        <li class="w-full pt-2" data-tour="nav.area.{{ $area['id'] }}" data-nav-area="{{ $area['id'] }}">
                            <span x-show="!sidebarCollapsed || open" class="block px-3 py-[3px] text-caption font-semibold uppercase tracking-wider text-secondary-light">{{ $area['label'] }}</span>
                            <ul class="space-y-1" :class="sidebarCollapsed && !open ? 'md:flex md:flex-col md:items-center' : ''">
                                @include('livewire.layout.partials.nav-items', ['items' => $area['items']])
                            </ul>
                        </li>
                    @endforeach

                </ul>
            </div>
        </div>
    </aside>
    {{-- Dim to focus: the drawer is a modal layer on mobile, so the page behind
         it is both dimmed and pushed out of focus. --}}
    <div x-show="open" x-transition.opacity @click="open = false"
        class="fixed inset-0 z-30 bg-neutral-950/30 backdrop-blur-sm md:hidden" x-cloak></div>
</div>
