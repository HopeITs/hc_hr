<div class="flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm px-6 py-3">

    <div class="flex items-center gap-4 mt-3">
        <h3 class="text-h4 font-extrabold text-ink">
            @lang('general.employees-per-salary-type')
        </h3>

    </div>

    @if ($loadFailed)
        <x-ui.load-error class="flex-1" />
    @elseif ($totalEmployees === 0)
        <x-center-empty-state variant="empty" class="flex-1 min-h-40! border-0 shadow-none" />
    @else
    <div class="grid grid-cols-2 -mt-3 items-center">

        <div class="flex flex-col items-center  pt-6 justify-center">
            <div
                id="salaryTypePieChart"
                class="w-[130px] me-8 pt-2 h-[130px]"
                data-series='@json($series)'
                data-percentage="{{ $highlightPercentage }}"
            ></div>

            {{-- Swatches in the series order of charts/brand-colors.js brandPalette():
                 neutrals only, so the legend and both charts agree in light and dark. --}}
            <div class="flex flex-wrap justify-center items-center gap-x-4 gap-y-1 mt-6">
                @foreach($series as $item)
                    <div class="flex items-center gap-1">
                        <span class="size-2 rounded-full {{ ['bg-ink', 'bg-ink-3', 'bg-line', 'bg-ink-2'][$loop->index % 4] }}" aria-hidden="true"></span>
                        <span class="text-ink-2 font-normal whitespace-nowrap text-caption">
                            {{ $item['label'] }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="w-full -ms-3">
            <canvas
                id="salaryTypeMiniChart"
                class="w-full h-[140px]"
                data-series='@json($series)'
            ></canvas>
        </div>

    </div>
    @endif

</div>
