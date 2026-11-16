@php
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
    $printParams = array_filter(['month' => $month, 'city_id' => $cityId]);
@endphp

<div class="space-y-6">
    <x-slot:title>{{ __('attendance.timesheets.title') }}</x-slot:title>

    <x-page-header :title="__('attendance.timesheets.title')" :lead="__('attendance.timesheets.lead')">
        <x-slot:actions>
            @if($canManage)
                <x-button variant="outline" :href="route('attendance.upload')">@lang('attendance.timesheets.upload')</x-button>
            @endif
            <x-button icon="file" :href="route('attendance.print', $printParams)" :wire:navigate="false" target="_blank" data-testid="print-link">@lang('attendance.timesheets.print')</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <x-forms.input-label for="att-month">@lang('attendance.timesheets.month')</x-forms.input-label>
                <input id="att-month" type="month" wire:model.live="month"
                       class="rounded-control border border-line bg-card px-3 py-2 text-body text-ink num">
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
            <label class="inline-flex items-center gap-2 pb-2 text-body text-ink">
                <input type="checkbox" wire:model.live="onlyToDecide" class="size-4 rounded border-line">
                @lang('attendance.timesheets.only-to-decide')
            </label>
        </div>
    </x-card>

    <x-card data-testid="attendance-banner">
        @if($toDecide > 0)
            <div class="flex flex-wrap items-center gap-3">
                <x-status tone="attn" icon="alert" :label="__('attendance.timesheets.to-decide', ['count' => $toDecide])" />
                <p class="text-body text-ink-2">@lang('attendance.timesheets.to-decide-help')</p>
            </div>
        @else
            <div class="flex items-center gap-3">
                <x-status tone="done" icon="check" :label="__('attendance.timesheets.all-decided')" />
            </div>
        @endif
    </x-card>

    <x-card class="overflow-x-auto" data-testid="attendance-grid">
        @if($sheets->isEmpty())
            <p class="text-body text-ink-2">@lang('attendance.timesheets.nothing')</p>
        @else
            @php($daysInMonth = $monthDate->daysInMonth)
            <table class="w-full min-w-max text-start text-body">
                <thead>
                    <tr class="border-b border-line-soft text-caption text-ink-3">
                        <th class="py-2 pe-4 text-start font-semibold">@lang('attendance.timesheets.person')</th>
                        @for($d = 1; $d <= $daysInMonth; $d++)
                            <th class="px-0.5 text-center font-semibold num">{{ $d }}</th>
                        @endfor
                        <th class="px-3 text-center font-semibold">@lang('attendance.timesheets.days')</th>
                        <th class="px-3 text-center font-semibold">@lang('attendance.timesheets.overtime')</th>
                        <th class="px-3 text-center font-semibold">@lang('attendance.timesheets.decide')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sheets as $item)
                        @php($employee = $item['employee'])
                        @php($sheet = $item['sheet'])
                        <tr class="border-b border-line-soft" wire:key="sheet-{{ $employee->id }}">
                            <td class="py-2 pe-4">
                                <div class="font-semibold text-ink"><bdi>{{ $employee->name }}</bdi></div>
                                <div class="text-caption text-ink-3 num">ID {{ $employee->id }}</div>
                            </td>
                            @foreach($sheet['rows'] as $row)
                                @php([$classes, $icon] = $cell[$row['kind']])
                                @php($label = __('attendance.timesheets.status.'.$row['kind']).' · '.$row['date']->format('d/m'))
                                <td class="px-0.5 py-1">
                                    @if($row['decidable'] && $canManage)
                                        <button type="button" wire:click="openDay({{ $employee->id }}, '{{ $row['date']->toDateString() }}')"
                                                title="{{ $label }}" aria-label="{{ $label }}"
                                                class="grid size-7 place-items-center rounded-control {{ $classes }}">
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
                            <td class="px-3 text-center font-semibold num">{{ $sheet['totals']['working_days'] }}</td>
                            <td class="px-3 text-center text-small num">
                                @if($sheet['totals']['overtime_hours'] > 0)
                                    {{ $sheet['totals']['overtime_hours'] }} h = +{{ $sheet['totals']['overtime_days'] }}
                                @else
                                    <span class="text-ink-3">–</span>
                                @endif
                            </td>
                            <td class="px-3 text-center font-semibold num">
                                @if($sheet['totals']['to_decide'] > 0)
                                    <x-status tone="attn" icon="alert" :label="(string) $sheet['totals']['to_decide']" />
                                @else
                                    <span class="text-ink-3">–</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-small text-ink-2">
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
        @endif
    </x-card>

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
                    <label class="flex cursor-pointer items-start gap-3 rounded-surface border border-line p-3 has-[:checked]:border-ink has-[:checked]:border-2">
                        <input type="radio" wire:model="dayKind" value="worked" class="mt-1 size-4">
                        <span><span class="block font-semibold text-ink">@lang('attendance.timesheets.worked')</span><span class="text-small text-ink-2">@lang('attendance.timesheets.worked-help')</span></span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-surface border border-line p-3 has-[:checked]:border-ink has-[:checked]:border-2">
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
