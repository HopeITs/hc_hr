{{--
    "Customize your desk" (like hc-tracking's desk): Ready layouts shows each
    layout this person may use (DashboardLayout::presets) as a mosaic of its
    panels, the suggested one first; My panels switches panels on
    and off, and Reset goes back to the role's own desk. Nothing changes until
    it is confirmed (home-screen.js confirmDesk). On a phone, My panels also
    holds the bottom bar's shortcuts (they save themselves).

    Red keeps its jobs: the primary action, the layout being looked at, and
    the panels that hold what waits on the viewer (the inbox, what is late).
--}}
@php
    $waitingPanels = ['decide', 'late'];
@endphp

<div class="hc-desk-back" x-show="desk" x-cloak x-transition.opacity x-on:click.self="closeDesk()">
    <div class="hc-desk" role="dialog" aria-modal="true" aria-labelledby="hc-desk-title"
         x-show="desk" x-trap.noscroll="desk" x-on:keydown.escape.stop.prevent="closeDesk()"
         x-transition:enter="hc-desk-enter" x-transition:enter-start="hc-desk-from">

        {{-- Title, the two views, close. --}}
        <header class="hc-desk-head">
            <h2 id="hc-desk-title" class="text-h4 font-extrabold text-ink">{{ __('dashboard.desk.title') }}</h2>

            <div class="hc-desk-tabs" role="tablist" aria-label="{{ __('dashboard.desk.title') }}">
                @foreach (['layouts', 'panels'] as $tab)
                    <button type="button" role="tab" id="hc-desk-tab-{{ $tab }}" aria-controls="hc-desk-{{ $tab }}"
                            :aria-selected="deskTab === @js($tab)" :tabindex="deskTab === @js($tab) ? 0 : -1"
                            x-on:click="deskTab = @js($tab)"
                            x-on:keydown.arrow-right.prevent="deskTab = deskTab === 'layouts' ? 'panels' : 'layouts'; $nextTick(() => $el.parentElement.querySelector('[aria-selected=true]')?.focus())"
                            x-on:keydown.arrow-left.prevent="deskTab = deskTab === 'layouts' ? 'panels' : 'layouts'; $nextTick(() => $el.parentElement.querySelector('[aria-selected=true]')?.focus())">
                        {{ __('dashboard.desk.'.$tab) }}
                    </button>
                @endforeach
            </div>

            <button type="button" class="hc-desk-close" x-on:click="closeDesk()" aria-label="{{ __('dashboard.home.close') }}">
                <x-ic name="x" />
            </button>
        </header>

        {{-- Ready layouts: the list, and the one being looked at as a mosaic. --}}
        <div class="hc-desk-body" id="hc-desk-layouts" role="tabpanel" aria-labelledby="hc-desk-tab-layouts" x-show="deskTab === 'layouts'">
            <nav class="hc-desk-list" aria-label="{{ __('dashboard.desk.layouts') }}">
                @foreach ($presets as $preset)
                    <button type="button" class="hc-desk-item" x-on:click="deskPick = @js($preset['key'])"
                            :aria-current="deskPick === @js($preset['key'])">
                        <span class="min-w-0 truncate">{{ __('dashboard.presets.'.$preset['key'].'.name') }}</span>
                        @if ($preset['suggested'])
                            <span class="hc-desk-suggested">{{ __('dashboard.desk.suggested') }}</span>
                        @endif
                    </button>
                @endforeach
            </nav>

            <div class="hc-desk-stage">
                @foreach ($presets as $preset)
                    @php($name = __('dashboard.presets.'.$preset['key'].'.name'))
                    <section x-show="deskPick === @js($preset['key'])" aria-labelledby="hc-desk-name-{{ $preset['key'] }}">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                @if ($preset['suggested'])
                                    <p class="text-small font-bold text-primary">{{ __('dashboard.desk.suggested') }}</p>
                                @endif
                                <h3 id="hc-desk-name-{{ $preset['key'] }}" class="hc-desk-name">{{ $name }}</h3>
                                <p class="text-ink-2">{{ __('dashboard.presets.'.$preset['key'].'.lead') }}</p>
                                <p class="mt-1 text-small font-bold text-ink">{{ trans_choice('dashboard.desk.count', count($preset['tiles'])) }}</p>
                            </div>
                            <button type="button" class="hc-desk-link" x-on:click="deskTab = 'panels'">{{ __('dashboard.desk.adjust') }}</button>
                        </div>

                        {{-- The mosaic: each panel at the size it will have, on the same 12 columns. --}}
                        <ol class="hc-desk-mosaic" aria-label="{{ __('dashboard.desk.preview', ['layout' => $name]) }}">
                            @foreach ($preset['tiles'] as $tile)
                                <li @class(['hc-desk-panel', 'is-waiting' => in_array($tile['key'], $waitingPanels, true)])
                                    style="--c: {{ $tile['cols'] }}; --r: {{ $tile['rows'] }}">
                                    <x-dashboard.tile-icon :name="$tiles->icon($tile['key'])" />
                                    <span class="min-w-0 truncate">{{ $tiles->label($tile['key']) }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endforeach
            </div>
        </div>

        {{-- My panels: every panel, on or off. --}}
        <div class="hc-desk-body hc-desk-body--panels" id="hc-desk-panels" role="tabpanel" aria-labelledby="hc-desk-tab-panels" x-show="deskTab === 'panels'" x-cloak>
            <p class="text-ink-2">{{ __('dashboard.desk.panels-lead') }}</p>

            <div class="hc-desk-toggles">
                @foreach ($layout as $item)
                    @continue(isset($absentKeys[$item['key']]))
                    <label class="hc-desk-toggle" :class="{ 'is-on': deskShown[@js($item['key'])] }" title="{{ $tiles->label($item['key']) }}">
                        <span class="hc-desk-toggle-icon"><x-dashboard.tile-icon :name="$tiles->icon($item['key'])" /></span>
                        {{-- Two lines, never cut: "Employees by department" and its siblings only differ at the end. --}}
                        <span class="min-w-0 flex-1 line-clamp-2 font-bold leading-snug">{{ $tiles->label($item['key']) }}</span>
                        <input type="checkbox" role="switch" class="hc-switch" x-model="deskShown[@js($item['key'])]">
                    </label>
                @endforeach
            </div>

            {{-- The phone's bottom bar (prompt 10), which used to live in the old Customise mode.
                 Lazy: it loads only once it is on screen, so never on a computer. --}}
            <div class="md:hidden" data-slot="bottom-bar-picker">
                <livewire:layout.bottom-bar-picker lazy />
            </div>
        </div>

        <footer class="hc-desk-foot">
            <x-button variant="quiet" size="sm" icon="reset" :loading="false" x-on:click="reset()" data-testid="dashboard-reset">{{ __('dashboard.home.reset') }}</x-button>
            <div class="flex items-center gap-2">
                <x-button variant="quiet" :loading="false" x-on:click="closeDesk()">{{ __('general.cancel') }}</x-button>
                @foreach ($presets as $preset)
                    <x-button :loading="false" x-show="deskTab === 'layouts' && deskPick === '{{ $preset['key'] }}'"
                              x-on:click="confirmDesk()" x-bind:disabled="deskBusy">
                        {{ __('dashboard.desk.use', ['layout' => __('dashboard.presets.'.$preset['key'].'.name')]) }}
                    </x-button>
                @endforeach
                <x-button :loading="false" x-show="deskTab === 'panels'" x-cloak x-on:click="confirmDesk()" x-bind:disabled="deskBusy">
                    {{ __('dashboard.desk.save-panels') }}
                </x-button>
            </div>
        </footer>
    </div>
</div>
