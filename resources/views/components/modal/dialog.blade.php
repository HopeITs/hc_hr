@props(['id'=> 'modal', 'maxWidth' => null ,'title' => 'modal', 'closeAction' => null])

@php($titleId = $id.'-title')

<x-modal :id="$id" :maxWidth="$maxWidth" :labelledby="$titleId" :close-action="$closeAction" {{$attributes}}>
        <!-- Modal content -->
        <div class="relative bg-card dialog:rounded-surface shadow-e1 sheet:min-h-dvh sheet:flex sheet:flex-col">
            <!-- Modal header -->
            {{-- Phones: the title bar stays on screen while a long form scrolls. --}}
            <div class="flex items-center justify-between gap-2 p-4 md:p-4 border-b dialog:rounded-t-surface sheet:sticky sheet:top-0 sheet:z-10 sheet:bg-card sheet:pt-[max(1rem,env(safe-area-inset-top))] border-line-soft">
                <h2 id="{{ $titleId }}" class="text-h4 font-semibold text-ink">
                 {{$title}}
                </h2>

                {{-- Same path as Escape and Cancel: closes locally, never submits. --}}
                <button type="button"
                        x-on:click="close()"
                        aria-label="{{ __('general.a11y.close-dialog') }}"
                        data-modal-close
                        class="-me-2 grid size-11 shrink-0 place-items-center rounded-control text-ink-3 transition hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 motion-reduce:transition-none">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M6 6l12 12M18 6L6 18"/>
                    </svg>
                </button>
            </div>
            <!-- Modal body -->
            {{-- A sheet that ends in a pinned action bar (data-sheet-actions) drops this bottom padding so the bar sits flush. --}}
            <div class="modal-body p-4 md:p-4 sheet:flex-1 sheet:flex sheet:flex-col sheet:pb-[max(1rem,env(safe-area-inset-bottom))]">
              {{$slot}}
            </div>
        </div>
</x-modal>
