<div data-tour="contract-details.pager" class="no-print mx-auto w-full max-w-[210mm] rounded-xl sticky bottom-0 mt-8 z-20 pb-[env(safe-area-inset-bottom)] bg-white/80 dark:bg-neutral-900/80 backdrop-blur
            border-t border-neutral-200 dark:border-neutral-700">
    <div class="mx-auto max-w-5xl px-4 py-3 flex items-center justify-between gap-3 max-sm:flex-col max-sm:items-stretch max-sm:gap-2">
        <div class="text-sm opacity-70 max-sm:text-center">Page {{ $pageNo }} of {{ $totalPages }}</div>

        <div class="flex items-center gap-2 max-sm:[&>button]:flex-1 max-sm:[&>button]:min-h-11">
            <button type="button" class="px-3 py-2 rounded-lg border border-neutral-300 hover:bg-neutral-50 disabled:opacity-40"
                    wire:click="prevPage" @disabled($pageNo === 1)>
                @lang('general.previous')
            </button>

            <div class="hidden sm:flex items-center gap-1">
                @for($i=1; $i<= $totalPages; $i++)
                    <button type="button" wire:click="setPage({{ $i }})"
                            class="px-3 py-1 rounded-lg border
                  {{ $pageNo === $i ? 'bg-primary text-white border-primary' : 'border-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800' }}">
                        {{ $i }}
                    </button>
                @endfor
            </div>

            <button type="button" class="px-3 py-2 rounded-lg bg-primary text-white disabled:opacity-40"
                    wire:click="nextPage" @disabled($pageNo === $totalPages)>
                @lang('general.next')
            </button>
        </div>
    </div>
</div>
