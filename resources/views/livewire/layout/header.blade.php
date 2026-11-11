@php
    use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

    $isRtl = app()->getLocale() === 'ar';
    $user = auth()->user();
    $activeRole = role_label($user->active_role_name) ?: __('None');
    $switchTo = $isRtl ? 'en' : 'ar';
    $localized = LaravelLocalization::getLocalizedURL($switchTo, null, [], true);
    $curr = app()->getLocale();
    $enUrl = LaravelLocalization::getLocalizedURL('en', null, [], true);
    $arUrl = LaravelLocalization::getLocalizedURL('ar', null, [], true);
    $guideLabel = $isRtl ? 'دليل الاستخدام' : 'User guide';
    $homepageLabel = $isRtl ? 'الموقع الرئيسي' : 'Main website';
@endphp

<nav id="app-navbar" dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
    style="padding-top: env(safe-area-inset-top, 0px)"
    class="fixed inset-x-0 top-0 z-30 w-screen bg-base md:w-full border-b border-line-soft">

    {{--
        Everything below `sm` is tightened purely to buy horizontal room. The row
        is `justify-end`, so when its content is wider than the viewport the
        overflow spills off the *start* edge — and the hamburger is the first
        item, so it was the thing that silently went off-screen (x:-51 at 375px).
        At 420px the row needed 397px inside a 380px box. sm and up is untouched.
    --}}
    {{-- md+: a fixed 72px row, matched by the sidebar's logo row (sidebar.blade.php). --}}
    <div class="px-2 py-[11px] sm:px-3 lg:px-4 lg:ps-3 md:py-0 md:h-18 md:flex md:items-center">
        <div class="w-full flex items-center justify-end gap-1.5 sm:gap-3 md:pe-0 sm:pe-4">
            <button wire:click="$dispatch('sidebar.toggle')" type="button"
                class="grid size-11 place-items-center md:size-auto sm:p-2 shrink-0 transition bg-transparent border border-transparent cursor-pointer md:hidden me-auto text-heading text-ink-2 hover:text-ink rounded-base">
                <span class="sr-only">{{ __('general.a11y.open-sidebar') }}</span>
                <svg class="w-6 h-6" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                    fill="none" viewBox="0 0 24 24">
                    <path stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M5 7h14M5 12h14M5 17h10" />
                </svg>
            </button>
            {{-- C2: search pages and people, and start a task: its Commands group has the New
                 actions. The New button was taken off the bar (2026-11-03); the phone's + and
                 the dashboard's shortcuts still offer them. --}}
            <livewire:layout.global-search />

            {{-- Language: only the other one, as a single button. --}}
            <a href="{{ $curr === 'ar' ? $enUrl : $arUrl }}"
                class="inline-flex items-center justify-center h-11 sm:h-9 min-w-11 sm:min-w-9 px-2 shrink-0 rounded-[14px] text-body font-semibold leading-none text-ink-2 transition hover:bg-hover hover:text-ink"
                aria-label="{{ $curr === 'ar' ? 'English' : 'العربية' }}">{{ $curr === 'ar' ? 'EN' : 'AR' }}</a>

            {{-- Island 2: the few icons used often; the rest are in the account menu. --}}
            <div class="flex items-center shrink-0 gap-0.5 rounded-[18px] bg-soft p-0.5 sm:p-1">
                {{-- D3. Not on phones: one more 44px button pushed the menu button off the
                     screen at 375px (M1). There it is in the account menu, and the "?"
                     beside each difficult field opens the same panel. --}}
                <x-help.button/>
                <x-tour.button/>

                <x-layouts.app.notification />
            </div>

            @auth
                <div x-data="roleDropdown()" x-init="$store.dir = { rtl: @js($isRtl) }" class="relative shrink-0 ms-0 sm:ms-3" @keydown.escape.window="open=false">

                    <button @click="toggle()" :aria-expanded="open" aria-haspopup="menu" class="inline-flex items-center justify-center transition rounded-full size-11 sm:size-10">

                        <span class="relative inline-flex items-center justify-center rounded-full size-10 shrink-0">
                            <img class="object-cover border rounded-full size-10 border-line"
                                src="{{ $user->profile?->avatar_url }}" alt="" />

                            <span
                                class="absolute bottom-0.5 {{ $isRtl ? 'start-0.5' : 'end-0.5' }}
                                         size-2 rounded-full bg-ink-2 ring-2 ring-card"></span>
                        </span>
                    </button>

                    <div x-cloak x-show="open" x-transition.duration.160ms.origin.bottom @click.outside="open=false"
                        :class="$store.dir.rtl ? 'end-0' : 'end-0'"
                        class="z-50 absolute top-full mt-2 w-[250px] max-h-[calc(100dvh-5.5rem)] overflow-y-auto overscroll-contain rounded-surface border border-line-soft bg-card shadow-e3"
                        role="menu">

                        <div class="relative px-4 pt-4 pb-4">
                            <div class="relative flex items-center gap-3">
                                <span class="inline-flex items-center justify-center overflow-hidden rounded-full size-14 shrink-0">
                                    <img class="object-cover border rounded-full size-12 border-line"
                                        src="{{ $user->profile?->avatar_url }}" alt="">
                                </span>

                                <div class="min-w-0">
                                    <div class="text-body font-semibold text-ink truncate">
                                        {{ $user->profile?->name }}
                                    </div>
                                    <div class="text-small text-ink-2 truncate">{{ $user->email }}</div>
                                    <div class="text-small text-ink-2 truncate">{{ role_label($user->myRole()) }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="h-px bg-gradient-to-r from-transparent via-line-soft to-transparent"></div>

                        <div class="px-2 py-2">
                            <a href="{{ route('my-profile') }}" wire:navigate
                                class="flex items-center gap-3 px-3 py-2 text-body font-medium text-ink transition rounded-surface hover:bg-hover hover:shadow-e1">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.8">
                                    <circle cx="12" cy="8" r="4" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 20c0-4 4-6 8-6s8 2 8 6" />
                                </svg>
                                <span>@lang('general.my-profile')</span>
                            </a>

                            <a href="{{ route('settings.password') }}" wire:navigate
                                class="flex items-center gap-3 px-3 py-2 text-body font-medium text-ink transition rounded-surface hover:bg-hover hover:shadow-e1">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.8">
                                    <rect x="5" y="10" width="14" height="10" rx="2" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 10V7a4 4 0 0 1 8 0v3" />
                                </svg>
                                <span>@lang('general.my-account')</span>
                            </a>

                            {{-- "Install the app" (pwa-install.js): hidden when this already is the installed app. --}}
                            <a href="{{ route('settings.app') }}" wire:navigate data-install-app-link
                                x-data="pwaInstall" x-show="!installed"
                                class="flex items-center gap-3 px-3 py-2 text-body font-medium text-ink transition rounded-surface hover:bg-hover hover:shadow-e1">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <rect x="6" y="2.5" width="12" height="19" rx="2.5" />
                                    <path d="M12 7v7M9 11l3 3 3-3M10.5 18.5h3" />
                                </svg>
                                <span>@lang('pwa.menu')</span>
                            </a>

                            {{-- Notification preferences, devices and push (docs/notifications/architecture.md). --}}
                            <a href="{{ route('settings.notifications') }}" wire:navigate data-notification-settings-link
                                class="flex items-center gap-3 px-3 py-2 text-body font-medium text-ink transition rounded-surface hover:bg-hover hover:shadow-e1">
                                <x-ic name="bell" class="size-4" />
                                <span>@lang('notification-settings.menu')</span>
                            </a>

                            {{-- D3, phones only: the header's help button does not fit there. Same panel, no request. --}}
                            <button type="button" role="menuitem" data-help-menu-item
                                x-on:click="open = false; $dispatch('help-open')"
                                class="flex w-full min-h-11 items-center gap-3 px-3 py-2 text-start text-body font-medium text-ink transition rounded-surface sm:hidden hover:bg-hover hover:shadow-e1">
                                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" /><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.4M12 17h.01" />
                                </svg>
                                <span>@lang('help.panel.open')</span>
                            </button>

                            <x-theme-toggle menu />

                            {{-- D3: opens the feedback dialog over the current page; nothing navigates. --}}
                            <button type="button" role="menuitem" data-feedback-open
                                x-on:click="open = false; Livewire.dispatch('open-feedback')"
                                class="flex w-full min-h-11 items-center gap-3 px-3 py-2 text-start text-body font-medium text-ink transition rounded-surface hover:bg-hover hover:shadow-e1">
                                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z" />
                                    <path stroke-linecap="round" d="M8.5 10.5h7M8.5 13.5h4.5" />
                                </svg>
                                <span>@lang('feedback.menu')</span>
                            </button>
                        </div>

                        {{-- The secondary links stay out of the top bar. --}}
                        <div class="px-2 py-2">
                            @foreach ([[$guideLabel, 'https://docs.google.com/document/d/1AcrvWNBzoD-bTem3Gy_35EtDgwD-SXST/edit?usp=sharing&ouid=105248334092459549739&rtpof=true&sd=true'], [$homepageLabel, 'https://hcsyria.org/home']] as [$label, $href])
                                <a href="{{ $href }}" target="_blank" rel="noopener" role="menuitem"
                                    class="flex min-h-11 items-center gap-3 px-3 py-2 text-body font-medium text-ink transition rounded-surface hover:bg-hover">
                                    <span>{{ $label }}</span>
                                </a>
                            @endforeach
                        </div>

                        <div class="h-px bg-gradient-to-r from-transparent via-line-soft to-transparent"></div>

                                               @if ($user->hasMultipleRoles()) 
                                                   <div class="px-4 py-4"> 
                                                       <div class="flex items-center justify-between mb-2"> 
                                                           <div class="text-caption font-semibold text-ink-2 uppercase tracking-wide"> 
                                                               {{ __('Switch role') }} 
                                                           </div> 

                                                           <x-button variant="outline" size="sm" :loading="false" x-on:click="rolesOpen = !rolesOpen">
                                                               <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                                    stroke-width="1.8" aria-hidden="true">
                                                                   <path d="M16 3h5v5"/>
                                                                   <path d="M4 20l16-16"/>
                                                                   <path d="M21 16v5h-5"/>
                                                                   <path d="M15 15l6 6"/>
                                                                   <path d="M3 3l6 6"/>
                                                               </svg>
                                                               {{ __('Switch') }}
                                                           </x-button> 
                                                       </div> 

                                                       <div x-show="rolesOpen" x-transition.origin.top> 
                                                           <livewire:auth.role-switcher :onlySwitchables="true" :wire:key="'role-switcher-pill'"/> 
                                                       </div> 

                                                       <p class="mt-2 text-caption text-ink-2">{{ __('Choose a role to make it active instantly.') }}</p> 
                                                   </div> 

                                                   <div class="h-px bg-line-soft"></div> 
                                               @endif 

                        <div class="px-2 py-2">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                    class="flex items-center w-full gap-3 px-3 py-2 text-body font-semibold text-ink transition rounded-surface hover:bg-hover hover:shadow-e1">
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="1.8">
                                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                                        <path d="M10 17l5-5-5-5" />
                                        <path d="M15 12H3" />
                                    </svg>
                                    @lang('auth.logout')
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endauth
        </div>
    </div>
</nav>

<script nonce="{{ Vite::cspNonce() }}">
    function roleDropdown() {
        return {
            open: false,
            rolesOpen: false,
            activeRole: @js($activeRole),
            toggle() {
                this.open = !this.open;
            },
        }
    }
</script>
