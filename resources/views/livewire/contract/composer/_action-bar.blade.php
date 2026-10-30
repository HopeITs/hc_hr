{{--
    On desktop this is the last card in the stack, pinned to the foot of its
    column (lg:mt-auto) so the column fills the grid row whichever side is
    taller. Below lg it detaches to the bottom of the viewport and carries a
    compressed version of the contract card with it.
--}}
<div
    data-tour="contract.save"
    class="rounded-surface border border-line-soft bg-card shadow-e1 lg:mt-auto
           max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-20 max-lg:rounded-b-none
           max-lg:border-x-0 max-lg:border-b-0 max-lg:shadow-e3 max-lg:pb-[env(safe-area-inset-bottom)]"
>
    {{-- Small screens only: who and where, since the rail is hidden there. --}}
    <div class="flex items-center gap-2 border-b border-line-soft px-4 py-2 lg:hidden max-lg:[@media(max-height:30rem)]:hidden"
         x-cloak x-show="selectedEmployee">
        <span class="min-w-0 flex-1 truncate text-small font-medium text-ink"
              x-text="selectedEmployee ? selectedEmployee.label : ''"></span>
        <span class="shrink-0 text-caption text-ink-2" x-text="cardPosition"></span>
        <span x-cloak x-show="capacity" class="shrink-0 text-caption font-medium tabular-nums"
              x-bind:class="capacity && capacity.left === 0 ? 'text-primary' : 'text-ink-2'"
              x-text="cardCapacity"></span>
    </div>

    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 p-3 sm:px-4">
        <p class="flex min-w-0 flex-1 items-center gap-2.5 text-small max-sm:basis-full">
            {{-- One pip per required section. Termination is optional, so it
                 has no pip: it can never be the thing holding the save back. --}}
            <span class="flex gap-1" aria-hidden="true">
                <template x-for="name in requiredSections" :key="name">
                    <span class="h-1.5 w-6 rounded-full transition-colors"
                          x-bind:class="isValid(name) ? 'bg-ink' : 'bg-line'"></span>
                </template>
            </span>
            <span class="truncate font-medium text-ink"
                  x-text="progressLabel"></span>
        </p>

        <div class="flex shrink-0 items-center gap-2 max-sm:w-full max-sm:[&>*]:flex-1 max-sm:[&>*]:justify-center">
            <x-button variant="outline" :href="$cancelUrl">
                @lang('general.cancel')
            </x-button>

            <x-button
                :loading="false"
                x-bind:disabled="!canSubmit"
                x-on:click="submit()"
            >
                <span x-cloak x-show="submitting" class="me-2" role="status">
                    <x-icons.spinner class="size-4 animate-spin" />
                    <span class="sr-only">@lang('general.a11y.loading')</span>
                </span>
                <span x-text="submitting ? @js(__('general.saving')) : @js($submitLabel)"></span>
            </x-button>
        </div>
    </div>
</div>
