@php
    use App\Services\Attendance\Grouping;
    use App\Services\Attendance\Timesheet as Sheet;

    // One square per day: icon + words + shape, never colour alone (DESIGN.md).
    $cell = [
        Sheet::WORKED => ['bg-surface text-ink', 'check'],
        Sheet::DECIDED_WORKED => ['bg-surface text-ink', 'check'],
        Sheet::ONE_PUNCH => ['bg-card border-2 border-ink text-ink', 'alert'],
        Sheet::NO_RECORD => ['bg-primary text-on-brand', 'question'],
        Sheet::LEAVE => ['bg-card border border-line text-ink-2', 'calendar'],
        Sheet::DECIDED_ABSENT => ['bg-card border border-dashed border-ink-2 text-ink-2', 'x'],
        Sheet::WEEKEND => ['bg-soft text-ink-3', null],
        Sheet::HOLIDAY => ['bg-soft text-ink-3', null],
        Sheet::OUTSIDE => ['border border-dashed border-line-soft text-ink-3', null],
    ];
    $stateTone = ['open' => 'wait', 'confirmed' => 'attn', 'approved' => 'work', 'locked' => 'done'];
    $stateIcon = ['open' => 'clock', 'confirmed' => 'check', 'approved' => 'check', 'locked' => 'shield'];
    $hm = fn (?int $m) => $m === null ? '' : intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
    $printBase = array_filter(['month' => $month, 'city_id' => $cityId, 'group_by' => $by]);
    $groupLabel = $by === Grouping::DEPARTMENT ? 'attendance.timesheets.no-department' : 'attendance.timesheets.no-project';
    // What the active role sees: own (an employee), team (a manager), city (HR of a city) or all.
    $own = $mode === 'own';
    $title = match ($mode) { 'own' => __('attendance.timesheets.title-own'), 'team' => __('attendance.timesheets.title-team'), default => __('attendance.timesheets.title') };
    $isCurrentMonth = $monthDate->format('Y-m') === now()->format('Y-m');
    $lead = match ($mode) { 'own' => __('attendance.timesheets.lead-own'), 'team' => __('attendance.timesheets.lead-team'), default => __('attendance.timesheets.lead') };
@endphp

<div class="space-y-6"
     x-data="{
        {{-- Filtering and folding happen here, in the browser, over the rows the server already sent. --}}
        q: '',
        undecided: false,
        open: {},
        tick: 0,
        init() { Livewire.hook('commit', ({ succeed }) => succeed(() => this.$nextTick(() => this.tick++))); },
        rowEls() { return [...this.$root.querySelectorAll('tr[data-group]')]; },
        match(s, d) { this.tick; const q = this.q.trim().toLowerCase(); return (! q || s.includes(q)) && (! this.undecided || d > 0); },
        shown(key) { this.tick; return this.rowEls().some(r => r.dataset.group === key && this.match(r.dataset.s, +r.dataset.d)); },
        hasRows() { this.tick; return this.rowEls().length > 0; },
        anyShown() { return this.rowEls().some(r => this.match(r.dataset.s, +r.dataset.d)); },
        groupKeys() { return [...new Set(this.rowEls().map(r => r.dataset.group))]; },
        // Groups start collapsed, so a long page opens as a short list of projects. They open by
        // themselves when there is only one. While a search or filter is on they are all open, whatever
        // was folded by hand, so a match is never hidden; clearing it brings back what was chosen.
        isOpen(key) { this.tick; if (this.q.trim() !== '' || this.undecided) return true; if (this.open[key] !== undefined) return this.open[key]; return this.groupKeys().length === 1; },
        toggle(key) { this.open[key] = ! this.isOpen(key); },
        foldAll(on) { this.groupKeys().forEach(k => this.open[k] = ! on); },
     }">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-page-header :title="$title" :lead="$lead">
        <x-slot:actions>
            @if($canManage)
                <x-button variant="outline" :href="route('attendance.devices')">@lang('attendance.devices.title')</x-button>
                <x-button variant="quiet" :href="route('attendance.upload')">@lang('attendance.timesheets.upload')</x-button>
            @endif
            <x-button icon="file" :href="route('attendance.print', $printBase)" :wire:navigate="false" target="_blank" data-testid="print-link">{{ $own ? __('attendance.timesheets.print-own') : __('attendance.timesheets.print-all') }}</x-button>
            <x-button variant="outline" :href="route('attendance.export.pdf', $printBase)" :wire:navigate="false" target="_blank" data-testid="pdf-link">@lang('attendance.export.pdf')</x-button>
            <x-button variant="outline" :href="route('attendance.export.xlsx', $printBase)" :wire:navigate="false" data-testid="xlsx-link">@lang('attendance.export.xlsx')</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- What to show: month, city, how to group, who --}}
    <x-card class="space-y-4" data-testid="attendance-toolbar">
        <div class="flex flex-wrap items-end gap-x-6 gap-y-4">
            <div>
                <span class="mb-1 block text-small font-semibold text-ink-2" id="att-month-label">@lang('attendance.timesheets.month')</span>
                <div class="flex items-center gap-1" role="group" aria-labelledby="att-month-label">
                    <button type="button" wire:click="shiftMonth(-1)" aria-label="{{ __('attendance.timesheets.prev-month') }}"
                            class="grid size-11 place-items-center rounded-control border border-line bg-card text-ink hover:bg-hover">
                        <x-ic name="chevron" class="size-4 ltr:rotate-180" />
                    </button>
                    <input type="month" wire:model.live="month" aria-labelledby="att-month-label"
                           class="h-11 rounded-control border border-line bg-card px-3 text-body font-semibold text-ink num">
                    <button type="button" wire:click="shiftMonth(1)" aria-label="{{ __('attendance.timesheets.next-month') }}"
                            class="grid size-11 place-items-center rounded-control border border-line bg-card text-ink hover:bg-hover">
                        <x-ic name="chevron" class="size-4 rtl:rotate-180" />
                    </button>
                </div>
            </div>

            @if($cities->isNotEmpty())
            <div>
                <x-forms.input-label for="att-city">@lang('attendance.timesheets.city')</x-forms.input-label>
                <x-forms.select-input id="att-city" wire:model.live="cityId" wire:loading.attr="disabled" wire:target="cityId">
                    <option value="">@lang('attendance.timesheets.all-cities')</option>
                    @foreach($cities as $city)
                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                    @endforeach
                </x-forms.select-input>
            </div>
            @endif

            @unless($own)
            <div>
                <span class="mb-1 block text-small font-semibold text-ink-2" id="att-group-label">@lang('attendance.timesheets.group-by')</span>
                <div class="inline-flex overflow-hidden rounded-control border border-line" role="group" aria-labelledby="att-group-label">
                    @foreach([Grouping::PROJECT => 'by-project', Grouping::DEPARTMENT => 'by-department'] as $value => $label)
                        <button type="button" wire:click="$set('groupBy', '{{ $value }}')" aria-pressed="{{ $by === $value ? 'true' : 'false' }}"
                                class="h-11 px-4 text-body font-semibold {{ $by === $value ? 'bg-ink text-on-ink' : 'bg-card text-ink-2 hover:bg-hover' }}">
                            @lang('attendance.timesheets.'.$label)
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="min-w-56 flex-1">
                <x-forms.input-label for="att-search">@lang('attendance.timesheets.search')</x-forms.input-label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-ink-3"><x-ic name="search" class="size-4" /></span>
                    {{-- Filters the people already on the page, in the browser: no round trip. --}}
                    <input id="att-search" type="search" x-model="q" autocomplete="off" data-testid="att-search"
                           class="h-11 w-full rounded-control border border-line bg-card ps-10 pe-3 text-body text-ink">
                </div>
            </div>

            <label class="inline-flex h-11 items-center gap-2 text-body text-ink">
                <input type="checkbox" x-model="undecided" class="size-4 rounded border-line" data-testid="att-undecided">
                @lang('attendance.timesheets.only-to-decide')
            </label>
            @endunless

            <span class="inline-flex h-11 items-center gap-2 text-small text-ink-2" wire:loading wire:target="month,cityId,groupBy,shiftMonth" role="status">
                <x-icons.spinner class="size-4 animate-spin" /> @lang('attendance.timesheets.loading')
            </span>
        </div>

        @if($cityName)
            <p class="text-small text-ink-2" data-testid="att-scope">{{ __('attendance.timesheets.showing-city', ['city' => $cityName]) }}</p>
        @endif
    </x-card>

    {{-- The month in three numbers --}}
    <div class="grid gap-4 sm:grid-cols-3" data-testid="attendance-summary">
        <x-card>
            <div class="text-small text-ink-2">@lang('attendance.timesheets.people')</div>
            <div class="mt-1 text-h2 font-extrabold text-ink num">{{ $summary['people'] }}</div>
        </x-card>
        <x-card data-testid="attendance-banner">
            <div class="text-small text-ink-2">@lang('attendance.timesheets.decide')</div>
            @if($summary['to_decide'] > 0)
                <div class="mt-1 text-h2 font-extrabold text-primary num">{{ $summary['to_decide'] }}</div>
                <p class="mt-1 text-small text-ink-2">@lang('attendance.timesheets.to-decide-help')</p>
                @unless($own)
                    <button type="button" x-on:click="undecided = ! undecided" x-bind:aria-pressed="undecided" class="mt-2 text-small font-semibold underline text-ink hover:text-primary">
                        <span x-show="! undecided">@lang('attendance.timesheets.show-only-those')</span>
                        <span x-show="undecided" x-cloak>@lang('attendance.timesheets.show-everyone')</span>
                    </button>
                @endunless
            @else
                <div class="mt-2"><x-status tone="done" icon="check" :label="__('attendance.timesheets.all-decided')" /></div>
            @endif
        </x-card>
        <x-card>
            <div class="text-small text-ink-2">@lang('attendance.timesheets.overtime')</div>
            <div class="mt-1 text-h2 font-extrabold text-ink num">{{ $summary['overtime_hours'] }} <span class="text-body font-semibold text-ink-2">@lang('attendance.timesheets.hours-unit')</span></div>
            <p class="mt-1 text-small text-ink-2 num"><bdi>= +{{ $summary['overtime_days'] }}</bdi> @lang('attendance.timesheets.free-days')</p>
        </x-card>
    </div>

    {{-- Month-end: approve what is ready, lock what is approved --}}
    @if($summary['ready_to_approve'] > 0 || $summary['ready_to_lock'] > 0)
        <x-card class="flex flex-wrap items-center gap-3" data-testid="attendance-bulk">
            @if($summary['ready_to_approve'] > 0)
                <x-button wire:click="approveAllReady" wire:confirm="{{ __('attendance.workflow.approve-all', ['count' => $summary['ready_to_approve']]) }}?">
                    {{ __('attendance.workflow.approve-all', ['count' => $summary['ready_to_approve']]) }}
                </x-button>
            @endif
            @if($summary['ready_to_lock'] > 0)
                <x-button variant="outline" icon="shield" wire:click="lockAllApproved" wire:confirm="{{ __('attendance.workflow.lock-all', ['count' => $summary['ready_to_lock']]) }}?">
                    {{ __('attendance.workflow.lock-all', ['count' => $summary['ready_to_lock']]) }}
                </x-button>
            @endif
        </x-card>
    @endif

    {{-- Key to the squares --}}
    <div class="flex flex-wrap gap-x-5 gap-y-2 text-small text-ink-2" aria-label="{{ __('attendance.timesheets.key') }}">
        @foreach([
            [Sheet::WORKED, 'attendance.timesheets.legend-worked'],
            [Sheet::ONE_PUNCH, 'attendance.timesheets.legend-one'],
            [Sheet::NO_RECORD, 'attendance.timesheets.legend-none'],
            [Sheet::LEAVE, 'attendance.timesheets.legend-leave'],
            [Sheet::DECIDED_ABSENT, 'attendance.timesheets.legend-absent'],
            [Sheet::WEEKEND, 'attendance.timesheets.legend-off'],
            [Sheet::OUTSIDE, 'attendance.timesheets.legend-out'],
        ] as [$kind, $key])
            <span class="inline-flex items-center gap-2">
                @php([$classes, $icon] = $cell[$kind])
                <span class="grid size-5 place-items-center rounded-control {{ $classes }}">@if($icon)<x-ic :name="$icon" class="size-3" />@endif</span>
                @lang($key)
            </span>
        @endforeach
    </div>

    @unless($own)
        <div class="flex flex-wrap items-center justify-between gap-2" x-show="groupKeys().length > 1" x-cloak>
            <p class="text-small text-ink-2" x-show="q.trim() || undecided" x-cloak role="status">@lang('attendance.timesheets.filtered')</p>
            <span class="ms-auto inline-flex gap-3">
                <button type="button" x-on:click="foldAll(false)" class="text-small font-semibold underline text-ink-2 hover:text-ink">@lang('attendance.timesheets.expand-all')</button>
                <button type="button" x-on:click="foldAll(true)" class="text-small font-semibold underline text-ink-2 hover:text-ink">@lang('attendance.timesheets.collapse-all')</button>
            </span>
        </div>
    @endunless

    <p class="py-6 text-center text-body text-ink-2" x-show="hasRows() && ! anyShown()" x-cloak data-testid="att-no-match">@lang('attendance.timesheets.no-match')</p>

    {{-- City, then project or department --}}
    <div class="space-y-6 transition-opacity" wire:loading.class="opacity-50 pointer-events-none" wire:target="month,cityId,groupBy,shiftMonth">
    @forelse($blocks as $block)
        <section class="space-y-3" aria-labelledby="city-{{ $block['id'] }}" data-testid="city-block" wire:key="city-{{ $block['id'] }}">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id="city-{{ $block['id'] }}" class="text-h3 font-extrabold text-ink">{{ $block['name'] ?? '—' }}</h2>
                <span class="text-body text-ink-2">{{ trans_choice('attendance.timesheets.people-count', $block['totals']['people']) }}</span>
                @if($block['totals']['to_decide'] > 0)
                    <x-status tone="attn" icon="alert" :label="__('attendance.timesheets.to-decide', ['count' => $block['totals']['to_decide']])" />
                @endif
                {{-- The mailing list that stands for this city, when there is one. --}}
                @if($cityList = \App\Models\MailList::emailFor($block['id'] ? (int) $block['id'] : null, null, null, $mailLists))
                    <a href="mailto:{{ $cityList->address }}" data-testid="email-city" class="inline-flex min-h-8 items-center gap-1.5 text-small font-semibold text-ink-2 underline hover:text-ink">
                        <x-icons.envelope class="size-4" /> @lang('mail_lists.email_group')
                    </a>
                @endif
            </div>

            @foreach($block['groups'] as $group)
                @php($foldKey = $block['id'].':'.$group['key'])
                <x-card class="!p-0 overflow-hidden" style="contain: paint" data-testid="group-card" wire:key="group-{{ $foldKey }}" x-show="shown({{ \Illuminate\Support\Js::from($foldKey) }})">
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-line-soft bg-soft px-4 py-3">
                        <button type="button" x-on:click="toggle(@js($foldKey))" x-bind:aria-expanded="isOpen(@js($foldKey))"
                                class="flex min-h-11 items-center gap-2 text-start" data-testid="group-fold">
                            <x-ic name="chevron" class="size-4 shrink-0 text-ink-2 transition" x-bind:class="isOpen({{ \Illuminate\Support\Js::from($foldKey) }}) ? 'rotate-90' : 'rtl:rotate-180'" />
                            <h3 class="text-h4 font-bold text-ink"><bdi>{{ $group['name'] ?? __($groupLabel) }}</bdi></h3>
                            <span class="text-small text-ink-2">{{ trans_choice('attendance.timesheets.people-count', $group['totals']['people']) }}</span>
                        </button>

                        <div class="flex flex-wrap items-center gap-3">
                            @if($group['totals']['to_decide'] > 0)
                                <x-status tone="attn" icon="alert" :label="__('attendance.timesheets.to-decide', ['count' => $group['totals']['to_decide']])" />
                            @else
                                <x-status tone="done" icon="check" :label="__('attendance.timesheets.group-ready')" />
                            @endif
                            @if($group['totals']['overtime_hours'] > 0)
                                <span class="text-small text-ink-2 num"><bdi>{{ $group['totals']['overtime_hours'] }} @lang('attendance.timesheets.hours-unit') = +{{ $group['totals']['overtime_days'] }}</bdi> @lang('attendance.timesheets.free-days')</span>
                            @endif
                            {{-- The list for this project or department (in this city, else nationwide). --}}
                            @php($groupList = ($group['id'] ?? null) ? \App\Models\MailList::emailForGroup($block['id'] ? (int) $block['id'] : null, $by === \App\Services\Attendance\Grouping::PROJECT ? (int) $group['id'] : null, $by === \App\Services\Attendance\Grouping::DEPARTMENT ? (int) $group['id'] : null, $mailLists) : null)
                            @if($groupList)
                                <a href="mailto:{{ $groupList->address }}" data-testid="email-group"
                                   class="inline-flex min-h-9 items-center gap-2 rounded-control border border-line bg-card px-3 text-small font-semibold text-ink hover:bg-hover">
                                    <x-icons.envelope class="size-4" /> @lang('mail_lists.email_group')
                                </a>
                            @endif
                            @unless($own)
                                @php($groupParams = $printBase + ['city_id' => $block['id'], 'group' => $group['id'] ?? 'none'])
                                <details class="relative" data-testid="group-export">
                                    <summary class="inline-flex min-h-9 cursor-pointer list-none items-center gap-2 rounded-control border border-line bg-card px-3 text-small font-semibold text-ink hover:bg-hover">
                                        <x-ic name="file" class="size-4" /> @lang('attendance.timesheets.print-group')
                                        <x-ic name="chevron" class="size-3 rotate-90" />
                                    </summary>
                                    <div class="absolute end-0 z-20 mt-1 w-40 overflow-hidden rounded-surface border border-line-soft bg-card py-1 shadow-e3">
                                        <a class="block px-4 py-2 text-small font-semibold text-ink hover:bg-hover" target="_blank" href="{{ route('attendance.print', $groupParams) }}">@lang('attendance.export.print')</a>
                                        <a class="block px-4 py-2 text-small font-semibold text-ink hover:bg-hover" target="_blank" href="{{ route('attendance.export.pdf', $groupParams) }}">@lang('attendance.export.pdf')</a>
                                        <a class="block px-4 py-2 text-small font-semibold text-ink hover:bg-hover" href="{{ route('attendance.export.xlsx', $groupParams) }}">@lang('attendance.export.xlsx')</a>
                                    </div>
                                </details>
                            @endunless
                        </div>
                    </div>

                    <div x-show="isOpen(@js($foldKey))" x-cloak>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-max text-start text-body">
                                <thead>
                                    <tr class="text-caption text-ink-3">
                                        <th scope="col" class="sticky start-0 z-10 min-w-48 bg-card px-4 py-2 text-start font-semibold">@lang('attendance.timesheets.person')</th>
                                        <th scope="col" class="px-3 py-2 text-center font-semibold">@lang('attendance.timesheets.days')</th>
                                        <th scope="col" class="px-3 py-2 text-center font-semibold">@lang('attendance.timesheets.overtime')</th>
                                        <th scope="col" class="px-3 py-2 text-center font-semibold">@lang('attendance.timesheets.decide')</th>
                                        <th scope="col" class="px-3 py-2 text-center font-semibold">@lang('attendance.workflow.state')</th>
                                        @foreach($days as $day)
                                            @php($today = $isCurrentMonth && $day['n'] === now()->day)
                                            <th scope="col" @class(['px-px py-2 text-center font-semibold num', 'rounded-t-control bg-ink text-on-ink' => $today]) @if($today) aria-current="date" @endif>
                                                <span class="block leading-tight">{{ $day['n'] }}</span>
                                                <span class="block text-[10px] font-normal leading-tight">{{ $day['w'] }}</span>
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($group['items'] as $item)
                                        @php($employee = $item['employee'])
                                        @php($sheet = $item['sheet'])
                                        {{-- data-s: what the search looks in (both names and the id); data-d: days waiting. --}}
                                        <tr class="border-t border-line-soft hover:bg-hover" wire:key="sheet-{{ $employee->id }}"
                                            data-group="{{ $foldKey }}" data-s="{{ mb_strtolower($employee->nameForLocale('en').' '.$employee->nameForLocale('ar').' '.$employee->id) }}" data-d="{{ (int) $sheet['totals']['to_decide'] }}"
                                            x-show="match($el.dataset.s, +$el.dataset.d)">
                                            <th scope="row" class="sticky start-0 z-10 bg-card px-4 py-2 text-start font-normal">
                                                <button type="button" wire:click="openMonth({{ $employee->id }})" class="block min-h-11 text-start"
                                                        aria-label="{{ __('attendance.timesheets.open-month', ['name' => $employee->name]) }}">
                                                    <span class="block font-semibold text-ink"><bdi>{{ $employee->name }}</bdi></span>
                                                    <span class="block text-caption text-ink-3"><span class="num">ID {{ $employee->id }}</span>@if($sheet['placement']['center']) · <bdi>{{ $sheet['placement']['center'] }}</bdi>@endif</span>
                                                </button>
                                            </th>
                                            <td class="px-3 text-center font-semibold num">{{ $sheet['totals']['working_days'] }}</td>
                                            <td class="px-3 text-center text-small num whitespace-nowrap">
                                                @if($sheet['totals']['overtime_hours'] > 0)
                                                    <bdi>{{ $sheet['totals']['overtime_hours'] }} @lang('attendance.timesheets.hours-unit') = +{{ $sheet['totals']['overtime_days'] }}</bdi>
                                                @else
                                                    <span class="text-ink-3">–</span>
                                                @endif
                                            </td>
                                            <td class="px-3 text-center">
                                                @if($sheet['totals']['to_decide'] > 0)
                                                    <x-status tone="attn" icon="alert" :label="(string) $sheet['totals']['to_decide']" />
                                                @else
                                                    <span class="text-ink-3">–</span>
                                                @endif
                                            </td>
                                            <td class="px-3 text-center">
                                                <x-status :tone="$stateTone[$item['state']]" :icon="$stateIcon[$item['state']]" :label="__('attendance.workflow.states.'.$item['state'])" />
                                            </td>
                                            @foreach($sheet['rows'] as $row)
                                                @php([$classes, $icon] = $cell[$row['kind']])
                                                @php($label = __('attendance.timesheets.status.'.$row['kind']).' · '.$row['date']->format('d/m').($row['worked_minutes'] ? ' · '.$hm($row['worked_minutes']) : ''))
                                                <td class="px-px py-1">
                                                    @if($row['decidable'] && $item['can']['decide'])
                                                        <button type="button" wire:click="openDay({{ $employee->id }}, '{{ $row['date']->toDateString() }}')"
                                                                title="{{ $label }}" aria-label="{{ $employee->name }}: {{ $label }}"
                                                                class="grid size-7 place-items-center rounded-control transition hover:scale-110 {{ $classes }}">
                                                            @if($icon)<x-ic :name="$icon" class="size-3.5" />@endif
                                                        </button>
                                                    @else
                                                        <span title="{{ $label }}" class="grid size-7 place-items-center rounded-control {{ $classes }}">
                                                            @if($icon)<x-ic :name="$icon" class="size-3.5" />@endif
                                                            <span class="sr-only">{{ $label }}</span>
                                                        </span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </section>
    @empty
        <x-card>
            <p class="py-8 text-center text-body text-ink-2" data-testid="attendance-empty">@lang('attendance.timesheets.nothing')</p>
        </x-card>
    @endforelse
    </div>

    {{-- One person's month: where it stands, what the viewer may do, the signed paper, the exports --}}
    @if($panel)
        @php($pEmployee = $panel['employee'])
        @php($pRecord = $panel['record'])
        @php($pCan = $panel['can'])
        @php($pTotals = $panel['sheet']['totals'])
        @php($pSigned = $pRecord->exists ? $pRecord->getFirstMedia('signed') : null)
        @php($pParams = ['month' => $month, 'employees' => [$pEmployee->id]])
        <div class="fixed inset-0 z-50 flex justify-end bg-backdrop" x-data x-on:keydown.escape.window="$wire.closeMonth()" data-testid="month-panel">
            <div class="absolute inset-0" wire:click="closeMonth" aria-hidden="true"></div>
            <aside role="dialog" aria-modal="true" aria-labelledby="month-title" class="relative flex h-full w-full max-w-md flex-col gap-5 overflow-y-auto bg-card p-6 shadow-e3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="month-title" class="text-h4 font-bold text-ink"><bdi>{{ $pEmployee->name }}</bdi></h2>
                        <p class="text-small text-ink-2"><bdi class="num">{{ $monthDate->format('F Y') }}</bdi> · <span class="num">ID {{ $pEmployee->id }}</span></p>
                    </div>
                    <button type="button" wire:click="closeMonth" aria-label="{{ __('attendance.workflow.close') }}" class="grid size-11 place-items-center rounded-control text-ink-2 hover:bg-hover"><x-ic name="x" class="size-5" /></button>
                </div>

                <div><x-status :tone="$stateTone[$panel['state']]" :icon="$stateIcon[$panel['state']]" :label="__('attendance.workflow.states.'.$panel['state'])" /></div>

                <dl class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-surface bg-soft p-3"><dt class="text-caption text-ink-2">@lang('attendance.timesheets.days')</dt><dd class="text-h4 font-bold text-ink num">{{ $pTotals['working_days'] }}</dd></div>
                    <div class="rounded-surface bg-soft p-3"><dt class="text-caption text-ink-2">@lang('attendance.timesheets.overtime')</dt><dd class="text-h4 font-bold text-ink num">{{ $pTotals['overtime_hours'] }} h</dd><dd class="text-caption text-ink-2 num">+{{ $pTotals['overtime_days'] }} @lang('attendance.workflow.free-days')</dd></div>
                    <div class="rounded-surface bg-soft p-3"><dt class="text-caption text-ink-2">@lang('attendance.timesheets.decide')</dt><dd class="text-h4 font-bold num {{ $pTotals['to_decide'] > 0 ? 'text-primary' : 'text-ink' }}">{{ $pTotals['to_decide'] }}</dd></div>
                </dl>

                <div>
                    <h3 class="mb-2 text-small font-bold uppercase tracking-wide text-ink-3">@lang('attendance.workflow.timeline')</h3>
                    <ol class="space-y-2 text-body text-ink">
                        <li class="flex items-start gap-2"><x-ic :name="$pRecord->employee_confirmed_at ? 'check-circle' : 'dot'" class="mt-1 size-4 shrink-0" />
                            <span>@if($pRecord->employee_confirmed_at)@lang('attendance.workflow.step-confirmed') <span class="text-small text-ink-3 num">{{ $pRecord->employee_confirmed_at->format('d/m/Y H:i') }}</span>@else<span class="text-ink-3">@lang('attendance.workflow.step-confirmed') · @lang('attendance.workflow.not-yet')</span>@endif</span></li>
                        <li class="flex items-start gap-2"><x-ic :name="$pRecord->approved_at ? 'check-circle' : 'dot'" class="mt-1 size-4 shrink-0" />
                            <span>@if($pRecord->approved_at){{ __('attendance.workflow.step-approved', ['name' => $pRecord->approver?->profile?->name ?? '—']) }} <span class="text-small text-ink-3 num">{{ $pRecord->approved_at->format('d/m/Y H:i') }}</span><br><span class="text-small text-ink-2">{{ __('attendance.workflow.overtime-approved', ['hours' => $pRecord->overtime_hours_approved]) }}</span>@else<span class="text-ink-3">@lang('attendance.workflow.approve') · @lang('attendance.workflow.not-yet')</span>@endif</span></li>
                        <li class="flex items-start gap-2"><x-ic :name="$pRecord->locked_at ? 'shield' : 'dot'" class="mt-1 size-4 shrink-0" />
                            <span>@if($pRecord->locked_at){{ __('attendance.workflow.step-locked', ['name' => $pRecord->locker?->profile?->name ?? '—']) }} <span class="text-small text-ink-3 num">{{ $pRecord->locked_at->format('d/m/Y H:i') }}</span><br>
                                <span class="text-small text-ink-2">@if($pRecord->credited_hours > 0){{ __('attendance.workflow.credited', ['days' => round($pRecord->credited_hours / max(1, $panel['sheet']['daily_hours']), 2), 'hours' => $pRecord->credited_hours]) }}@elseif($pRecord->overtime_hours_approved > 0){{ __('attendance.workflow.not-credited') }}@endif</span>@else<span class="text-ink-3">@lang('attendance.workflow.lock') · @lang('attendance.workflow.not-yet')</span>@endif</span></li>
                    </ol>
                </div>

                <div class="flex flex-wrap gap-2" data-testid="month-actions">
                    @if($pCan['confirm'])<x-button wire:click="confirmMonth({{ $pEmployee->id }})">@lang('attendance.workflow.confirm')</x-button>@endif
                    @if($pCan['approve'])<x-button wire:click="approveMonth({{ $pEmployee->id }})">@lang('attendance.workflow.approve')</x-button>@endif
                    @if($pCan['lock'])<x-button icon="shield" wire:click="lockMonth({{ $pEmployee->id }})">@lang('attendance.workflow.lock')</x-button>@endif
                    @if($pCan['reopen'])<x-button variant="outline" wire:click="reopenMonth({{ $pEmployee->id }})" wire:confirm="{{ __('attendance.workflow.reopen') }}?">@lang('attendance.workflow.reopen')</x-button>@endif
                </div>
                @if($pTotals['to_decide'] > 0 && ! $pCan['approve'] && ! $pCan['confirm'] && $panel['state'] === 'open')
                    <p class="text-small text-ink-2">{{ __('attendance.workflow.days-first', ['count' => $pTotals['to_decide']]) }}</p>
                @endif

                <div class="space-y-2" data-testid="month-signed">
                    <h3 class="text-small font-bold uppercase tracking-wide text-ink-3">@lang('attendance.workflow.signed')</h3>
                    @if($pSigned)
                        <div class="flex flex-wrap items-center gap-3">
                            <a class="text-body font-semibold text-ink underline" target="_blank" href="{{ route('attendance.signed', $pRecord->id) }}">@lang('attendance.workflow.signed-view')</a>
                            @if($pCan['signed'])<x-button variant="link" wire:click="removeSigned">@lang('attendance.workflow.signed-remove')</x-button>@endif
                        </div>
                    @else
                        <p class="text-small text-ink-2">@lang('attendance.workflow.signed-none')</p>
                    @endif
                    @if($pCan['signed'])
                        <form wire:submit.prevent="saveSigned" class="space-y-2">
                            <input type="file" wire:model="signedFile" accept=".pdf,.jpg,.jpeg,.png" aria-label="{{ __('attendance.workflow.signed') }}"
                                   class="block w-full rounded-control border border-line bg-card px-3 py-2 text-small text-ink">
                            <p class="text-caption text-ink-3">@lang('attendance.workflow.signed-help')</p>
                            @error('signedFile') <p class="text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                            <x-button type="submit" variant="outline" size="sm">@lang('attendance.workflow.signed-upload')</x-button>
                        </form>
                    @endif
                </div>

                <div class="mt-auto flex flex-wrap gap-2 border-t border-line-soft pt-4">
                    <x-button variant="outline" size="sm" icon="file" target="_blank" :wire:navigate="false" :href="route('attendance.print', $pParams)">@lang('attendance.export.print')</x-button>
                    <x-button variant="outline" size="sm" target="_blank" :wire:navigate="false" :href="route('attendance.export.pdf', $pParams)">@lang('attendance.export.pdf')</x-button>
                    <x-button variant="outline" size="sm" :wire:navigate="false" :href="route('attendance.export.xlsx', $pParams)">@lang('attendance.export.xlsx')</x-button>
                </div>
            </aside>
        </div>
    @endif

    @if($open && $open['row'])
        @php($row = $open['row'])
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-backdrop p-4" x-data x-on:keydown.escape.window="$wire.closeDay()" data-testid="day-dialog">
            <div class="absolute inset-0" wire:click="closeDay" aria-hidden="true"></div>
            <div role="dialog" aria-modal="true" aria-labelledby="day-title" class="relative w-full max-w-lg rounded-surface border border-line-soft bg-card p-6 shadow-e3">
                <h2 id="day-title" class="text-h4 font-bold text-ink">@lang('attendance.timesheets.dialog-title')</h2>
                <p class="mt-1 text-body font-semibold text-ink"><bdi>{{ $open['employee']->name }}</bdi> · <bdi class="num">{{ $row['date']->format('D d/m/Y') }}</bdi></p>
                <p class="mt-2 text-body text-ink-2">
                    @if($row['first'] && $row['kind'] === Sheet::ONE_PUNCH)
                        {{ __('attendance.timesheets.dialog-one', ['time' => $row['first']->format('H:i')]) }}
                    @else
                        @lang('attendance.timesheets.dialog-none')
                    @endif
                </p>

                <form wire:submit.prevent="saveDecision" class="mt-4 space-y-3">
                    <label class="flex cursor-pointer items-start gap-3 rounded-surface border border-line p-3 has-[:checked]:border-2 has-[:checked]:border-ink">
                        <input type="radio" wire:model="dayKind" value="worked" class="mt-1 size-4">
                        <span><span class="block font-semibold text-ink">@lang('attendance.timesheets.worked')</span><span class="text-small text-ink-2">@lang('attendance.timesheets.worked-help')</span></span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-surface border border-line p-3 has-[:checked]:border-2 has-[:checked]:border-ink">
                        <input type="radio" wire:model="dayKind" value="absent" class="mt-1 size-4">
                        <span><span class="block font-semibold text-ink">@lang('attendance.timesheets.absent')</span><span class="text-small text-ink-2">@lang('attendance.timesheets.absent-help')</span></span>
                    </label>
                    <p class="text-small text-ink-2">@lang('attendance.timesheets.leave-hint')</p>
                    <div>
                        <x-forms.input-label for="day-note">@lang('attendance.timesheets.note')</x-forms.input-label>
                        <x-forms.input id="day-note" wire:model="dayNote" maxlength="200" dir="auto" />
                        <x-forms.input-error field="dayNote" input-id="day-note" />
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                        <div>
                            @if($row['decision'])
                                <x-button variant="link" wire:click="clearDecision">@lang('attendance.timesheets.remove')</x-button>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            <x-button variant="outline" wire:click="closeDay">@lang('attendance.timesheets.cancel')</x-button>
                            <x-button type="submit">@lang('attendance.timesheets.save')</x-button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
