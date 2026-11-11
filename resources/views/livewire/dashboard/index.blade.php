@php
    use App\Support\Dashboard\DashboardLayout;

    $user = auth()->user();

    // The home screen (resources/js/dashboard/home-screen.js) gets the whole
    // layout, hidden tiles included (Add offers them), and keeps it in data-home:
    // after Reset re-renders the page, the script takes the new layout from there.
    $absentKeys = array_flip($absent);
    $home = [
        'layout' => $layout,
        'absent' => $absent,
        'bounds' => collect($layout)->mapWithKeys(fn (array $item) => [$item['key'] => $tiles->bounds($item['key'])])->all(),
        'looks' => DashboardLayout::LOOKS,
        'phoneHidden' => DashboardLayout::PHONE_HIDDEN,
        // A hub group filters the grid to its own cards (home-screen.js `group`).
        'groups' => $tiles->groupTiles($user, array_column($layout, 'key')),
        // Reset the layout only (home-screen.js resetArrangement): each card's default size and place.
        // One card out of the browser (Dashboard\Window): __TILE__ is its key.
        'windowUrl' => route('dashboard.window', ['tile' => '__TILE__']),
        'defaults' => collect($tiles->defaults($user))->values()->mapWithKeys(fn (array $item, int $i) => [$item['key'] => [$item['size'], $i]])->all(),
        'labels' => collect($layout)->mapWithKeys(fn (array $item) => [$item['key'] => $tiles->label($item['key'], $user)])->all(),
        'editing' => $customise,
        'text' => [
            'tile' => __('dashboard.home.tile'),
            'size' => __('dashboard.home.size'),
            'resize' => __('dashboard.home.resize'),
            'moved' => __('dashboard.home.moved'),
            'resized' => __('dashboard.home.resized'),
            'hidden' => __('dashboard.home.hidden'),
            'minimised' => __('dashboard.home.minimised-said'),
            'restored' => __('dashboard.home.restored-said'),
            'added' => __('dashboard.home.added'),
            'pinned' => __('dashboard.home.pinned'),
            'unpinned' => __('dashboard.home.unpinned'),
            'resetTitle' => __('dashboard.home.reset-title'),
            'resetMessage' => __('dashboard.home.reset-message'),
            'resetConfirm' => __('dashboard.home.reset'),
            'presetTitle' => __('dashboard.home.presets.confirm-title'),
            'presetMessage' => __('dashboard.home.presets.confirm-message'),
            'presetConfirm' => __('dashboard.home.presets.confirm'),
            'cancel' => __('general.cancel'),
            'nothing' => __('dashboard.home.nothing'),
            'filtered' => __('dashboard.home.filtered-said'),
            'layoutReset' => __('dashboard.home.reset-menu.layout-done'),
            'undo' => __('general.toast.undo'),
            'unfiltered' => __('dashboard.home.unfiltered-said'),
        ],
    ];
@endphp

<x-slot:title>
    {{ __('general.dashboard') }}
</x-slot:title>

{{-- The home screen's script, on this page only (a separate Vite entry, with SortableJS behind its own import). --}}
@section('css')
    @vite('resources/js/dashboard/home-screen.js')
@endsection

<div>
    <div class="hc-home-screen"
         x-data="hcHomeScreen"
         data-home="{{ json_encode($home, JSON_UNESCAPED_UNICODE) }}"
         x-on:keydown.escape.window="escape($event)">

        {{-- One row: the greeting (its own component, so the grid's renders never recompute
             it), and while customising: Reset, Add, Done. Customise itself sits in the row
             below, beside Reset to default. --}}
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0 flex-[1_1_16rem]">
                <livewire:dashboard.header :name="(string) ($employee?->first_name ?? '')"
                                           :job-title="$positionTitle === '—' ? '' : (string) $positionTitle" />
                <p class="text-hint text-ink-2" aria-live="polite" x-show="editing" x-cloak>{{ __('dashboard.home.edit-lead') }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                {{-- :loading="false": these drive the browser-side grid; a background layout
                     save must not disable them. --}}
                <span class="flex flex-wrap items-center gap-2" x-show="editing" x-cloak>
                    <x-button variant="quiet" size="sm" icon="reset" :loading="false" x-on:click="reset()">{{ __('dashboard.home.reset') }}</x-button>
                    <x-button variant="outline" size="sm" icon="plus" :loading="false" x-ref="add"
                              x-on:click="openGallery()" x-bind:disabled="addable().length === 0">{{ __('dashboard.home.add') }}</x-button>
                    <x-button size="sm" icon="check" :loading="false" x-ref="done" x-on:click="done()">{{ __('dashboard.home.done') }}</x-button>
                </span>
            </div>
        </div>

        {{-- The groups (they filter the grid), and beside them Customise and Reset to
             default: the layout only (sizes and places, with Undo) or everything (asks first). --}}
        <div x-show="! editing" class="flex items-start gap-2">
            <div class="min-w-0 flex-1">
                <livewire:dashboard.hub />
            </div>
            <button type="button" x-ref="customise" x-on:click="startEdit()" data-tour="dashboard.customise"
                    class="flex h-8 shrink-0 items-center gap-2 rounded-full border border-line-soft bg-card px-3 text-small font-semibold text-ink hover:bg-hover">
                <x-ic name="layout" class="size-4 text-ink-2" />
                <span class="max-sm:sr-only">{{ __('dashboard.home.customise') }}</span>
            </button>
            <div class="relative shrink-0" x-on:click.outside="resetMenu = false" x-on:keydown.escape.stop="resetMenu = false; $refs.resetButton.focus()">
                <button type="button" x-ref="resetButton" data-testid="dashboard-reset-menu"
                        x-on:click="resetMenu = ! resetMenu" aria-haspopup="menu" aria-controls="hc-reset-menu"
                        :aria-expanded="resetMenu.toString()"
                        class="flex h-8 items-center gap-2 rounded-full border border-line-soft bg-card px-3 text-small font-semibold text-ink hover:bg-hover">
                    <x-ic name="reset" class="size-4 text-ink-2" />
                    <span class="max-sm:sr-only">{{ __('dashboard.home.reset-menu.label') }}</span>
                </button>
                <div id="hc-reset-menu" role="menu" x-show="resetMenu" x-cloak
                     aria-label="{{ __('dashboard.home.reset-menu.label') }}"
                     class="absolute end-0 top-full z-30 mt-2 w-[min(20rem,calc(100vw-2rem))] rounded-surface border border-line bg-card p-1.5 shadow-e3">
                    <button type="button" role="menuitem" data-testid="dashboard-reset-layout" x-on:click="resetArrangement()"
                            class="block w-full rounded-control px-3 py-2 text-start hover:bg-hover">
                        <span class="block text-small font-bold text-ink">{{ __('dashboard.home.reset-menu.layout') }}</span>
                        <span class="block text-caption text-ink-2">{{ __('dashboard.home.reset-menu.layout-hint') }}</span>
                    </button>
                    <button type="button" role="menuitem" data-testid="dashboard-reset-all" x-on:click="resetAll()"
                            class="block w-full rounded-control px-3 py-2 text-start hover:bg-hover">
                        <span class="block text-small font-bold text-ink">{{ __('dashboard.home.reset-menu.all') }}</span>
                        <span class="block text-caption text-ink-2">{{ __('dashboard.home.reset-menu.all-hint') }}</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Ready-made layouts (DashboardLayout::PRESETS), while customising. --}}
        <section class="mb-4" x-show="editing" x-cloak aria-labelledby="hc-presets-title" data-testid="dashboard-presets">
            <h2 id="hc-presets-title" class="mb-2 text-body font-bold text-ink">{{ __('dashboard.home.presets.title') }}</h2>
            <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                @foreach ($presets as $id => $keys)
                    <button type="button" data-preset="{{ $id }}" x-on:click="applyPreset(@js($id))"
                            aria-pressed="{{ $activePreset === $id ? 'true' : 'false' }}"
                            @class([
                                'rounded-surface border p-3 text-start hover:bg-hover',
                                'border-ink bg-hover' => $activePreset === $id,
                                'border-line-soft bg-card' => $activePreset !== $id,
                            ])>
                        <span class="block text-body font-bold text-ink">{{ __('dashboard.home.presets.'.$id.'.name') }}</span>
                        <span class="block text-small text-ink-2">{{ __('dashboard.home.presets.'.$id.'.body') }}</span>
                    </button>
                @endforeach
            </div>
        </section>

        {{-- The phone bar's shortcuts (prompt 10), chosen here while customising on a
             phone. Lazy: it loads only once it is on screen, so never on a computer
             and never at rest. It saves each change itself (bottom-bar-changed). --}}
        <div class="mb-4 md:hidden" x-show="editing" x-cloak data-slot="bottom-bar-picker">
            <livewire:layout.bottom-bar-picker lazy />
        </div>

        {{-- The strip under the groups: minimised cards, cards made smaller than their minimum, and
             shortcuts, each as its own icon. One click brings a card back in its place (a shrunk one
             at its minimum card size); a shortcut opens its page. --}}
        <section class="hc-dock" x-ref="dock" aria-label="{{ __('dashboard.home.minimised') }}" data-testid="dashboard-dock">
            <ul class="hc-dock-list">
                <template x-for="it in docked()" :key="it.key">
                    <li class="hc-dock-entry">
                        <button type="button" class="hc-dock-item" :data-key="it.key" x-on:click="dockClick(it.key)"
                                :aria-label="isShortcut(it.key) ? labelOf(it.key) : @js(__('dashboard.home.restore', ['tile' => '__TILE__'])).replace('__TILE__', labelOf(it.key))">
                            <span class="hc-dock-icon" aria-hidden="true" x-html="dockIcon(it.key)"></span>
                            <span x-text="labelOf(it.key)"></span>
                        </button>
                        {{-- Customising: what waits in the strip can be taken off the dashboard here. --}}
                        <button type="button" class="hc-dock-remove" x-show="editing" x-cloak x-on:click="remove(it.key)"
                                :aria-label="@js(__('dashboard.home.hide', ['tile' => '__TILE__'])).replace('__TILE__', labelOf(it.key))">
                            <x-ic name="x" />
                        </button>
                    </li>
                </template>
            </ul>
        </section>

        {{-- The grid. Tiles are drawn once by the server (a hidden one not at all);
             moving, resizing and hiding only change them in place. --}}
        <div class="hc-home" data-drop="{{ __('dashboard.add.drop') }}">
            {{-- Faint cells behind the tiles while customising, so you can see where a tile will sit. --}}
            <div class="hc-grid hc-guides" x-ref="guides" x-show="editing" x-cloak aria-hidden="true"></div>

            <div class="hc-grid" x-ref="grid" role="list" aria-label="{{ __('general.dashboard') }}"
                 :class="{ editing, wiggle: wiggling, 'is-in': revealed }"
                 x-on:pointerdown="press($event)" x-on:pointermove="pressMove($event)"
                 x-on:pointerup="release()" x-on:pointerleave="release()" x-on:pointercancel="release()"
                 x-on:click.capture="swallowClick($event)"
                 x-on:contextmenu="if (editing || pressed) $event.preventDefault()">
                @foreach ($layout as $item)
                    @continue($item['hidden'] || isset($absentKeys[$item['key']]))
                    @include('livewire.dashboard.partials.tile', ['item' => $item])
                @endforeach
            </div>
        </div>

        {{-- Filtered to a hub group that has no card here: say so, with the way back. --}}
        <div class="hc-filter-empty" x-show="group && ! editing && filteredCount() === 0" x-cloak data-testid="dashboard-filter-empty">
            <p class="text-small text-ink-2">{{ __('dashboard.home.filter-empty') }}</p>
            <x-button variant="outline" size="sm" :loading="false" x-on:click="showAll()">{{ __('dashboard.hub.show-all') }}</x-button>
        </div>

        <p id="hc-tile-hint" class="sr-only">{{ __('dashboard.home.tile-hint') }}</p>
        <p id="hc-page-hint" class="sr-only">{{ __('dashboard.home.page-hint') }}</p>
        <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>

        {{-- A tile whose card could not load at all (the script puts this inside it). --}}
        <template x-ref="tileError">
            <div class="flex h-full flex-col items-center justify-center gap-3 rounded-surface border-[1.5px] border-dashed border-line bg-card p-4 text-center">
                <p class="text-body font-bold text-ink">{{ __('general.state-error-title') }}</p>
                <x-button size="sm" :loading="false" x-on:click="window.location.reload()">{{ __('general.retry') }}</x-button>
            </div>
        </template>

        {{-- Add: what can come back. A bottom sheet on phones, a panel on larger screens. --}}
        <div class="hc-sheet-back" x-show="gallery" x-cloak x-transition.opacity x-on:click.self="closeGallery()">
            <div class="hc-sheet" role="dialog" aria-modal="true" aria-labelledby="hc-gallery-title"
                 x-show="gallery" x-trap.noscroll="gallery" x-on:keydown.escape.stop.prevent="closeGallery()">
                <div class="flex items-center justify-between gap-3 border-b border-line-soft px-5 py-3">
                    <h2 id="hc-gallery-title" class="text-h4 font-extrabold text-ink">{{ __('dashboard.home.add-title') }}</h2>
                    <x-button variant="quiet" size="sm" icon="x" :loading="false" x-on:click="closeGallery()" aria-label="{{ __('dashboard.home.close') }}" />
                </div>

                <div class="overflow-y-auto p-3">
                    <template x-if="gallery">
                        <div class="grid gap-4">
                            @foreach (['widget' => 'cards', 'app' => 'shortcuts'] as $kind => $heading)
                                <section x-show="addable(@js($kind)).length > 0" aria-labelledby="hc-gallery-{{ $kind }}">
                                    <h3 id="hc-gallery-{{ $kind }}" class="px-2 pb-1 text-th font-bold text-ink-2">{{ __('dashboard.home.'.$heading) }}</h3>
                                    <div class="grid gap-1">
                                        @foreach ($layout as $item)
                                            @continue(isset($absentKeys[$item['key']]) || $tiles->isApp($item['key']) !== ($kind === 'app'))
                                            <button type="button" class="hc-galitem" x-show="hidden(@js($item['key']))" x-on:click="add(@js($item['key']))">
                                                <span class="hc-appicon"><x-dashboard.tile-icon :name="$tiles->icon($item['key'], $user)" /></span>
                                                <span class="min-w-0 flex-1 font-bold">{{ $tiles->label($item['key'], $user) }}</span>
                                                <x-ic name="plus" class="text-ink-2" />
                                            </button>
                                        @endforeach
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    @include('livewire.dashboard.partials.workspace')
</div>
