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
    $hm = fn (?int $m) => $m === null ? '' : intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
    $printBase = array_filter(['month' => $month, 'city_id' => $cityId, 'group_by' => $by]);
    $groupLabel = $by === Grouping::DEPARTMENT ? 'attendance.timesheets.no-department' : 'attendance.timesheets.no-project';
@endphp

<div class="space-y-6">
    <x-slot:title>{{ __('attendance.timesheets.title') }}</x-slot:title>

    <x-page-header :title="__('attendance.timesheets.title')" :lead="__('attendance.timesheets.lead')">
        <x-slot:actions>
            @if($canManage)
                <x-button variant="outline" icon="plus" :href="route('attendance.upload')">@lang('attendance.timesheets.upload')</x-button>
            @endif
            <x-button icon="file" :href="route('attendance.print', $printBase)" :wire:navigate="false" target="_blank" data-testid="print-link">@lang('attendance.timesheets.print-all')</x-button>
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

            <div>
                <x-forms.input-label for="att-city">@lang('attendance.timesheets.city')</x-forms.input-label>
                <x-forms.select-input id="att-city" wire:model.live="cityId">
                    <option value="">@lang('attendance.timesheets.all-cities')</option>
                    @foreach($cities as $city)
                        <option value="{{ $city->id }}">{{ $city->name }}</option>
                    @endforeach
                </x-forms.select-input>
            </div>

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
                    <input id="att-search" type="search" wire:model.live.debounce.300ms="search" autocomplete="off"
                           class="h-11 w-full rounded-control border border-line bg-card ps-10 pe-3 text-body text-ink">
                </div>
            </div>

            <label class="inline-flex h-11 items-center gap-2 text-body text-ink">
                <input type="checkbox" wire:model.live="onlyToDecide" class="size-4 rounded border-line">
                @lang('attendance.timesheets.only-to-decide')
            </label>
        </div>
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
            @else
                <div class="mt-2"><x-status tone="done" icon="check" :label="__('attendance.timesheets.all-decided')" /></div>
            @endif
        </x-card>
        <x-card>
            <div class="text-small text-ink-2">@lang('attendance.timesheets.overtime')</div>
            <div class="mt-1 text-h2 font-extrabold text-ink num">{{ $summary['overtime_hours'] }} <span class="text-body font-semibold text-ink-2">h</span></div>
            <p class="mt-1 text-small text-ink-2 num">= +{{ $summary['overtime_days'] }} @lang('attendance.timesheets.free-days')</p>
        </x-card>
    </div>

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

    {{-- City, then project or department --}}
    @forelse($blocks as $block)
        <section class="space-y-3" aria-labelledby="city-{{ $block['id'] }}" data-testid="city-block" wire:key="city-{{ $block['id'] }}">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id="city-{{ $block['id'] }}" class="text-h3 font-extrabold text-ink">{{ $block['name'] ?? '—' }}</h2>
                <span class="text-body text-ink-2">{{ trans_choice('attendance.timesheets.people-count', $block['totals']['people']) }}</span>
                @if($block['totals']['to_decide'] > 0)
                    <x-status tone="attn" icon="alert" :label="__('attendance.timesheets.to-decide', ['count' => $block['totals']['to_decide']])" />
                @endif
            </div>

            @foreach($block['groups'] as $group)
                @php($foldKey = $block['id'].':'.$group['key'])
                @php($folded = $collapsed[$foldKey] ?? false)
                <x-card class="!p-0 overflow-hidden" data-testid="group-card" wire:key="group-{{ $foldKey }}">
                    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-line-soft bg-soft px-4 py-3">
                        <button type="button" wire:click="toggleGroup('{{ $foldKey }}')" aria-expanded="{{ $folded ? 'false' : 'true' }}"
                                class="flex min-h-11 items-center gap-2 text-start">
                            <x-ic name="chevron" class="size-4 shrink-0 text-ink-2 transition {{ $folded ? 'rtl:rotate-180' : 'rotate-90' }}" />
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
                                <span class="text-small text-ink-2 num">{{ $group['totals']['overtime_hours'] }} h = +{{ $group['totals']['overtime_days'] }} @lang('attendance.timesheets.free-days')</span>
                            @endif
                            <x-button variant="outline" size="sm" icon="file" :wire:navigate="false" target="_blank"
                                      :href="route('attendance.print', $printBase + ['city_id' => $block['id'], 'group' => $group['id'] ?? 'none'])">
                                @lang('attendance.timesheets.print-group')
                            </x-button>
                        </div>
                    </div>

                    @unless($folded)
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-max text-start text-body">
                                <thead>
                                    <tr class="text-caption text-ink-3">
                                        <th scope="col" class="sticky start-0 z-10 min-w-48 bg-card px-4 py-2 text-start font-semibold">@lang('attendance.timesheets.person')</th>
                                        <th scope="col" class="px-3 py-2 text-center font-semibold">@lang('attendance.timesheets.days')</th>
                                        <th scope="col" class="px-3 py-2 text-center font-semibold">@lang('attendance.timesheets.overtime')</th>
                                        <th scope="col" class="px-3 py-2 text-center font-semibold">@lang('attendance.timesheets.decide')</th>
                                        @foreach($days as $day)
                                            <th scope="col" class="px-px py-2 text-center font-semibold num">
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
                                        <tr class="border-t border-line-soft hover:bg-hover" wire:key="sheet-{{ $employee->id }}">
                                            <th scope="row" class="sticky start-0 z-10 bg-card px-4 py-2 text-start font-normal">
                                                <div class="font-semibold text-ink"><bdi>{{ $employee->name }}</bdi></div>
                                                <div class="text-caption text-ink-3"><span class="num">ID {{ $employee->id }}</span>@if($sheet['placement']['center']) · <bdi>{{ $sheet['placement']['center'] }}</bdi>@endif</div>
                                            </th>
                                            <td class="px-3 text-center font-semibold num">{{ $sheet['totals']['working_days'] }}</td>
                                            <td class="px-3 text-center text-small num whitespace-nowrap">
                                                @if($sheet['totals']['overtime_hours'] > 0)
                                                    {{ $sheet['totals']['overtime_hours'] }} h = +{{ $sheet['totals']['overtime_days'] }}
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
                                            @foreach($sheet['rows'] as $row)
                                                @php([$classes, $icon] = $cell[$row['kind']])
                                                @php($label = __('attendance.timesheets.status.'.$row['kind']).' · '.$row['date']->format('d/m').($row['worked_minutes'] ? ' · '.$hm($row['worked_minutes']) : ''))
                                                <td class="px-px py-1">
                                                    @if($row['decidable'] && $canManage)
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
                    @endunless
                </x-card>
            @endforeach
        </section>
    @empty
        <x-card>
            <p class="py-8 text-center text-body text-ink-2" data-testid="attendance-empty">@lang('attendance.timesheets.nothing')</p>
        </x-card>
    @endforelse

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
