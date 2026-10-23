<div class="flex w-full h-full min-h-64 flex-col rounded-surface border border-neutral-200 bg-white shadow-[0px_0px_25px_-14px_rgba(88,_89,_91,_0.30)] overflow-hidden p-9">
    <div class="flex w-full">
        <div class="text-h4 font-semibold text-secondary-medium mb-4">@lang('general.contract-type-employees')  </div>
    </div>


    @if ($loadFailed)
        <x-ui.load-error class="flex-1" />
    @elseif (collect($chartData)->sum('value') === 0)
        <x-center-empty-state variant="empty" class="flex-1 min-h-40! border-0 shadow-none" />
    @else
    <div id="chartdiv" data-chart='@json($chartData)' class="w-full h-[200px] "></div>
    @endif
</div>
