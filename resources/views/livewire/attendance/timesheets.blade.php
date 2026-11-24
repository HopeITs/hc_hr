@php
    use App\Services\Attendance\Grouping;
    use App\Services\Attendance\Timesheet as Sheet;

    // Two screens, one component: everyone in a short list, or one person's month as a calendar.
    $printBase = array_filter(['month' => $month, 'city_id' => $cityId, 'group_by' => $by]);
    $groupLabel = $by === Grouping::DEPARTMENT ? 'attendance.timesheets.no-department' : 'attendance.timesheets.no-project';
    $own = $mode === 'own';
    $title = match ($mode) { 'own' => __('attendance.timesheets.title-own'), 'team' => __('attendance.timesheets.title-team'), default => __('attendance.timesheets.title') };
    $pending = [Sheet::ONE_PUNCH, Sheet::NO_RECORD];
    $blocked = $items->contains(fn ($i) => $i['sheet']['totals']['to_decide'] > 0 || $i['sheet']['totals']['leave_pending'] > 0);

    // One status per person, in plain words. Red is only for days that wait for an answer.
    $statusOf = function (array $item) {
        if ($item['sheet']['totals']['to_decide'] > 0) {
            return ['need', null];
        }

        return match (true) {
            $item['state'] === 'locked' => ['done', ['done', 'shield', __('attendance.timesheets.closed')]],
            $item['state'] === 'approved' => ['done', ['done', 'check', __('attendance.timesheets.confirmed')]],
            (bool) $item['can']['approve'] => ['check', ['attn', 'clock', __('attendance.timesheets.wait-confirm')]],
            default => ['progress', ['wait', 'clock', __('attendance.timesheets.in-progress')]],
        };
    };
    $planned = fn (array $sheet) => collect($sheet['rows'])->whereNotIn('kind', [Sheet::WEEKEND, Sheet::HOLIDAY, Sheet::OUTSIDE])->count();
    $hm = fn ($t) => $t ? $t->format('H:i') : '';
    $avatar = fn ($e) => $e->getFirstMediaUrl('avatar') ?: asset('images/default-avatar.png');
@endphp

<div class="space-y-6">
    <x-slot:title>{{ $title }}</x-slot:title>

@if($panel)
    {{-- ───────────── One person's month ───────────── --}}
    @php
        $pEmployee = $panel['employee'];
        $pRecord = $panel['record'];
        $pCan = $panel['can'];
        $pSheet = $panel['sheet'];
        $pTotals = $pSheet['totals'];
        // The paper prints only when nothing waits: no day for HR, no leave request unanswered.
        $pBlocked = $pTotals['to_decide'] > 0 || $pTotals['leave_pending'] > 0;
        $pSigned = $pRecord->exists ? $pRecord->getFirstMedia('signed') : null;
        $pParams = ['month' => $month, 'employees' => [$pEmployee->id]];
        $lead = ($monthDate->startOfMonth()->dayOfWeek + 1) % 7;   // weeks start on Saturday
        $leaveDays = collect($pSheet['rows'])->where('kind', Sheet::LEAVE)->count();
        $hours = intdiv((int) collect($pSheet['rows'])->whereIn('kind', [Sheet::WORKED])->sum('worked_minutes'), 60);
        $selected = $open && $open['employee']->id === $pEmployee->id ? $open['row'] : null;
        $selectedDate = $selected ? $selected['date']->toDateString() : null;
    @endphp

    <div data-testid="month-panel" class="space-y-6">
        @unless($own)
            <button type="button" wire:click="closeMonth" class="inline-flex min-h-11 items-center gap-2 font-semibold text-ink-2 hover:text-ink">
                <x-ic name="chevron" class="size-4 ltr:rotate-180" /> @lang('attendance.timesheets.back')
            </button>
        @endunless

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <img src="{{ $avatar($pEmployee) }}" alt="" class="size-14 rounded-full bg-surface object-cover">
                <div>
                    <h1 id="month-title" class="text-h2 font-extrabold text-ink"><bdi>{{ $pEmployee->name }}</bdi></h1>
                    <p class="text-small text-ink-2"><span class="num">ID {{ $pEmployee->id }}</span>@if($pSheet['placement']['program'] ?? null) · <bdi>{{ $pSheet['placement']['program'] }}</bdi>@endif</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @include('livewire.attendance.partials.month-stepper')
                @if($pBlocked)
                    <x-button variant="outline" icon="file" disabled title="{{ __('attendance.timesheets.print-blocked') }}" data-testid="print-blocked">@lang('attendance.export.print')</x-button>
                @else
                    <x-button variant="outline" icon="file" target="_blank" :wire:navigate="false" :href="route('attendance.print', $pParams)" data-testid="print-link">@lang('attendance.export.print')</x-button>
                @endif
            </div>
        </div>

        <dl class="grid grid-cols-3 gap-3">
            <div class="rounded-surface bg-soft p-4"><dt class="text-small text-ink-2">@lang('attendance.timesheets.days-worked')</dt><dd class="text-h3 font-extrabold text-ink num">{{ $pTotals['working_days'] + 0 }} <span class="text-body font-normal text-ink-3">/ {{ $planned($pSheet) }}</span></dd></div>
            <div class="rounded-surface bg-soft p-4"><dt class="text-small text-ink-2">@lang('attendance.timesheets.hours')</dt><dd class="text-h3 font-extrabold text-ink num">{{ $hours }}</dd></div>
            <div class="rounded-surface bg-soft p-4"><dt class="text-small text-ink-2">@lang('attendance.timesheets.leave')</dt><dd class="text-h3 font-extrabold text-ink num">{{ $leaveDays }}</dd></div>
        </dl>

        {{-- This person's own weekly days off: from the contract, else the center --}}
        @php
            $off = $pSheet['placement']['days_off'] ?? [];
            $offFrom = $pSheet['placement']['days_off_from'] ?? null;
            $offNames = collect($off)->map(fn ($d) => \Carbon\CarbonImmutable::now()->startOfWeek(0)->addDays($d)->locale(app()->getLocale())->isoFormat('dddd'))->join(app()->getLocale() === 'ar' ? '، ' : ', ');
        @endphp
        @if($offFrom)
            <p class="flex flex-wrap items-center gap-x-2 text-ink" data-testid="days-off">
                <x-ic name="calendar" class="size-4 text-ink-3" />
                <span class="font-semibold">@lang('attendance.timesheets.days-off'):</span>
                <span>{{ $offNames ?: '—' }}</span>
                <span class="text-small text-ink-2">({{ __('attendance.timesheets.days-off-from.'.$offFrom, ['center' => $pSheet['placement']['center'] ?? '—']) }})</span>
            </p>
        @endif

        @if($pTotals['to_decide'] > 0)
            <p class="rounded-surface border-2 border-primary px-5 py-4 font-bold text-ink" role="status">
                @if($pCan['decide'])
                    {{ trans_choice('attendance.timesheets.alert', $pTotals['to_decide'], ['count' => $pTotals['to_decide']]) }}
                    <span class="font-normal text-ink-2">@lang('attendance.timesheets.pick')</span>
                @else
                    {{ trans_choice('attendance.timesheets.alert-own', $pTotals['to_decide'], ['count' => $pTotals['to_decide']]) }}
                @endif
            </p>
        @endif

        {{-- The month as a calendar --}}
        <div class="grid grid-cols-7 gap-1 sm:gap-1.5" data-testid="attendance-calendar">
            @foreach(__('attendance.timesheets.weekdays') as $w)
                <div class="pb-1 text-center text-caption font-bold text-ink-2 sm:text-small">{{ $w }}</div>
            @endforeach
            @for($i = 0; $i < $lead; $i++)<div aria-hidden="true"></div>@endfor

            @foreach($pSheet['rows'] as $row)
                @php
                    $k = $row['kind'];
                    $canAnswer = $row['decidable'] && $pCan['decide'];
                    $isSel = $selectedDate === $row['date']->toDateString();
                    $label = __('attendance.timesheets.status.'.$k).' · '.$row['date']->format('d/m');
                    $box = match ($k) {
                        Sheet::ONE_PUNCH, Sheet::NO_RECORD => 'bg-primary border-primary text-on-brand',
                        Sheet::WEEKEND, Sheet::HOLIDAY => 'bg-soft border-transparent text-ink-3',
                        Sheet::LEAVE => 'bg-card border-line text-ink',
                        Sheet::DECIDED_ABSENT => 'bg-card border-dashed border-ink-2 text-ink',
                        Sheet::OUTSIDE, Sheet::UPCOMING => 'bg-card border-dashed border-line-soft text-ink-3',
                        default => 'bg-card border-line-soft text-ink',
                    };
                    $cls = 'flex min-h-16 flex-col items-start gap-0.5 rounded-control border p-1.5 text-start sm:min-h-21 sm:p-2.5 '.$box;
                @endphp
                @if($canAnswer)
                    <button type="button" wire:click="openDay({{ $pEmployee->id }}, '{{ $row['date']->toDateString() }}')" aria-label="{{ $label }}" aria-pressed="{{ $isSel ? 'true' : 'false' }}"
                            @class([$cls, 'transition hover:-translate-y-0.5', 'outline-3 outline-offset-2 outline-ink' => $isSel])>
                @else
                    <div title="{{ $label }}" class="{{ $cls }}">
                @endif
                    <span class="text-body font-extrabold leading-none num">{{ $row['date']->day }}</span>
                    <span class="mt-auto w-full text-caption font-semibold leading-tight sm:text-caption">
                        @switch($k)
                            @case(Sheet::WORKED)
                                <span class="hidden text-ink-2 sm:block num" dir="ltr">{{ $hm($row['first']) }}–{{ $hm($row['last']) }}</span>
                                @if($row['worked_minutes'])<span class="num">{{ intdiv($row['worked_minutes'], 60) }}@lang('attendance.timesheets.h') {{ $row['worked_minutes'] % 60 }}</span>@endif
                                @break
                            @case(Sheet::ONE_PUNCH)<span class="num" dir="ltr">{{ $hm($row['first']) }} – ?</span>@break
                            @case(Sheet::NO_RECORD)@lang('attendance.timesheets.status.no_record')@break
                            @case(Sheet::DECIDED_WORKED)@lang('attendance.timesheets.yes')@break
                            @case(Sheet::DECIDED_ABSENT)@lang('attendance.timesheets.no')@break
                            @case(Sheet::LEAVE){{ ! empty($row['leave_pending']) ? __('attendance.timesheets.leave-waits') : __('attendance.timesheets.status.leave') }}@break
                            @case(Sheet::HOLIDAY)<span class="line-clamp-2">{{ $row['note'] ?: __('attendance.timesheets.status.holiday') }}</span>@break
                        @endswitch
                        {{-- Worked on a day off: the punches are there and count as overtime, so show them. --}}
                        @if(in_array($k, [Sheet::WEEKEND, Sheet::HOLIDAY], true) && $row['first'])
                            <span class="hidden text-ink-2 sm:block num" dir="ltr">{{ $hm($row['first']) }}–{{ $hm($row['last']) }}</span>
                            <span class="font-bold text-ink">@lang('attendance.timesheets.worked-off')</span>
                        @endif
                    </span>
                    <span class="sr-only">{{ $label }}</span>
                @if($canAnswer)</button>@else</div>@endif
            @endforeach
        </div>

        {{-- The question for the chosen day: two buttons --}}
        @if($selected)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-surface border-2 border-ink px-5 py-4" data-testid="day-dialog" role="group" aria-labelledby="day-title">
                <div>
                    <p id="day-title" class="font-bold text-ink"><bdi class="num">{{ $selected['date']->locale(app()->getLocale())->isoFormat('dddd D/M') }}</bdi></p>
                    <p class="text-ink-2">
                        @if(in_array($selected['kind'], $pending, true))
                            {{ $selected['kind'] === Sheet::ONE_PUNCH && $selected['first'] ? __('attendance.timesheets.one-at', ['time' => $selected['first']->format('H:i')]) : __('attendance.timesheets.none-at') }}
                            @lang('attendance.timesheets.did-work')
                        @else
                            @lang($selected['kind'] === Sheet::DECIDED_ABSENT ? 'attendance.timesheets.answered-absent' : 'attendance.timesheets.answered-worked')
                        @endif
                    </p>
                </div>
                <div class="flex w-full gap-2 sm:w-auto">
                    <x-button variant="outline" icon="check" class="flex-1" wire:click="answerDay('worked')">@lang('attendance.timesheets.yes')</x-button>
                    <x-button variant="outline" icon="x" class="flex-1" wire:click="answerDay('absent')">@lang('attendance.timesheets.no')</x-button>
                    @if($selected['decision'])
                        <x-button variant="quiet" wire:click="clearDecision">@lang('attendance.timesheets.remove')</x-button>
                    @endif
                </div>
            </div>
        @endif

        {{-- The one next step for the month --}}
        <div class="flex flex-wrap items-start justify-between gap-4 border-t border-line-soft pt-5" data-testid="month-actions">
            <div class="space-y-2">
                @if($panel['state'] === 'locked')
                    <x-status tone="done" icon="shield" :label="__('attendance.timesheets.closed')" />
                    <p class="text-small text-ink-2">{{ __('attendance.timesheets.closed-note', ['date' => $pRecord->locked_at?->format('d/m/Y')]) }}</p>
                @elseif($panel['state'] === 'approved')
                    <x-status tone="done" icon="check" :label="__('attendance.timesheets.confirmed')" />
                    <p class="text-small text-ink-2">{{ __('attendance.timesheets.confirmed-by', ['name' => $pRecord->approver?->profile?->name ?? '—', 'date' => $pRecord->approved_at?->format('d/m/Y')]) }}</p>
                @elseif($pCan['approve'])
                    <x-button icon="check" wire:click="approveMonth({{ $pEmployee->id }})">@lang('attendance.timesheets.confirm-month')</x-button>
                    <p class="max-w-prose text-small text-ink-2">@lang('attendance.timesheets.confirm-why')</p>
                @elseif($pCan['confirm'])
                    <x-button icon="check" wire:click="confirmMonth({{ $pEmployee->id }})">@lang('attendance.timesheets.confirm-own')</x-button>
                @elseif($pTotals['to_decide'] > 0 && $pCan['decide'])
                    <p class="text-small text-ink-2">@lang('attendance.timesheets.answer-first')</p>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($pCan['lock'])<x-button variant="outline" icon="shield" wire:click="lockMonth({{ $pEmployee->id }})">@lang('attendance.workflow.lock')</x-button>@endif
                @if($pCan['reopen'])<x-button variant="quiet" wire:click="reopenMonth({{ $pEmployee->id }})" wire:confirm="{{ __('attendance.workflow.reopen') }}?">@lang('attendance.workflow.reopen')</x-button>@endif
            </div>
        </div>

        {{-- The signed paper: HR's, folded away --}}
        @if($pCan['signed'] || $pSigned)
            <details class="rounded-surface border border-line-soft px-5 py-3" data-testid="month-signed">
                <summary class="min-h-9 cursor-pointer font-semibold text-ink">@lang('attendance.workflow.signed')@if($pSigned) ✓@endif</summary>
                <div class="mt-3 space-y-2">
                    @if($pSigned)
                        <div class="flex flex-wrap items-center gap-3">
                            <a class="font-semibold text-ink underline" target="_blank" href="{{ route('attendance.signed', $pRecord->id) }}">@lang('attendance.workflow.signed-view')</a>
                            @if($pCan['signed'])<x-button variant="link" wire:click="removeSigned">@lang('attendance.workflow.signed-remove')</x-button>@endif
                        </div>
                    @else
                        <p class="text-small text-ink-2">@lang('attendance.workflow.signed-none')</p>
                    @endif
                    @if($pCan['signed'])
                        <form wire:submit.prevent="saveSigned" class="flex flex-wrap items-center gap-2">
                            <input type="file" wire:model="signedFile" accept=".pdf,.jpg,.jpeg,.png" aria-label="{{ __('attendance.workflow.signed') }}" class="rounded-control border border-line bg-card px-3 py-2 text-small text-ink">
                            <x-button type="submit" variant="outline" size="sm">@lang('attendance.workflow.signed-upload')</x-button>
                            @error('signedFile') <p class="w-full text-small font-semibold text-primary" role="alert">{{ $message }}</p> @enderror
                        </form>
                    @endif
                </div>
            </details>
        @endif
    </div>

@else
    {{-- ───────────── Everyone: one line each ───────────── --}}
    @php($counts = ['all' => $items->count(), 'need' => $items->filter(fn ($i) => $statusOf($i)[0] === 'need')->count(), 'done' => $items->filter(fn ($i) => $statusOf($i)[0] === 'done')->count()])
    <div class="space-y-6"
         x-data="{
            q: '', tab: 'all', tick: 0,
            init() { Livewire.hook('commit', ({ succeed }) => succeed(() => this.$nextTick(() => this.tick++))); },
            match(el) { this.tick; const q = this.q.trim().toLowerCase(); return (! q || el.dataset.s.includes(q)) && (this.tab === 'all' || el.dataset.st === this.tab); },
            groupShown(key) { this.tick; return [...this.$root.querySelectorAll('li[data-group]')].some(r => r.dataset.group === key && this.match(r)); },
         }">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-h1 font-extrabold text-ink">{{ $title }}</h1>
            <div class="flex flex-wrap items-center gap-2">
                @include('livewire.attendance.partials.month-stepper')
                @if($blocked)
                    <x-button variant="outline" icon="file" disabled title="{{ __('attendance.timesheets.print-blocked') }}" data-testid="print-blocked">@lang('attendance.export.print')</x-button>
                @else
                    <x-button variant="outline" icon="file" :href="route('attendance.print', $printBase)" :wire:navigate="false" target="_blank" data-testid="print-link">@lang('attendance.export.print')</x-button>
                @endif
                <details class="relative">
                    <summary class="grid size-11 cursor-pointer list-none place-items-center rounded-control text-ink-2 hover:bg-hover" aria-label="{{ __('attendance.timesheets.more') }}"><x-ic name="more" class="size-5" /></summary>
                    <div class="absolute end-0 z-20 mt-1 w-56 overflow-hidden rounded-surface border border-line-soft bg-card py-1 shadow-e3">
                        @if($blocked)
                            <p class="px-4 py-2.5 text-small text-ink-2" data-testid="export-blocked">@lang('attendance.timesheets.print-blocked')</p>
                        @else
                            <a class="block px-4 py-2.5 text-small font-semibold text-ink hover:bg-hover" target="_blank" href="{{ route('attendance.export.pdf', $printBase) }}" data-testid="pdf-link">@lang('attendance.export.pdf')</a>
                            <a class="block px-4 py-2.5 text-small font-semibold text-ink hover:bg-hover" href="{{ route('attendance.export.xlsx', $printBase) }}" data-testid="xlsx-link">@lang('attendance.export.xlsx')</a>
                        @endif
                        @if($canManage)
                            <a class="block border-t border-line-soft px-4 py-2.5 text-small font-semibold text-ink hover:bg-hover" href="{{ route('attendance.upload') }}" wire:navigate>@lang('attendance.timesheets.upload')</a>
                            @if(config('attendance.devices_page'))
                                <a class="block px-4 py-2.5 text-small font-semibold text-ink hover:bg-hover" href="{{ route('attendance.devices') }}" wire:navigate>@lang('attendance.timesheets.devices')</a>
                            @endif
                        @endif
                    </div>
                </details>
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <div class="flex min-w-64 flex-1 gap-1 border-b border-line-soft" role="tablist">
                @foreach(['all' => 'tab-all', 'need' => 'tab-need', 'done' => 'tab-done'] as $tab => $key)
                    @php($red = $tab === 'need' && $counts['need'] > 0)
                    <button type="button" role="tab" x-on:click="tab = '{{ $tab }}'" x-bind:aria-selected="tab === '{{ $tab }}'"
                            class="-mb-px flex min-h-11 items-center gap-2 border-b-3 border-transparent px-3 font-semibold text-ink-2 aria-selected:border-ink aria-selected:text-ink">
                        @lang('attendance.timesheets.'.$key)
                        <span @class(['grid h-5.5 min-w-5.5 place-items-center rounded-full px-1.5 text-caption num', 'bg-primary text-on-brand' => $red, 'bg-surface text-ink' => ! $red])>{{ $counts[$tab] }}</span>
                    </button>
                @endforeach
            </div>

            @if($cities->isNotEmpty())
                <x-forms.select-input id="att-city" wire:model.live="cityId" aria-label="{{ __('attendance.timesheets.city') }}">
                    <option value="">@lang('attendance.timesheets.all-cities')</option>
                    @foreach($cities as $city)
                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                    @endforeach
                </x-forms.select-input>
            @endif

            <div class="relative w-full sm:w-64">
                <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-ink-3"><x-ic name="search" class="size-4" /></span>
                <input type="search" x-model="q" autocomplete="off" data-testid="att-search" placeholder="{{ __('attendance.timesheets.search') }}" aria-label="{{ __('attendance.timesheets.search') }}"
                       class="h-11 w-full rounded-control border border-line bg-card ps-10 pe-3 text-body text-ink">
            </div>
        </div>

        @if($cityName)
            <p class="text-small text-ink-2" data-testid="att-scope">{{ __('attendance.timesheets.showing-city', ['city' => $cityName]) }}</p>
        @endif

        {{-- Month-end in one line: confirm everyone who is ready --}}
        @if($summary['ready_to_approve'] > 0 || $summary['ready_to_lock'] > 0)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-surface border border-line-soft bg-card px-5 py-4 shadow-e1" data-testid="attendance-bulk">
                <p class="font-semibold text-ink">@if($summary['ready_to_approve'] > 0){{ trans_choice('attendance.timesheets.ready-help', $summary['ready_to_approve'], ['count' => $summary['ready_to_approve']]) }}@endif</p>
                <div class="flex flex-wrap gap-2">
                    @if($summary['ready_to_approve'] > 0)
                        <x-button icon="check" wire:click="approveAllReady" wire:confirm="{{ __('attendance.timesheets.confirm-why') }}">{{ __('attendance.timesheets.confirm-all', ['count' => $summary['ready_to_approve']]) }}</x-button>
                    @endif
                    @if($summary['ready_to_lock'] > 0)
                        <x-button variant="outline" icon="shield" wire:click="lockAllApproved" wire:confirm="{{ __('attendance.workflow.lock-all', ['count' => $summary['ready_to_lock']]) }}?">{{ __('attendance.workflow.lock-all', ['count' => $summary['ready_to_lock']]) }}</x-button>
                    @endif
                </div>
            </div>
        @endif

        <div class="space-y-6 transition-opacity" wire:loading.class="opacity-50 pointer-events-none" wire:target="month,cityId,groupBy,shiftMonth">
        @forelse($blocks as $block)
            <section class="space-y-4" wire:key="city-{{ $block['id'] }}">
                @if(count($blocks) > 1 || ! $cityId)
                    <div class="flex flex-wrap items-baseline gap-x-3">
                        <h2 class="text-h3 font-extrabold text-ink">{{ $block['name'] ?? '—' }}</h2>
                        @if($cityList = \App\Models\MailList::emailFor($block['id'] ? (int) $block['id'] : null, null, null, $mailLists))
                            <a href="{{ $cityList->mailto() }}" data-testid="email-city" class="text-small font-semibold text-ink-2 underline hover:text-ink">@lang('mail_lists.email_group')</a>
                        @endif
                    </div>
                @endif

                @foreach($block['groups'] as $group)
                    @php($foldKey = $block['id'].':'.$group['key'])
                    <div wire:key="group-{{ $foldKey }}" x-show="groupShown(@js($foldKey))" x-data="{ open: true }">
                        <div class="flex flex-wrap items-center justify-between gap-2 pb-2">
                            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" data-testid="group-fold" class="flex min-h-9 items-center gap-2 text-start">
                                <x-ic name="chevron" class="size-4 text-ink-3 transition" x-bind:class="open ? 'rotate-90' : 'rtl:rotate-180'" />
                                <h3 class="text-small font-bold text-ink-2"><bdi>{{ $group['name'] ?? __($groupLabel) }}</bdi> · <span class="num">{{ $group['totals']['people'] }}</span></h3>
                            </button>
                            @php($groupList = ($group['id'] ?? null) ? \App\Models\MailList::emailForGroup($block['id'] ? (int) $block['id'] : null, $by === Grouping::PROJECT ? (int) $group['id'] : null, $by === Grouping::DEPARTMENT ? (int) $group['id'] : null, $mailLists) : null)
                            @if($groupList)
                                <a href="{{ $groupList->mailto() }}" data-testid="email-group" class="text-small font-semibold text-ink-2 underline hover:text-ink">@lang('mail_lists.email_group')</a>
                            @endif
                        </div>

                        <ul x-show="open" class="divide-y divide-line-soft overflow-hidden rounded-surface border border-line-soft bg-card shadow-e1">
                            @foreach($group['items'] as $item)
                                @php([$st, $chip] = $statusOf($item))
                                @php($employee = $item['employee'])
                                <li wire:key="row-{{ $employee->id }}" data-group="{{ $foldKey }}" data-st="{{ $st }}"
                                    data-s="{{ mb_strtolower($employee->nameForLocale('en').' '.$employee->nameForLocale('ar').' '.$employee->id) }}" x-show="match($el)">
                                    <button type="button" wire:click="openMonth({{ $employee->id }})" class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-hover sm:gap-4 sm:px-5"
                                            aria-label="{{ __('attendance.timesheets.open-month', ['name' => $employee->name]) }}">
                                        <img src="{{ $avatar($employee) }}" alt="" loading="lazy" class="size-10 shrink-0 rounded-full bg-surface object-cover">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-bold text-ink"><bdi>{{ $employee->name }}</bdi></span>
                                            <span class="block text-caption text-ink-3"><span class="num">ID {{ $employee->id }}</span>@if($item['sheet']['placement']['center']) · <bdi>{{ $item['sheet']['placement']['center'] }}</bdi>@endif</span>
                                        </span>
                                        <span class="hidden text-small text-ink-2 sm:block num">{{ __('attendance.timesheets.days-of', ['worked' => $item['sheet']['totals']['working_days'] + 0, 'planned' => $planned($item['sheet'])]) }}</span>
                                        @if($st === 'need')
                                            <x-tag variant="act" :label="trans_choice('attendance.timesheets.need-you', $item['sheet']['totals']['to_decide'], ['count' => $item['sheet']['totals']['to_decide']])" />
                                        @else
                                            <x-status :tone="$chip[0]" :icon="$chip[1]" :label="$chip[2]" />
                                        @endif
                                        <x-ic name="chevron" class="size-4 shrink-0 text-ink-3 rtl:rotate-180" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </section>
        @empty
            <x-card><p class="py-8 text-center text-ink-2" data-testid="attendance-empty">{{ $items->isEmpty() ? __('attendance.timesheets.no-punches') : __('attendance.timesheets.nothing') }}</p></x-card>
        @endforelse
        </div>
    </div>
@endif
</div>
