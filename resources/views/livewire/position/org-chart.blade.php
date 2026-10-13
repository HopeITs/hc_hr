@php
    $compact = $density === 'compact';
    $isTech = $manager_type === 'tech';
    // Structural connectors mirror the active line. The technical line uses a
    // dashed neutral, the direct line a solid neutral — the dash *pattern*
    // (not colour) carries the meaning, keeping it colour-blind safe and on-brand.
    $trunk = $isTech ? 'border-s border-dashed border-neutral-400' : 'bg-neutral-300';
    $branchBorder = $isTech ? 'border-dashed border-neutral-400' : 'border-neutral-300';
    $colGap = $compact ? 'gap-3' : 'gap-6';
@endphp

<x-slot:title>
    {{ __('general.orgn-chart') }}
</x-slot:title>

<div class="pb-8 space-y-4"
     x-data="{
        filtersOpen: false,
        exporting: false,
        async exportChart(format) {
            const node = document.getElementById('org-chart-export')
            if (!node || this.exporting) return
            this.exporting = true
            try {
                const { toPng } = await import('https://esm.sh/html-to-image@1.11.11')
                const dataUrl = await toPng(node, { backgroundColor: '#ffffff', pixelRatio: 2, cacheBust: true })
                if (format === 'png') {
                    const a = document.createElement('a')
                    a.href = dataUrl; a.download = 'org-chart.png'; a.click()
                } else {
                    const { jsPDF } = await import('https://esm.sh/jspdf@2.5.1')
                    const img = new Image(); img.src = dataUrl
                    await new Promise(r => { img.onload = r })
                    const pdf = new jsPDF({ orientation: img.width >= img.height ? 'l' : 'p', unit: 'pt', format: [img.width, img.height] })
                    pdf.addImage(dataUrl, 'PNG', 0, 0, img.width, img.height)
                    pdf.save('org-chart.pdf')
                }
            } catch (e) {
                console.error('Org chart export failed', e)
            } finally {
                this.exporting = false
            }
        }
     }"
     x-on:keydown.escape.window="filtersOpen = false">

    <section class="isolate overflow-hidden bg-white border border-neutral-200 shadow-e1 rounded-surface">
        {{-- ---------- Header + stats ---------- --}}
        <div class="relative px-6 py-6 text-white bg-secondary-dark">
            <div class="relative flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <div class="flex items-center gap-2 mb-2 text-caption font-semibold uppercase tracking-wider text-white/70">
                        <x-icons.code-fork class="size-4" />
                        @lang('general.organization-overview')
                    </div>
                    <h1 class="text-h3 font-bold leading-tight tracking-tight sm:text-h2">@lang('general.orgn-chart')</h1>
                    <p class="max-w-2xl mt-2 text-body leading-relaxed text-white/75">@lang('general.org-chart-subtitle')</p>
                </div>

                <div data-tour="org.stats" class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach([
                        ['value' => $stats['positions'], 'label' => __('general.total-positions'), 'accent' => false],
                        ['value' => $stats['employees'], 'label' => __('general.filled-positions'), 'accent' => false],
                        ['value' => $stats['vacancies'], 'label' => __('general.vacant-positions'), 'accent' => true],
                        ['value' => $stats['managers'], 'label' => __('general.top-level-managers'), 'accent' => false],
                    ] as $stat)
                        {{-- Uniform neutral cards; the vacancies card carries a flat red
                             accent bar because vacancies are the actionable metric. --}}
                        <div class="relative min-w-28 overflow-hidden rounded-surface border border-white/10 bg-white/10 px-4 py-3">
                            @if($stat['accent'])
                                <span class="absolute inset-y-0 start-0 w-1 bg-primary" aria-hidden="true"></span>
                            @endif
                            <div class="text-h3 font-bold tabular-nums">{{ number_format($stat['value']) }}</div>
                            <div class="mt-1 text-caption font-medium leading-snug text-white/80">{{ $stat['label'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ---------- View switcher ---------- --}}
        <div data-tour="org.views" class="flex items-center gap-1 px-4 pt-3 bg-white border-b border-neutral-200">
            <button type="button" wire:click="$set('view', 'chart')"
                    class="flex items-center gap-2 rounded-t-xl border-b-2 px-4 py-2 text-body font-semibold transition {{ $view === 'chart' ? 'border-primary text-primary' : 'border-transparent text-secondary-light hover:text-secondary-dark' }}">
                <x-icons.code-fork class="size-4" />
                @lang('general.chart-view')
            </button>
            <button type="button" wire:click="$set('view', 'directory')"
                    class="flex items-center gap-2 rounded-t-xl border-b-2 px-4 py-2 text-body font-semibold transition {{ $view === 'directory' ? 'border-primary text-primary' : 'border-transparent text-secondary-light hover:text-secondary-dark' }}">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M3 12h18M3 19h18"/></svg>
                @lang('general.directory-view')
            </button>
        </div>

        @if($view === 'directory')
            <livewire:position.directory />
        @else
        {{-- ---------- Slim sticky toolbar ---------- --}}
        <div class="sticky top-0 z-20 flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 bg-white/95 px-4 py-2 backdrop-blur">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative" data-tour="org.search">
                    <span class="pointer-events-none absolute inset-y-0 start-2.5 flex items-center text-secondary-light">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3-3"/>
                        </svg>
                    </span>
                    <input type="search" wire:model.live.debounce.350ms="search"
                           placeholder="{{ __('general.search-name-or-position') }}"
                           class="py-2 text-small font-medium border border-neutral-200 w-52 rounded-surface bg-neutral-50 ps-8 pe-3 text-secondary-dark placeholder:text-secondary-light focus:border-secondary-light focus:bg-white focus:ring-2 focus:ring-secondary-soft" />
                    @if($this->isSearching)
                        <button type="button" wire:click="$set('search', '')"
                                class="absolute inset-y-0 flex items-center transition end-2 text-secondary-light hover:text-primary">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/>
                            </svg>
                        </button>
                    @endif
                </div>

                <button type="button" x-on:click="filtersOpen = true" data-tour="org.filters"
                        class="relative flex items-center gap-2 px-3 py-2 text-small font-semibold text-white transition rounded-surface bg-secondary-dark hover:bg-secondary-medium">
                    <x-icons.cog class="size-4" />
                    @lang('general.filters')
                    @if($this->activeFilterCount > 0)
                        <span class="flex size-5 items-center justify-center rounded-full bg-primary text-caption font-bold">
                            {{ $this->activeFilterCount }}
                        </span>
                    @endif
                </button>

                <button type="button" wire:click="toggleDensity"
                        class="flex items-center gap-2 rounded-surface border border-neutral-200 bg-white px-3 py-2 text-small font-semibold text-secondary-medium transition hover:border-secondary-light hover:bg-secondary-soft hover:text-primary">
                    <x-icons.code-fork class="size-3.5" />
                    {{ $compact ? __('general.comfortable-view') : __('general.compact-view') }}
                </button>

                {{-- Goes solid red once vacancies are hidden, so the chart never
                     silently omits cards without the toolbar saying so. --}}
                <button type="button" wire:click="toggleVacancies" data-tour="org.vacancies"
                        aria-pressed="{{ $showVacancies ? 'false' : 'true' }}"
                        @unless($showVacancies) title="{{ __('general.vacancies-kept-help') }}" @endunless
                        class="flex items-center gap-2 rounded-surface border px-3 py-2 text-small font-semibold transition {{ $showVacancies ? 'border-neutral-200 bg-white text-secondary-medium hover:border-secondary-light hover:bg-secondary-soft hover:text-primary' : 'border-primary bg-primary text-white hover:opacity-90' }}">
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        @if($showVacancies)
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"/>
                            <circle cx="12" cy="12" r="2.5"/>
                        @else
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 6.2A9.9 9.9 0 0 1 12 6c6.4 0 10 6 10 6a17 17 0 0 1-3.3 3.9M6.5 8.1A17 17 0 0 0 2 12s3.6 6 10 6a9.8 9.8 0 0 0 3.3-.5"/>
                        @endif
                    </svg>
                    {{ $showVacancies ? __('general.hide-vacancies') : __('general.show-vacancies') }}
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2" data-tour="org.controls">
                <div class="flex items-center overflow-hidden border border-neutral-200 rounded-surface bg-neutral-50">
                    <button type="button" wire:click="zoomOut" title="{{ __('general.zoom-out') }}"
                            class="flex items-center justify-center transition size-8 text-secondary-medium hover:bg-white hover:text-primary">
                        <span class="text-h4 font-bold">-</span>
                    </button>
                    <span class="min-w-12 border-x border-neutral-200 bg-white px-1 py-2 text-center text-small font-semibold tabular-nums text-secondary-dark">
                        {{ round($scale * 100) }}%
                    </span>
                    <button type="button" wire:click="zoomIn" title="{{ __('general.zoom-in') }}"
                            class="flex items-center justify-center transition size-8 text-secondary-medium hover:bg-white hover:text-primary">
                        <span class="text-h4 font-bold">+</span>
                    </button>
                </div>
                <button type="button" wire:click="fitToScreen"
                        class="px-3 py-2 text-small font-semibold transition bg-white border border-neutral-200 rounded-surface text-secondary-medium hover:border-secondary-light hover:bg-secondary-soft hover:text-primary">
                    @lang('general.fit-to-screen')
                </button>
                <button type="button" wire:click="expandAll"
                        class="px-3 py-2 text-small font-semibold transition bg-white border border-neutral-200 rounded-surface text-secondary-medium hover:bg-neutral-50">
                    @lang('general.expand-all')
                </button>
                <button type="button" wire:click="collapseAll"
                        class="px-3 py-2 text-small font-semibold text-white transition rounded-surface bg-primary hover:opacity-90">
                    @lang('general.collapse-all')
                </button>

                {{-- Export menu --}}
                <div class="relative" x-data="{ menu: false }" x-on:click.outside="menu = false" data-tour="org.export">
                    <button type="button" x-on:click="menu = !menu" :disabled="exporting"
                            class="flex items-center gap-2 rounded-surface border border-neutral-200 bg-white px-3 py-2 text-small font-semibold text-secondary-medium transition hover:border-secondary-light hover:bg-secondary-soft hover:text-primary disabled:opacity-60">
                        <svg x-show="!exporting" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                        <x-icons.spinner x-show="exporting" x-cloak class="size-4 animate-spin" />
                        @lang('general.export')
                    </button>
                    <div x-show="menu" x-cloak x-transition
                         class="absolute z-30 py-1 mt-1 overflow-hidden bg-white border border-neutral-200 shadow-e3 end-0 w-36 rounded-surface">
                        <button type="button" x-on:click="menu = false; exportChart('png')"
                                class="flex items-center w-full gap-2 px-3 py-2 text-small font-semibold transition text-secondary-dark hover:bg-secondary-soft hover:text-primary">
                            @lang('general.export-png')
                        </button>
                        <button type="button" x-on:click="menu = false; exportChart('pdf')"
                                class="flex items-center w-full gap-2 px-3 py-2 text-small font-semibold transition text-secondary-dark hover:bg-secondary-soft hover:text-primary">
                            @lang('general.export-pdf')
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ---------- Canvas ---------- --}}
        <div class="relative">
            <div wire:loading.flex
                 wire:target="city_id,program_id,center_id,department_id,job_level_id,focus_manager_id,manager_type,search,resetFilters,toggleDensity"
                 class="absolute inset-0 z-30 items-center justify-center bg-white/75 backdrop-blur-[1px]">
                <div class="flex items-center gap-2 px-4 py-3 text-body font-semibold bg-white border border-neutral-200 shadow-e3 rounded-surface text-secondary-medium">
                    <x-icons.spinner class="size-5 animate-spin text-primary" />
                    @lang('general.updating-chart')
                </div>
            </div>

            @if($this->isSearching)
                {{-- Flat search results grid (hierarchy is not meaningful while searching). --}}
                @php($results = $this->searchResults)
                <div class="min-h-[680px] w-full bg-[radial-gradient(circle_at_center,_var(--color-secondary-soft)_1px,_transparent_1px)] [background-size:22px_22px]">
                    <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-neutral-200 bg-white/80">
                        <span class="text-body font-semibold text-secondary-medium">
                            {{ trans_choice('general.search-results-count', $results->count(), ['count' => $results->count()]) }}
                            <span class="text-secondary-light">— "{{ $search }}"</span>
                        </span>
                        <button type="button" wire:click="$set('search', '')"
                                class="rounded-surface px-3 py-2 text-small font-semibold text-primary transition hover:bg-secondary-soft">
                            @lang('general.clear-search')
                        </button>
                    </div>

                    @if($results->isEmpty())
                        <div class="flex min-h-[560px] items-center justify-center p-6">
                            <div class="max-w-md px-8 py-12 text-center border border-neutral-300 border-dashed shadow-e1 rounded-surface bg-white/90">
                                <span class="flex items-center justify-center mx-auto size-14 rounded-surface bg-secondary-soft text-primary">
                                    <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3-3"/>
                                    </svg>
                                </span>
                                <h2 class="mt-4 text-h4 font-semibold text-secondary-dark">@lang('general.no-search-results')</h2>
                                <p class="max-w-xs mx-auto mt-2 text-body leading-relaxed text-secondary-light">@lang('general.no-search-results-help')</p>
                            </div>
                        </div>
                    @else
                        <div class="flex flex-wrap justify-center gap-4 p-6">
                            @foreach($results as $result)
                                @if($result->activeContracts->isNotEmpty())
                                    @foreach($result->activeContracts as $contract)
                                        <x-org-node :position="$result" :employee="$contract->employee" :manager-type="$manager_type" :compact="$compact"/>
                                    @endforeach
                                @else
                                    <x-org-empty-node :position="$result" :compact="$compact"/>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
            <div
                x-ref="viewport"
                data-tour="org.canvas"
                x-data="{
                    scale: @entangle('scale').live,
                    translateX: 0,
                    translateY: 0,
                    dragging: false,
                    pointerId: null,
                    lastX: 0,
                    lastY: 0,
                    lastTime: 0,
                    velocityX: 0,
                    velocityY: 0,
                    inertiaId: null,

                    transformStyle() {
                        return `transform: translate3d(${this.translateX}px, ${this.translateY}px, 0) scale(${this.scale})`;
                    },

                    stopInertia() {
                        if (this.inertiaId) cancelAnimationFrame(this.inertiaId);
                        this.inertiaId = null;
                    },

                    startInertia() {
                        const step = () => {
                            this.velocityX *= 0.9;
                            this.velocityY *= 0.9;
                            if (Math.abs(this.velocityX) < 0.25 && Math.abs(this.velocityY) < 0.25) {
                                this.inertiaId = null;
                                return;
                            }
                            this.translateX += this.velocityX;
                            this.translateY += this.velocityY;
                            this.inertiaId = requestAnimationFrame(step);
                        };
                        if (Math.abs(this.velocityX) > 0.4 || Math.abs(this.velocityY) > 0.4) {
                            this.inertiaId = requestAnimationFrame(step);
                        }
                    },

                    zoomAt(clientX, clientY, delta) {
                        const rect = this.$refs.viewport.getBoundingClientRect();
                        const x = clientX - rect.left;
                        const y = clientY - rect.top;
                        const prev = this.scale;
                        const next = Math.min(2, Math.max(0.3, +(prev + delta).toFixed(2)));
                        if (next === prev) return;
                        const ratio = next / prev;
                        this.translateX = x - (x - this.translateX) * ratio;
                        this.translateY = y - (y - this.translateY) * ratio;
                        this.scale = next;
                    },

                    onWheel(event) {
                        event.preventDefault();
                        this.stopInertia();
                        if (event.ctrlKey || event.metaKey) {
                            const delta = event.deltaY < 0 ? 0.06 : -0.06;
                            this.zoomAt(event.clientX, event.clientY, delta);
                            return;
                        }
                        const factor = event.deltaMode === 1 ? 16 : 1;
                        this.translateX -= event.deltaX * factor;
                        this.translateY -= event.deltaY * factor;
                    },

                    onPointerDown(event) {
                        if (event.button !== 0 || event.target.closest('button, a, input, select, textarea, [data-no-pan]')) return;
                        this.stopInertia();
                        this.dragging = true;
                        this.pointerId = event.pointerId;
                        this.lastX = event.clientX;
                        this.lastY = event.clientY;
                        this.lastTime = performance.now();
                        this.velocityX = 0;
                        this.velocityY = 0;
                        this.$refs.viewport.setPointerCapture(event.pointerId);
                    },

                    onPointerMove(event) {
                        if (!this.dragging || event.pointerId !== this.pointerId) return;
                        const now = performance.now();
                        const dt = Math.max(now - this.lastTime, 1);
                        const dx = event.clientX - this.lastX;
                        const dy = event.clientY - this.lastY;
                        this.translateX += dx;
                        this.translateY += dy;
                        this.velocityX = (dx / dt) * 16;
                        this.velocityY = (dy / dt) * 16;
                        this.lastX = event.clientX;
                        this.lastY = event.clientY;
                        this.lastTime = now;
                    },

                    onPointerUp(event) {
                        if (!this.dragging || event.pointerId !== this.pointerId) return;
                        this.dragging = false;
                        this.$refs.viewport.releasePointerCapture(event.pointerId);
                        this.startInertia();
                    },

                    resetPan() {
                        this.stopInertia();
                        this.translateX = 0;
                        this.translateY = 0;
                    },
                }"
                @wheel.prevent="onWheel($event)"
                @pointerdown="onPointerDown($event)"
                @pointermove="onPointerMove($event)"
                @pointerup="onPointerUp($event)"
                @pointercancel="onPointerUp($event)"
                x-on:reset-pan.window="resetPan()"
                class="relative min-h-[min(72vh,820px)] w-full touch-none overflow-hidden overscroll-none select-none bg-[radial-gradient(circle_at_center,_var(--color-secondary-soft)_1px,_transparent_1px)] [background-size:22px_22px]"
                :class="dragging ? 'cursor-grabbing' : 'cursor-grab'"
            >
                <p class="pointer-events-none absolute bottom-3 start-3 z-10 rounded-surface border border-neutral-200/80 bg-white/90 px-2 py-1 text-caption font-medium text-secondary-light shadow-e1 backdrop-blur-sm">
                    @lang('general.org-chart-nav-hint')
                </p>
                @if(collect($tree['root'])->isEmpty())
                    <div class="absolute inset-0 flex items-center justify-center p-6">
                        <div class="max-w-md px-8 py-12 text-center border border-neutral-300 border-dashed shadow-e1 rounded-surface bg-white/90">
                            <span class="flex items-center justify-center mx-auto size-14 rounded-surface bg-secondary-soft text-primary">
                                <x-icons.code-fork class="size-7" />
                            </span>
                            <h2 class="mt-4 text-h4 font-semibold text-secondary-dark">@lang('general.no-org-chart-data')</h2>
                            <p class="max-w-xs mx-auto mt-2 text-body leading-relaxed text-secondary-light">@lang('general.no-org-chart-data-help')</p>
                        </div>
                    </div>
                @else
                    <div class="origin-top-left will-change-transform"
                         :style="transformStyle()">
                        <div id="org-chart-export" class="flex min-w-max flex-col items-center {{ $compact ? 'px-8 py-8' : 'px-16 py-12' }}">
                            {{-- Level 1 — the chief executive sits alone on the first row. --}}
                            <div class="relative flex items-end justify-center gap-4 pb-12">
                                @foreach($tree['root'] as $root)
                                    @forelse($root->activeContracts as $contract)
                                        <x-org-node :position="$root" :employee="$contract->employee" :is-manager="true" :manager-type="$manager_type" :compact="$compact"/>
                                    @empty
                                        <x-org-empty-node :position="$root" :compact="$compact"/>
                                    @endforelse
                                @endforeach

                                <span class="absolute bottom-0 start-1/2 h-10 w-px {{ $trunk }}"></span>
                            </div>

                            {{-- Level 2 — the deputy executives, as a flat row of cards. Their own
                                 reports are promoted onto level 3 by grade, so no expander here. --}}
                            @if(collect($tree['deputies'])->isNotEmpty())
                                <div class="relative flex w-full items-start justify-center {{ $colGap }} border-t {{ $branchBorder }} px-4 pb-12">
                                    @foreach($tree['deputies'] as $deputy)
                                        {{-- Same stem geometry as a top-level manager column, so the
                                             drop from level 1 lands on each card the way level 3 does. --}}
                                        <div class="relative flex flex-col items-center pt-6">
                                            <span class="absolute start-1/2 top-0 h-6 w-px {{ $trunk }}"></span>

                                            @forelse($deputy->activeContracts as $contract)
                                                <x-org-node :position="$deputy" :employee="$contract->employee" :manager-type="$manager_type" :compact="$compact"/>
                                            @empty
                                                <x-org-empty-node :position="$deputy" :compact="$compact"/>
                                            @endforelse
                                        </div>
                                    @endforeach

                                    <span class="absolute bottom-0 start-1/2 h-10 w-px {{ $trunk }}"></span>
                                </div>
                            @endif

                            {{-- Level 3 — department managers and key staff. The grade split below
                                 keeps managerial grades on this row and pools the operational
                                 grades into level 4; sub-trees expand exactly as before. --}}
                            @php($teamRel = $manager_type.'Team')
                            @php($topManagers = collect($tree['managers']))
                            @php($topBranches = $topManagers->filter(fn ($p) => $p->{$teamRel}->isNotEmpty()))
                            {{-- Only genuinely lower-grade leaves (ranked below every real manager) drop
                                 into the section below; high-grade leaves (e.g. a deputy with no reports)
                                 stay in the manager row. --}}
                            @php($maxBranchGrade = (int) $topBranches->max(fn ($p) => $p->jobLevel?->grade ?? 0))
                            @php($topLeaves = $topManagers->filter(fn ($p) => $p->{$teamRel}->isEmpty() && ($p->jobLevel?->grade ?? 999) > $maxBranchGrade))
                            @php($rowManagers = $topManagers->reject(fn ($p) => $topLeaves->contains('id', $p->id)))
                            @php($vLine = $isTech ? 'w-0 border-s border-dashed border-neutral-400' : 'w-px bg-neutral-300')
                            @php($rowGap = $compact ? 'gap-2' : 'gap-4')

                            @if($topManagers->isNotEmpty())
                                <div class="flex w-full flex-col items-center {{ $rowGap }} border-t {{ $branchBorder }} px-4">
                                    {{-- Managers (and high-grade leaves) stay on the main row. --}}
                                    @if($rowManagers->isNotEmpty())
                                        <div class="flex items-start justify-center {{ $colGap }}">
                                            @foreach($rowManagers as $manager)
                                                <x-org-manager-column
                                                    :manager="$manager"
                                                    :managerType="$manager_type"
                                                    :compact="$compact"
                                                    :collapsed="$collapsed"
                                                    :trunk="$trunk"
                                                    :branchBorder="$branchBorder"
                                                    top-level
                                                />
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- Lower-grade individual contributors pool below the manager row. --}}
                                    @if($topLeaves->isNotEmpty())
                                        <x-org-leaf-pool
                                            :positions="$topLeaves"
                                            :managerType="$manager_type"
                                            :compact="$compact"
                                            :vLine="$vLine"
                                            :gap="$rowGap"
                                            wide
                                        />
                                    @endif
                                </div>
                            @else
                                <div class="px-6 py-4 mt-8 text-body leading-relaxed bg-white border border-neutral-300 border-dashed rounded-surface text-secondary-light">
                                    @lang('general.no-data-filters')
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
            @endif
        </div>
        @endif
    </section>

    {{-- Employee profile drawer (shared by chart + directory cards). --}}
    <livewire:position.profile-drawer />

    {{-- ---------- Off-canvas filter drawer ---------- --}}
    <div x-cloak x-show="filtersOpen" class="fixed inset-0 z-40" x-transition.opacity>
        <div class="absolute inset-0 bg-secondary-dark/40 backdrop-blur-[1px]" x-on:click="filtersOpen = false"></div>

        <div class="absolute inset-y-0 flex flex-col w-full max-w-sm bg-white shadow-e3 end-0"
             x-show="filtersOpen"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-x-full rtl:-translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full rtl:-translate-x-full">

            <div class="flex items-center justify-between px-4 py-4 border-b border-neutral-200">
                <div class="flex items-center gap-2 text-body font-semibold text-secondary-dark">
                    <x-icons.cog class="size-4 text-primary" />
                    @lang('general.chart-filters')
                </div>
                <button type="button" x-on:click="filtersOpen = false"
                        class="flex items-center justify-center transition rounded-surface size-8 text-secondary-light hover:bg-neutral-100 hover:text-primary">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/>
                    </svg>
                </button>
            </div>

            <div class="flex-1 px-4 py-4 space-y-4 overflow-y-auto">
                <label class="block space-y-1">
                    <span class="text-small font-semibold text-secondary-medium">{{ trans_choice('general.city', 1) }}</span>
                    <x-forms.select-input wire:model.live="city_id">
                        <option value="">@lang('general.all-cities')</option>
                        @foreach($cities as $city)
                            <option value="{{ $city->id }}">{{ $city->name }}</option>
                        @endforeach
                    </x-forms.select-input>
                </label>

                <label class="block space-y-1">
                    <span class="text-small font-semibold text-secondary-medium">{{ trans_choice('general.program', 1) }}</span>
                    <x-forms.select-input wire:model.live="program_id">
                        <option value="">@lang('general.all-programs')</option>
                        @foreach($programs as $program)
                            <option value="{{ $program->id }}">{{ $program->name }}</option>
                        @endforeach
                    </x-forms.select-input>
                </label>

                <label class="block space-y-1">
                    <span class="text-small font-semibold text-secondary-medium">{{ trans_choice('general.job-level', 1) }}</span>
                    <x-forms.select-input wire:model.live="job_level_id">
                        <option value="">@lang('general.all-job-levels')</option>
                        @foreach($jobLevels as $level)
                            <option value="{{ $level->id }}">{{ $level->name }}</option>
                        @endforeach
                    </x-forms.select-input>
                </label>

                <label class="block space-y-1">
                    <span class="text-small font-semibold text-secondary-medium">
                        @lang('general.direct-manager')
                    </span>
                    <x-forms.select-input wire:model.live="focus_manager_id">
                        <option value="">@lang('general.all-managers')</option>
                        @foreach($managers as $manager)
                            <option value="{{ $manager->id }}">
                                {{ $manager->activeContracts->first()?->employee?->name }} — {{ $manager->title }}
                            </option>
                        @endforeach
                    </x-forms.select-input>
                </label>

                <label class="block space-y-1">
                    <span class="text-small font-semibold text-secondary-medium">{{ trans_choice('general.center', 1) }}</span>
                    <x-forms.select-input wire:model.live="center_id">
                        <option value="">@lang('general.all-centers')</option>
                        @foreach($centers as $center)
                            <option value="{{ $center->id }}">{{ $center->name }}</option>
                        @endforeach
                    </x-forms.select-input>
                </label>

                <label class="block space-y-1">
                    <span class="text-small font-semibold text-secondary-medium">{{ trans_choice('general.department', 1) }}</span>
                    <x-forms.select-input wire:model.live="department_id">
                        <option value="">@lang('general.all-departments')</option>
                        @foreach($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </x-forms.select-input>
                </label>

            </div>

            <div class="flex items-center gap-2 px-4 py-4 border-t border-neutral-200">
                <button type="button" wire:click="resetFilters" x-on:click="filtersOpen = false"
                        class="flex-1 rounded-surface border border-neutral-200 px-3 py-2 text-small font-semibold text-secondary-medium transition hover:bg-neutral-50">
                    @lang('general.reset-filters')
                </button>
                <button type="button" x-on:click="filtersOpen = false"
                        class="flex-1 rounded-surface bg-primary px-3 py-2 text-small font-semibold text-white transition hover:opacity-90">
                    @lang('general.apply')
                </button>
            </div>
        </div>
    </div>
</div>
