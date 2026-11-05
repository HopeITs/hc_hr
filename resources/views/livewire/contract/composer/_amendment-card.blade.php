@php
    use App\Enums\ContractType as ContractTypeEnum;

    /** @var \App\Models\Contract $contract */
    $typeLabels = collect([ContractTypeEnum::FullTime, ContractTypeEnum::PartTime, ContractTypeEnum::Freelance])
        ->mapWithKeys(fn (int $type) => [(string) $type => ContractTypeEnum::getLocalizedName($type)['value']]);
@endphp

{{--
    Amend mode's rail: the terms as they stand today beside what the amendment
    makes of them. "Now" comes worded from the server (ContractAmend::nowCard),
    because the composer may be seeded from a draft rather than the contract.
--}}
<div class="flex h-full flex-col rounded-surface border border-line-soft bg-card shadow-e1"
     x-data="{ typeLabels: @js($typeLabels), before: @js($nowCard ?? []) }">
    <div class="flex items-center gap-3 border-b border-line-soft p-3">
        <img class="size-10 shrink-0 rounded-round object-cover ring-1 ring-line-soft"
             src="{{ $contract->employee?->avatar_url }}" alt="" loading="lazy" />
        <div class="min-w-0 flex-1">
            <p class="truncate text-body font-semibold text-ink">{{ $contract->employee?->name }}</p>
            <p class="truncate text-caption text-ink-2">{{ $contract->position?->title }}</p>
            <p class="truncate text-caption tabular-nums text-ink-3">{{ $currentPeriod }}</p>
        </div>
    </div>

    <dl class="flex flex-1 flex-col px-4">
        <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] gap-2 border-b border-line-soft py-2 text-caption text-ink-2">
            <span></span>
            <span class="text-end">@lang('contracts.amendment.now')</span>
            <span class="text-end font-semibold text-ink">@lang('contracts.amendment.new')</span>
        </div>
        @foreach([
            ['key' => __('general.type'),         'now' => 'before?.type',     'new' => 'typeLabels[type] ?? labels.empty'],
            ['key' => __('general.work-days'),    'now' => 'before?.workDays', 'new' => 'cardWorkDays'],
            ['key' => __('general.weekly-hours'), 'now' => 'before?.hours',    'new' => 'cardHours'],
            ['key' => __('general.salary'),       'now' => 'before?.salary',   'new' => 'cardSalary'],
        ] as $row)
            <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)] items-center gap-2 border-b border-line-soft py-2 last:border-b-0">
                <dt class="truncate text-caption text-ink-2">{{ $row['key'] }}</dt>
                <dd class="truncate text-end text-small tabular-nums text-ink-2" x-text="{{ $row['now'] }}"></dd>
                <dd class="truncate text-end text-small font-semibold tabular-nums text-ink"
                    x-text="{{ $row['new'] }}"></dd>
            </div>
        @endforeach
    </dl>

    <div class="flex items-baseline justify-between gap-3 rounded-b-surface border-t border-line-soft bg-soft px-4 py-2.5">
        <dt class="shrink-0 text-caption font-semibold uppercase tracking-wider text-ink-2">@lang('contracts.amendment.effective-from')</dt>
        <dd class="min-w-0 truncate text-end text-body font-semibold tabular-nums"
            x-bind:class="effectiveFrom ? 'text-ink' : 'text-ink-3'"
            x-text="effectiveFrom ? formatDate(effectiveFrom) : labels.empty"></dd>
    </div>
</div>
