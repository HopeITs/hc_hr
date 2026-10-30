@php
    use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

    $isRtl = app()->getLocale() === 'ar';
    $chevFlip = $isRtl ? 'scale-x-[-1]' : '';
    $haloAnchor = $isRtl ? 'at_30%_30%' : 'at_70%_30%';
    $user = auth()->user();
    $activeRole = role_label($user->active_role_name) ?: __('None');
    $switchTo = $isRtl ? 'en' : 'ar';
    $localized = LaravelLocalization::getLocalizedURL($switchTo, null, [], true);
@endphp

@auth
    <div x-data="roleDropdown()" x-init="$store.dir = { rtl: @js($isRtl) }" class="relative w-full"
         @keydown.escape.window="open=false">
        <button @click="toggle()" :aria-expanded="open" aria-haspopup="menu"
                class="group flex w-full items-center gap-3 px-3 py-2 rounded-surface
                   bg-card
                   border border-line-soft shadow-e1 hover:bg-hover transition">
            <span
                class="relative inline-grid place-items-center rounded-full shrink-0">
                <img class="size-14 border border-1 border-line rounded-full object-cover"
                     src="{{ $user->profile->avatar_url }}" alt=""/>
                <span aria-hidden="true"
                    class="absolute bottom-1.5 end-1.5 size-2 rounded-full bg-ink ring-2 ring-card"></span>
            </span>

            <div class="min-w-0 text-start">
                <div class="text-body font-semibold text-ink truncate">{{ $user->profile?->name }}</div>
                <span id="activeRolePill"
                      class="mt-1 inline-flex items-center gap-1 px-2 py-1 rounded-full text-caption font-medium
                             bg-surface text-ink border border-line-soft">
                    {{ $activeRole }}
                </span>
            </div>

            <svg class="ms-auto size-4 text-ink-3 group-hover:text-ink-2 transition {{ $chevFlip }}"
                 viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd"
                      d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.24a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08z"
                      clip-rule="evenodd"/>
            </svg>
        </button>

        <div class="mt-3 px-3">
            <a href="{{ route('notifications.index') }}" wire:navigate
               class="flex items-center justify-between w-full px-3 py-2 text-body font-medium bg-surface text-ink rounded-surface border border-line-soft hover:bg-hover transition">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-ink-2" fill="none" stroke="currentColor" stroke-width="2"
                         viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V4a2 2 0 00-4 0v1.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                    <span>Notifications</span>
                </div>
                <livewire:notifications.counter :poll="false" />
            </a>
        </div>


        <div x-cloak x-show="open" x-transition.duration.160ms.origin.bottom @click.outside="open=false"
             class="z-50 absolute mb-2 top-full w-full
                   rounded-surface border border-line-soft bg-card backdrop-blur-xl
                   shadow-e3"
             :class="$store.dir.rtl ? 'right-0' : 'left-0'" role="menu">
            <div class="relative px-4 pt-4 pb-4">

                <div class="relative flex items-center gap-3">
                    <span
                        class="rounded-full shrink-0">
                        <img class="size-12 border border-1 border-line rounded-full object-cover"
                             src="{{ $user->profile->avatar_url }}" alt="">
                    </span>
                    <div class="min-w-0">
                        <div class="text-body font-semibold text-ink truncate">{{ $user->profile?->name }}</div>
                        <div class="text-small text-ink-2 truncate">{{ $user->email }}</div>

                    </div>
                </div>

                <div class="mt-3">
                    <span
                        class="inline-flex items-center gap-2 px-2 py-1 rounded-full text-caption font-semibold
                                 bg-surface text-ink border border-line">
                        {{ __('Current:') }} <span x-text="activeRole">{{ $activeRole }}</span>
                    </span>
                </div>
            </div>

            <div class="h-px bg-gradient-to-r from-transparent via-line-soft to-transparent"></div>

            <div class="px-2 py-2">
                <a href="{{ route('settings.profile') }}" wire:navigate
                   class="flex items-center gap-3 px-3 py-2 rounded-surface text-body font-medium
                      text-ink hover:bg-hover hover:shadow-e1 transition">
                    <svg class="size-4 text-ink" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 20c0-4 4-6 8-6s8 2 8 6"/>
                    </svg>
                    <span>@lang('general.my-account')</span>
                </a>

                <a href="{{ $localized }}"
                   class="flex items-center gap-3 px-3 py-2 rounded-surface text-body font-medium
                      text-ink hover:bg-hover hover:shadow-e1 transition">
                    <svg class="size-4 text-ink" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>
                    </svg>
                    <span class="font-medium">{{ $switchTo === 'ar' ? 'العربية' : 'English' }}</span>
                </a>
            </div>

            <div class="h-px bg-gradient-to-r from-transparent via-line-soft to-transparent"></div>

            @if ($user->hasMultipleRoles())
                <div class="px-4 py-4" id="roleFlyout" :data-active-role="activeRole">
                    <div class="flex items-center justify-between mb-2">
                        <div class="text-caption font-semibold text-ink-3 tracking-wide uppercase">
                            {{ __('Switch role') }}</div>
                        <button @click="rolesOpen = !rolesOpen" @mouseenter="rolesOpen = true"
                                class="inline-flex items-center gap-2 rounded-surface border border-line-soft px-3 py-2 text-small font-medium
                                   hover:border-ink-2 hover:bg-hover transition"
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
                                    [&>div>button]:border-2 [&>div>button]:border-line-soft [&>div>button]:shadow-e1
                                    [&>div>button:hover]:border-ink-2 [&>div>button:hover]:bg-surface
                                    [&>div>button]:flex [&>div>button]:items-center [&>div>button]:gap-3">
                            <livewire:auth.role-switcher :onlySwitchables="true" :wire:key="'role-switcher-pill'"/>
                        </div>
                    </div>

                    <p class="mt-2 text-caption text-ink-2">{{ __('Choose a role to make it active instantly.') }}</p>
                </div>

                <div class="h-px bg-surface"></div>
            @endif

            <div class="px-2 py-2">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-surface text-body font-semibold
                               text-ink hover:bg-hover hover:shadow-e1
                               transition">
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
        <a href="{{ route('login') }}" wire:navigate
           class="block w-full text-center py-2 px-4 text-body font-semibold text-on-brand bg-primary rounded-surface">
            @lang('auth.login')
        </a>
        <a href="{{ route('register') }}" wire:navigate
           class="block w-full text-center py-2 px-4 text-body font-semibold text-ink bg-surface hover:bg-hover rounded-surface">
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

    // wire:navigate re-runs this script on every page and DOMContentLoaded never
    // fires again, so register the (global) listener once, whenever Livewire is up.
    (() => {
        if (window.__hcRoleSwitchedBound) return;

        const bind = () => {
            if (window.__hcRoleSwitchedBound) return;
            window.__hcRoleSwitchedBound = true;
            Livewire.on('role-switched', ({role, label}) => {document.getElementById('activeRolePill')?.replaceChildren(document.createTextNode(label ?? role));
            });
        };

        window.Livewire?.on ? bind() : document.addEventListener('livewire:init', bind, { once: true });
    })();
</script>
