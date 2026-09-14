@php
    use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

    $isRtl = app()->getLocale() === 'ar';
    $user = auth()->user();
    $activeRole = $user->active_role_name ?? __('None');
    $switchTo = $isRtl ? 'en' : 'ar';
    $localized = LaravelLocalization::getLocalizedURL($switchTo, null, [], true);
    $curr = app()->getLocale();
    $enUrl = LaravelLocalization::getLocalizedURL('en', null, [], true);
    $arUrl = LaravelLocalization::getLocalizedURL('ar', null, [], true);
    $guideLabel = $isRtl ? 'دليل الاستخدام' : 'User guide';
    $homepageLabel = $isRtl ? 'الموقع الرئيسي' : 'Main website';
    $feedbackLabel = $isRtl ? 'إرسال مشكلة أو ملاحظة' : 'Send problem or feedback';
@endphp

<nav id="app-navbar" dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
    class="fixed inset-x-0 top-0 z-10 w-screen bg-white md:w-full dark:bg-neutral-800 border-b border-neutral-200 dark:border-neutral-700">

    {{--
        Everything below `sm` is tightened purely to buy horizontal room. The row
        is `justify-end`, so when its content is wider than the viewport the
        overflow spills off the *start* edge — and the hamburger is the first
        item, so it was the thing that silently went off-screen (x:-51 at 375px).
        At 420px the row needed 397px inside a 380px box. sm and up is untouched.
    --}}
    <div class="px-2 py-[11px] sm:px-3 lg:px-4 lg:ps-3">
        <div class="flex items-center justify-end gap-1.5 sm:gap-3 md:pe-0 sm:pe-4">
            <button wire:click="$dispatch('sidebar.toggle')" type="button"
                class="p-1.5 sm:p-2 shrink-0 transition bg-transparent border border-transparent cursor-pointer md:hidden me-auto text-heading text-secondary-light hover:text-secondary-dark rounded-base">
                <span class="sr-only">Open sidebar</span>
                <svg class="w-6 h-6" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                    fill="none" viewBox="0 0 24 24">
                    <path stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M5 7h14M5 12h14M5 17h10" />
                </svg>
            </button>
            {{-- Island 1: language segmented (EN | AR) --}}
            <div class="flex items-center shrink-0 rounded-[18px] bg-[#f6f6f6] p-0.5 sm:p-1">
                <a href="{{ $enUrl }}"
                    class="inline-flex items-center justify-center h-9 px-2.5 sm:px-4 rounded-[14px] text-body font-bold leading-none transition {{ $curr === 'en' ? 'bg-white text-neutral-900 shadow-e1' : 'text-secondary-light font-semibold hover:text-secondary-dark' }}"
                    aria-label="English" @if($curr === 'en') aria-current="true" @endif>EN</a>
                <a href="{{ $arUrl }}"
                    class="inline-flex items-center justify-center h-9 px-2.5 sm:px-4 rounded-[14px] text-body font-bold leading-none transition {{ $curr === 'ar' ? 'bg-white text-neutral-900 shadow-e1' : 'text-secondary-light font-semibold hover:text-secondary-dark' }}"
                    aria-label="العربية" @if($curr === 'ar') aria-current="true" @endif>AR</a>
            </div>

            {{-- Island 2: utility icons grouped in one capsule --}}
            <div class="flex items-center shrink-0 gap-0.5 rounded-[18px] bg-[#f6f6f6] p-0.5 sm:p-1">
                <a href="https://docs.google.com/document/d/1AcrvWNBzoD-bTem3Gy_35EtDgwD-SXST/edit?usp=sharing&ouid=105248334092459549739&rtpof=true&sd=true"
                    target="_blank" rel="noopener"
                    class="inline-flex items-center justify-center transition rounded-[14px] size-8 sm:size-9 text-secondary-light hover:bg-white hover:text-secondary-dark hover:shadow-e1 focus:outline-none focus:ring-2 focus:ring-neutral-200"
                    title="{{ $guideLabel }}" aria-label="{{ $guideLabel }}">
                    <svg width="15" height="15" viewBox="0 0 29.93 27.45" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M29.93,5.57v14.8c-.37,2.76-2.35,4.97-5.12,5.48l-7.71,1.4c-1.36.25-2.68.28-4.05.03l-7.81-1.42c-2.8-.46-4.86-2.68-5.23-5.52V5.6C.46,1.94,3.76-.55,7.38.11l4.64.84c1.15.21,2.16.74,2.95,1.64.75-.84,1.7-1.4,2.8-1.6L22.6.1c3.6-.65,6.85,1.88,7.32,5.47ZM13.72,24.86V5.91c0-1.23-.84-2.25-2.04-2.47l-4.73-.87c-2.31-.43-4.45,1.31-4.45,3.69v13.43c0,1.82,1.27,3.37,3.06,3.7l8.16,1.48ZM24.4,23.37c1.8-.33,3.04-1.9,3.04-3.69V6.25c0-2.32-2.07-4.12-4.35-3.7l-4.89.9c-1.18.22-1.98,1.27-1.98,2.46v18.96s8.19-1.49,8.19-1.49Z"/></svg>
                </a>

                <a href="https://hcsyria.org/home" target="_blank" rel="noopener"
                    class="inline-flex items-center justify-center transition rounded-[14px] size-8 sm:size-9 text-secondary-light hover:bg-white hover:text-secondary-dark hover:shadow-e1 focus:outline-none focus:ring-2 focus:ring-neutral-200"
                    title="{{ $homepageLabel }}" aria-label="{{ $homepageLabel }}">
                    <svg width="15" height="15" viewBox="0 0 29.95 29.95" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M15.98,29.92l-2.01.03-.2-.05c-7.42-.59-13.25-6.55-13.77-13.87l.03-2.07C.56,6.49,6.49.52,14.03.03c4.07-.27,8.11,1.17,11.14,3.99,3.3,3.08,5.04,7.45,4.75,11.9-.49,7.54-6.46,13.47-13.93,14ZM8.21,8.73c.9-2.13,2.07-3.96,3.48-5.77-3.21.87-5.85,2.94-7.51,5.77h4.02ZM25.75,8.73c-1.67-2.85-4.31-4.89-7.5-5.77,1.43,1.82,2.6,3.69,3.49,5.78h4.01ZM18.98,8.74c-1.07-2.12-2.4-4-4.01-5.72-1.61,1.72-2.94,3.6-4.01,5.72h8.03ZM7.36,18.72c-.66-2.47-.66-5.01,0-7.48H3.07c-.77,2.47-.76,5.07,0,7.49h4.29ZM19.99,18.72c.79-2.47.79-5.08,0-7.49h-10.02c-.79,2.45-.79,5.03,0,7.49h10.03ZM26.87,18.72c.77-2.48.76-5.06,0-7.49h-4.29c.66,2.47.66,5.01,0,7.49h4.29ZM8.2,21.21h-4.01c1.66,2.83,4.29,4.9,7.51,5.78-1.44-1.81-2.59-3.69-3.5-5.78ZM18.98,21.22h-8.02c1.07,2.12,2.4,4,4.01,5.72,1.61-1.73,2.94-3.6,4.01-5.72ZM25.75,21.22h-4.02c-.89,2.12-2.06,3.96-3.48,5.77,3.2-.88,5.85-2.95,7.49-5.77Z"/></svg>
                </a>

                <a href="https://docs.google.com/forms/d/e/1FAIpQLSdxNuk6xkFZY1t4vb3kYEKkwMMPM6VnvfGPO-iH1VfTCiEHMQ/viewform"
                    target="_blank" rel="noopener"
                    class="inline-flex items-center justify-center transition rounded-[14px] size-8 sm:size-9 text-secondary-light hover:bg-white hover:text-secondary-dark hover:shadow-e1 focus:outline-none focus:ring-2 focus:ring-neutral-200"
                    title="{{ $feedbackLabel }}" aria-label="{{ $feedbackLabel }}">
                    <svg width="15" height="15" viewBox="0 0 29.95 29.95" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M29.92,13.93c-.27-3.41-1.64-6.63-4-9.15C22.84,1.48,18.48-.26,14.03.03,5.69.58-.52,7.79.03,15.97c.51,7.54,6.51,13.46,13.98,13.95l2.01.03c7.41-.53,13.39-6.51,13.92-13.92l-.02-2.1ZM14.97,27.45c-6.89,0-12.47-5.58-12.47-12.47S8.09,2.5,14.97,2.5s12.47,5.58,12.47,12.47-5.58,12.47-12.47,12.47Z"/><path d="M14.98,12.48h-1.24c-.71,0-1.26.57-1.26,1.24,0,.69.55,1.26,1.26,1.26h1.23s0,7.48,0,7.48c0,.72.58,1.25,1.25,1.25.67,0,1.24-.53,1.24-1.28v-7.45c0-1.39-1.12-2.49-2.49-2.5Z"/><path d="M14.98,9.99c1.03,0,1.87-.84,1.87-1.87s-.84-1.87-1.87-1.87-1.87.84-1.87,1.87.84,1.87,1.87,1.87Z"/></svg>
                </a>

                <x-layouts.app.notification />
            </div>

            @auth
                <div x-data="roleDropdown()" x-init="$store.dir = { rtl: @js($isRtl) }" class="relative shrink-0 ms-0 sm:ms-3" @keydown.escape.window="open=false">

                    <button @click="toggle()" :aria-expanded="open" aria-haspopup="menu" class="inline-flex items-center justify-center transition rounded-full size-10 focus:outline-none focus:ring-2 focus:ring-neutral-200">

                        <span class="relative inline-flex items-center justify-center rounded-full size-10 shrink-0">
                            <img class="object-cover border rounded-full size-10 border-primary"
                                src="{{ $user->profile?->avatar_url }}" alt="user" />

                            <span
                                class="absolute bottom-0.5 {{ $isRtl ? 'start-0.5' : 'end-0.5' }}
                                         size-2 rounded-full bg-emerald-500 ring-2 ring-white"></span>
                        </span>
                    </button>

                    <div x-cloak x-show="open" x-transition.duration.160ms.origin.bottom @click.outside="open=false"
                        :class="$store.dir.rtl ? 'end-0' : 'end-0'"
                        class="z-50 absolute top-full mt-2 w-[250px] rounded-surface border bg-white
                                shadow-[0_25px_80px_-20px_rgba(2,6,23,0.35)] ring-1 ring-black/5"
                        role="menu">

                        <div class="relative px-4 pt-4 pb-4">
                            <div
                                class="pointer-events-none absolute inset-0 opacity-[0.07]
                                    bg-[radial-gradient(1200px_400px_{{ $isRtl ? 'at_90%_-20%' : 'at_10%_-20%' }},theme(colors.primary.500/.6),transparent_40%)]">
                            </div>

                            <div class="relative flex items-center gap-3">
                                <span class="inline-flex items-center justify-center overflow-hidden rounded-full size-14 shrink-0">
                                    <img class="object-cover border rounded-full size-12 border-primary"
                                        src="{{ $user->profile?->avatar_url }}" alt="user">
                                </span>

                                <div class="min-w-0">
                                    <div class="text-body font-semibold text-neutral-900 truncate">
                                        {{ $user->profile?->name }}
                                    </div>
                                    <div class="text-small text-secondary-medium truncate">{{ $user->email }}</div>
                                    <div class="text-small text-secondary-medium truncate">{{ $user->myRole() }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="h-px bg-gradient-to-r from-transparent via-neutral-200 to-transparent"></div>

                        <div class="px-2 py-2">
                            <a href="{{ route('my-profile') }}" wire:navigate
                                class="flex items-center gap-3 px-3 py-2 text-body font-medium text-neutral-800 transition rounded-surface hover:bg-secondary-soft hover:text-primary hover:shadow-e1">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.8">
                                    <circle cx="12" cy="8" r="4" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 20c0-4 4-6 8-6s8 2 8 6" />
                                </svg>
                                <span>@lang('general.my-profile')</span>
                            </a>

                            <a href="{{ route('settings.password') }}"
                                class="flex items-center gap-3 px-3 py-2 text-body font-medium text-neutral-800 transition rounded-surface hover:bg-neutral-100/90 hover:shadow-e1">
                                <svg class="text-neutral-800 size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="1.8">
                                    <rect x="5" y="10" width="14" height="10" rx="2" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 10V7a4 4 0 0 1 8 0v3" />
                                </svg>
                                <span>@lang('general.my-account')</span>
                            </a>
                        </div>

                        <div class="h-px bg-gradient-to-r from-transparent via-neutral-200 to-transparent"></div>

                        {{--                        @if ($user->hasMultipleRoles()) --}}
                        {{--                            <div class="px-4 py-4"> --}}
                        {{--                                <div class="flex items-center justify-between mb-2"> --}}
                        {{--                                    <div class="text-caption font-semibold text-secondary-light uppercase tracking-wide"> --}}
                        {{--                                        {{ __('Switch role') }} --}}
                        {{--                                    </div> --}}

                        {{--                                    <button @click="rolesOpen = !rolesOpen" --}}
                        {{--                                            class="inline-flex items-center gap-2 rounded-surface border border-neutral-200 --}}
                        {{--                                                   px-3 py-2 text-small font-medium hover:border-secondary-medium --}}
                        {{--                                                   hover:bg-secondary-soft transition"> --}}
                        {{--                                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" --}}
                        {{--                                             stroke-width="1.8"> --}}
                        {{--                                            <path d="M16 3h5v5"/> --}}
                        {{--                                            <path d="M4 20l16-16"/> --}}
                        {{--                                            <path d="M21 16v5h-5"/> --}}
                        {{--                                            <path d="M15 15l6 6"/> --}}
                        {{--                                            <path d="M3 3l6 6"/> --}}
                        {{--                                        </svg> --}}
                        {{--                                        {{ __('Switch') }} --}}
                        {{--                                    </button> --}}
                        {{--                                </div> --}}

                        {{--                                <div x-show="rolesOpen" x-transition.origin.top> --}}
                        {{--                                    <livewire:auth.role-switcher :onlySwitchables="true" :wire:key="'role-switcher-pill'"/> --}}
                        {{--                                </div> --}}

                        {{--                                <p class="mt-2 text-caption text-secondary-medium">{{ __('Choose a role to make it active instantly.') }}</p> --}}
                        {{--                            </div> --}}

                        {{--                            <div class="h-px bg-neutral-100"></div> --}}
                        {{--                        @endif --}}

                        <div class="px-2 py-2">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                    class="flex items-center w-full gap-3 px-3 py-2 text-body font-semibold text-red-600 transition rounded-surface hover:bg-red-50/80 hover:shadow-e1">
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
