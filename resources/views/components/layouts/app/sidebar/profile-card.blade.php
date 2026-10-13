@php
    use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

    $isRtl = app()->getLocale() === 'ar';
    $chevFlip = $isRtl ? 'scale-x-[-1]' : '';
    $haloAnchor = $isRtl ? 'at_30%_30%' : 'at_70%_30%';
    $user = auth()->user();
    $activeRole = $user->active_role_name ?? __('None');
    $switchTo = $isRtl ? 'en' : 'ar';
    $localized = LaravelLocalization::getLocalizedURL($switchTo, null, [], true);
@endphp

@auth
    <div x-data="roleDropdown()" x-init="$store.dir = { rtl: @js($isRtl) }" class="relative w-full"
         @keydown.escape.window="open=false">
        <button @click="toggle()" :aria-expanded="open" aria-haspopup="menu"
                class="group flex w-full items-center gap-3 px-3 py-2 rounded-surface
                   bg-white
                   border border-neutral-200/70 shadow-e1 hover:bg-neutral-50 transition">
            <span
                class="relative inline-grid place-items-center rounded-full shrink-0 ring-2 ring-primary/30">
                <img class="size-14 border border-1 border-primary rounded-full object-cover"
                     src="{{ $user->profile->avatar_url }}" alt="user"/>
                <span
                    class="absolute bottom-1.5 {{ $isRtl ? 'left-1.5' : 'right-1.5' }} size-2 rounded-full bg-neutral-500 ring-2 ring-white"></span>
            </span>

            <div class="min-w-0 text-start">
                <div class="text-body font-semibold text-neutral-900 truncate">{{ $user->profile?->name }}</div>
                <span id="activeRolePill"
                      class="mt-1 inline-flex items-center gap-1 px-2 py-1 rounded-full text-caption font-medium
                             bg-neutral-100 text-neutral-800 border border-neutral-200">
                    {{ $activeRole }}
                </span>
            </div>

            <svg class="ms-auto size-4 text-secondary-light group-hover:text-secondary-medium transition {{ $chevFlip }}"
                 viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd"
                      d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08z"
                      clip-rule="evenodd"/>
            </svg>
        </button>

        <div class="mt-3 px-3">
            <a href="{{ route('notifications.index') }}"
               class="flex items-center justify-between w-full px-3 py-2 text-body font-medium bg-neutral-100 text-neutral-800 rounded-surface border border-neutral-200 hover:bg-neutral-200 transition">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-secondary-medium" fill="none" stroke="currentColor" stroke-width="2"
                         viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V4a2 2 0 00-4 0v1.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                    <span>Notifications</span>
                </div>
                <livewire:notifications.counter wire:poll.10s/>
            </a>
        </div>


        <div x-cloak x-show="open" x-transition.duration.160ms.origin.bottom @click.outside="open=false"
             class="z-50 absolute mb-2 top-full w-full
                   rounded-surface border border-neutral-200/70 bg-white/80 backdrop-blur-xl
                   shadow-e3 ring-1 ring-black/5"
             :class="$store.dir.rtl ? 'right-0' : 'left-0'" role="menu">
            <div class="relative px-4 pt-4 pb-4">
                <div
                    class="pointer-events-none absolute inset-0 opacity-[0.07]
                    bg-[radial-gradient(1200px_400px_{{ $isRtl ? 'at_90%_-20%' : 'at_10%_-20%' }},theme(colors.primary.500/.6),transparent_40%)]">
                </div>

                <div class="relative flex items-center gap-3">
                    <span
                        class="rounded-full shrink-0 ring-2 ring-primary/30">
                        <img class="size-12 border border-1 border-primary rounded-full object-cover"
                             src="{{ $user->profile->avatar_url }}" alt="user">
                    </span>
                    <div class="min-w-0">
                        <div class="text-body font-semibold text-neutral-900 truncate">{{ $user->profile?->name }}</div>
                        <div class="text-small text-secondary-medium truncate">{{ $user->email }}</div>

                    </div>
                </div>

                <div class="mt-3">
                    <span
                        class="inline-flex items-center gap-2 px-2 py-1 rounded-full text-caption font-semibold
                                 bg-secondary-soft text-secondary-dark border border-secondary-light">
                        {{ __('Current:') }} <span x-text="activeRole">{{ $activeRole }}</span>
                    </span>
                </div>
            </div>

            <div class="h-px bg-gradient-to-r from-transparent via-neutral-200 to-transparent"></div>

            <div class="px-2 py-2">
                <a href="{{ route('settings.profile') }}"
                   class="flex items-center gap-3 px-3 py-2 rounded-surface text-body font-medium
                      text-neutral-800 hover:bg-neutral-100/90 hover:shadow-e1 transition">
                    <svg class="size-4 text-neutral-800" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 20c0-4 4-6 8-6s8 2 8 6"/>
                    </svg>
                    <span>@lang('general.my-account')</span>
                </a>

                <a href="{{ $localized }}"
                   class="flex items-center gap-3 px-3 py-2 rounded-surface text-body font-medium
                      text-neutral-800 hover:bg-neutral-100/90 hover:shadow-e1 transition">
                    <svg class="size-4 text-neutral-800" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>
                    </svg>
                    <span class="font-medium">{{ $switchTo === 'ar' ? 'العربية' : 'English' }}</span>
                </a>
            </div>

            <div class="h-px bg-gradient-to-r from-transparent via-neutral-200 to-transparent"></div>

            @if ($user->hasMultipleRoles())
                <div class="px-4 py-4" id="roleFlyout" :data-active-role="activeRole">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-caption font-semibold text-secondary-light tracking-wide uppercase">
                            {{ __('Switch role') }}</div>
                        <button @click="rolesOpen = !rolesOpen" @mouseenter="rolesOpen = true"
                                class="inline-flex items-center gap-2 rounded-surface border border-neutral-200 px-3 py-2 text-small font-medium
                                   hover:border-secondary-medium hover:bg-secondary-soft transition"
                                :aria-expanded="rolesOpen">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.8">
                                <path d="M16 3h5v5"/>
                                <path d="M4 20l16-16"/>
                                <path d="M21 16v5h-5"/>
                                <path d="M15 15l6 6"/>
                                <path d="M3 3l6 6"/>
                            </svg>
                            {{ __('Switch') }}
                        </button>
                    </div>

                    <div x-show="rolesOpen" x-transition.origin.top id="roleList" class="grid grid-cols-1 gap-2">
                        <div
                            class="[&>div>button]:w-full [&>div>button]:text-left [&>div>button]:p-3 [&>div>button]:rounded-surface
                                    [&>div>button]:border-2 [&>div>button]:border-neutral-200 [&>div>button]:shadow-e1
                                    [&>div>button:hover]:border-secondary-medium [&>div>button:hover]:bg-secondary-soft
                                    [&>div>button]:flex [&>div>button]:items-center [&>div>button]:gap-3">
                            <livewire:auth.role-switcher :onlySwitchables="true" :wire:key="'role-switcher-pill'"/>
                        </div>
                    </div>

                    <p class="mt-2 text-caption text-secondary-medium">{{ __('Choose a role to make it active instantly.') }}</p>
                </div>

                <div class="h-px bg-neutral-100"></div>
            @endif

            <div class="px-2 py-2">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-surface text-body font-semibold
                               text-primary hover:bg-neutral-50/80 hover:shadow-e1 focus:outline-none
                               focus-visible:ring-2 focus-visible:ring-primary/50 transition">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                            <path d="M10 17l5-5-5-5"/>
                            <path d="M15 12H3"/>
                        </svg>
                        <span>@lang('auth.logout')</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
@endauth

@guest
    <div class="p-4 space-y-2 w-full">
        <a href="{{ route('login') }}"
           class="block w-full text-center py-2 px-4 text-body font-semibold text-white bg-primary rounded-surface">
            @lang('auth.login')
        </a>
        <a href="{{ route('register') }}"
           class="block w-full text-center py-2 px-4 text-body font-semibold text-neutral-800 bg-neutral-200 hover:bg-neutral-300 rounded-surface">
            @lang('auth.register')
        </a>
    </div>
@endguest

<script nonce="{{ Vite::cspNonce() }}">
    function roleDropdown() {
        return {
            open: false,
            rolesOpen: false,
            activeRole: @js($activeRole),
            toggle() {
                this.open = !this.open
            },
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (window.Livewire?.on) {
            Livewire.on('role-switched', ({role}) => {document.getElementById('activeRolePill')?.replaceChildren(document.createTextNode(role));
            });
        }
    });
</script>
