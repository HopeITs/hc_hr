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
        'labels' => collect($layout)->mapWithKeys(fn (array $item) => [$item['key'] => $tiles->label($item['key'], $user)])->all(),
        'editing' => $customise,
        'text' => [
            'tile' => __('dashboard.home.tile'),
            'size' => __('dashboard.home.size'),
            'resize' => __('dashboard.home.resize'),
            'moved' => __('dashboard.home.moved'),
            'resized' => __('dashboard.home.resized'),
            'hidden' => __('dashboard.home.hidden'),
            'added' => __('dashboard.home.added'),
            'pinned' => __('dashboard.home.pinned'),
            'unpinned' => __('dashboard.home.unpinned'),
            'resetTitle' => __('dashboard.home.reset-title'),
            'resetMessage' => __('dashboard.home.reset-message'),
            'resetConfirm' => __('dashboard.home.reset'),
            'cancel' => __('general.cancel'),
            'nothing' => __('dashboard.home.nothing'),
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
    {{-- Prompt 7: the greeting and a headline that says what waits on the viewer.
         What is late and the decisions are tiles in the grid ("late", "decide").
         Its own component, so the grid's renders (Add, Reset) never recompute it.
         The name and job title are the ones mount() already loaded. --}}
    <livewire:dashboard.header :name="(string) ($employee?->first_name ?? '')"
                               :job-title="$positionTitle === '—' ? '' : (string) $positionTitle" />

    <div class="hc-home-screen"
         x-data="hcHomeScreen"
         data-home="{{ json_encode($home, JSON_UNESCAPED_UNICODE) }}"
         x-on:keydown.escape.window="escape($event)">

        {{-- Customise, and while customising: Reset, Add, Done. The words need about
             16rem: where the buttons leave less (a phone), they sit above them, full width. --}}
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="min-w-0 flex-[1_1_16rem] text-hint text-ink-2" aria-live="polite">
                <span x-show="! editing">{{ __('dashboard.home.lead') }}</span>
                <span x-show="editing" x-cloak>{{ __('dashboard.home.edit-lead') }}</span>
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" x-ref="customise" class="hc-btn hc-btn--outline hc-btn--sm"
                        x-show="! editing" x-on:click="startEdit()" data-tour="dashboard.customise">
                    <x-ic name="layout" />{{ __('dashboard.home.customise') }}
                </button>

                <span class="flex flex-wrap items-center gap-2" x-show="editing" x-cloak>
                    <button type="button" class="hc-btn hc-btn--quiet hc-btn--sm" x-on:click="reset()">
                        <x-ic name="reset" />{{ __('dashboard.home.reset') }}
                    </button>
                    <button type="button" x-ref="add" class="hc-btn hc-btn--outline hc-btn--sm"
                            x-on:click="openGallery()" :disabled="addable().length === 0">
                        <x-ic name="plus" />{{ __('dashboard.home.add') }}
                    </button>
                    <button type="button" x-ref="done" class="hc-btn hc-btn--primary hc-btn--sm" x-on:click="done()">
                        <x-ic name="check" />{{ __('dashboard.home.done') }}
                    </button>
                </span>
            </div>
        </div>

        {{-- The phone bar's shortcuts (prompt 10), chosen here while customising on a
             phone. Lazy: it loads only once it is on screen, so never on a computer
             and never at rest. It saves each change itself (bottom-bar-changed). --}}
        <div class="mb-4 md:hidden" x-show="editing" x-cloak data-slot="bottom-bar-picker">
            <livewire:layout.bottom-bar-picker lazy />
        </div>

        {{-- The grid. Tiles are drawn once by the server (a hidden one not at all);
             moving, resizing and hiding only change them in place. --}}
        <div class="hc-home">
            {{-- Faint cells behind the tiles while customising, so you can see where a tile will sit. --}}
            <div class="hc-grid hc-guides" x-ref="guides" x-show="editing" x-cloak aria-hidden="true"></div>

            <div class="hc-grid" x-ref="grid" role="list" aria-label="{{ __('general.dashboard') }}"
                 :class="{ editing, wiggle: wiggling }"
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

        <p id="hc-tile-hint" class="sr-only">{{ __('dashboard.home.tile-hint') }}</p>
        <p id="hc-page-hint" class="sr-only">{{ __('dashboard.home.page-hint') }}</p>
        <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>

        {{-- A tile whose card could not load at all (the script puts this inside it). --}}
        <template x-ref="tileError">
            <div class="flex h-full flex-col items-center justify-center gap-3 rounded-surface border-[1.5px] border-dashed border-line bg-card p-4 text-center">
                <p class="text-body font-bold text-ink">{{ __('general.state-error-title') }}</p>
                <button type="button" class="hc-btn hc-btn--primary hc-btn--sm" x-on:click="window.location.reload()">{{ __('general.retry') }}</button>
            </div>
        </template>

        {{-- Add: what can come back. A bottom sheet on phones, a panel on larger screens. --}}
        <div class="hc-sheet-back" x-show="gallery" x-cloak x-transition.opacity x-on:click.self="closeGallery()">
            <div class="hc-sheet" role="dialog" aria-modal="true" aria-labelledby="hc-gallery-title"
                 x-show="gallery" x-trap.noscroll="gallery" x-on:keydown.escape.stop.prevent="closeGallery()">
                <div class="flex items-center justify-between gap-3 border-b border-line-soft px-5 py-3">
                    <h2 id="hc-gallery-title" class="text-h4 font-extrabold text-ink">{{ __('dashboard.home.add-title') }}</h2>
                    <button type="button" class="hc-btn hc-btn--quiet hc-btn--sm" x-on:click="closeGallery()" aria-label="{{ __('dashboard.home.close') }}">
                        <x-ic name="x" />
                    </button>
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
</div>
