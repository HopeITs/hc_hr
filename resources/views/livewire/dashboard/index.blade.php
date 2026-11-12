@php
    use App\Support\Dashboard\DashboardLayout;

    // The desk's script (resources/js/dashboard/home-screen.js) gets the whole
    // layout, hidden panels included (Customize offers them), and keeps it in
    // data-home: after a ready layout or Reset re-renders the page, the script
    // takes the new layout from there.
    $absentKeys = array_flip($absent);
    $home = [
        'layout' => $layout,
        'absent' => $absent,
        'bounds' => collect($layout)->mapWithKeys(fn (array $item) => [$item['key'] => $tiles->bounds($item['key'])])->all(),
        'looks' => DashboardLayout::LOOKS,
        'phoneHidden' => DashboardLayout::PHONE_HIDDEN,
        'labels' => collect($layout)->mapWithKeys(fn (array $item) => [$item['key'] => $tiles->label($item['key'])])->all(),
        'customise' => $customise,
        'text' => [
            'size' => __('dashboard.home.size'),
            'moved' => __('dashboard.home.moved'),
            'resized' => __('dashboard.home.resized'),
            'removed' => __('dashboard.home.removed'),
            'added' => __('dashboard.home.added'),
            'resetTitle' => __('dashboard.home.reset-title'),
            'resetMessage' => __('dashboard.home.reset-message'),
            'resetConfirm' => __('dashboard.home.reset'),
            'cancel' => __('general.cancel'),
            'deskApplied' => __('dashboard.desk.applied'),
        ],
    ];
    $shownKeys = array_column(array_filter($layout, fn (array $item) => ! $item['hidden'] && ! isset($absentKeys[$item['key']])), 'key');
    $addable = count(array_filter($layout, fn (array $item) => $item['hidden'] && ! isset($absentKeys[$item['key']])));
@endphp

<x-slot:title>
    {{ __('general.dashboard') }}
</x-slot:title>

{{-- The desk's script, on this page only (a separate Vite entry): moving, resizing and removing panels in place. --}}
@section('css')
    @vite('resources/js/dashboard/home-screen.js')
@endsection

<div>
    {{-- The hello: date, greeting, the day in two figures, the command strip (Customize included).
         Its own component, so the grid's renders (Customize, Reset) never recompute it. --}}
    <livewire:dashboard.header :name="(string) ($employee?->first_name ?? '')"
                               :inbox-placed="in_array('decide', $shownKeys, true)" />

    <div class="hc-home-screen"
         x-data="hcHomeScreen"
         data-home="{{ json_encode($home, JSON_UNESCAPED_UNICODE) }}"
         data-desk-default="{{ array_key_first($presets) }}"
         x-on:hc-desk-open.window="openDesk($event.detail?.tab || 'layouts')"
         x-on:keydown.escape.window="escape($event)">

        {{-- The grid. Panels are drawn once by the server (a hidden one not at all);
             moving, resizing and removing only change them in place. --}}
        <div class="hc-home">
            <div class="hc-grid" x-ref="grid" role="list" aria-label="{{ __('general.dashboard') }}"
                 x-on:click.capture="swallowClick($event)"
                 x-on:contextmenu="if (carrying) $event.preventDefault()">
                @foreach ($layout as $item)
                    @continue($item['hidden'] || isset($absentKeys[$item['key']]))
                    @include('livewire.dashboard.partials.tile', ['item' => $item])
                @endforeach
            </div>

            {{-- Outside the dense grid so it is never packed into a gap: always last, and only while a panel
                 is left to add. Counted in the browser, so removing a panel shows it at once. --}}
            @php($moreForms = collect(range(1, max(1, count($layout))))->mapWithKeys(fn (int $n) => [$n => trans_choice('dashboard.home.more-available', $n, ['count' => $n])]))
            <button type="button" class="hc-addtile" x-on:click="openDesk('panels')" data-testid="dashboard-add-panel"
                    x-data="{ forms: @js($moreForms) }"
                    x-show="items.filter((i) => i.hidden && ! absent.includes(i.key)).length > 0"
                    @if ($addable === 0) x-cloak @endif>
                <x-ic name="plus" />
                <span>{{ __('dashboard.home.add-panel') }}</span>
                <small x-text="forms[items.filter((i) => i.hidden && ! absent.includes(i.key)).length] || ''">{{ $addable > 0 ? $moreForms[$addable] : '' }}</small>
            </button>
        </div>

        <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>

        {{-- A panel just removed from its menu: Undo puts it back where it was, for a few seconds. --}}
        <div class="hc-undo" role="status" aria-live="polite" x-show="removed" x-cloak x-transition.opacity>
            <span class="hc-undo-text" x-text="removed ? text.removed.replace(':tile', removed.label) : ''"></span>
            <x-button variant="outline" size="sm" icon="return" :loading="false" x-on:click="undoRemove()">{{ __('dashboard.home.undo') }}</x-button>
            <button type="button" class="hc-undo-close" x-on:click="dismissUndo()" aria-label="{{ __('dashboard.home.close') }}">
                <x-ic name="x" />
            </button>
        </div>

        {{-- A panel whose card could not load at all (the script puts this inside it). --}}
        <template x-ref="tileError">
            <div class="flex h-full flex-col items-center justify-center gap-3 rounded-surface border-[1.5px] border-dashed border-line bg-card p-4 text-center">
                <p class="text-body font-bold text-ink">{{ __('general.state-error-title') }}</p>
                <x-button size="sm" :loading="false" x-on:click="window.location.reload()">{{ __('general.retry') }}</x-button>
            </div>
        </template>

        @include('livewire.dashboard.partials.desk')
    </div>

    {{-- Outside the grid: a tile clips what overflows it, so the dialog cannot live in the calendar card. --}}
    @if ($canRequestLeave)
        <livewire:employee.leave.leave-request-dialog />
    @endif
</div>
