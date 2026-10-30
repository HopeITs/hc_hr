<div class="w-full bg-card rounded-surface shadow-e1 border border-line-soft overflow-hidden">
    <div class="flex justify-between items-center px-6 py-4 border-b border-line-soft">
        <h3 class="text-h4 font-extrabold text-ink">
            {{ __('Employees per Job Level') }}
        </h3>
        <span class="num text-caption font-semibold bg-surface text-ink-2 px-3 py-1 rounded-round">
            {{ now()->year }}
        </span>
    </div>

    <div class="p-6 flex items-center justify-center">
        <div id="jobLevelsChart" class="w-full h-80"></div>
    </div>
</div>
