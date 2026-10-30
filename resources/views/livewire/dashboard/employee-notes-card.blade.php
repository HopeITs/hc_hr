<div class="h-full w-full">
    <div
        x-data="notesBoard()"
        x-init="init(@js($this->notes->pluck('id')))"
        class="relative h-full w-full">
        <div class="flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
            <x-dashboard-cards.card-header :title="__('general.notes')" :count="$loadFailed ? null : $this->notes->count()">
                <x-slot:icon>
                    <img class="block size-full object-contain" src="{{ asset('assets/icons/notes.svg') }}" alt="">
                </x-slot:icon>
                <x-slot:actions>
                    <x-button size="sm" icon="plus" :loading="false" x-on:click="openNewNoteModal()">
                        @lang('general.add-note')
                    </x-button>
                </x-slot:actions>
            </x-dashboard-cards.card-header>

            <div data-csb-exclude class="mx-6 mt-[11px] mb-4 pe-2 flex-1 min-h-0 overflow-y-auto custom-scrollbar">
                <div class="pt-1">

                    @if ($loadFailed)
                        <x-ui.load-error class="min-h-40" />
                    @else
                    <table class="w-full text-body text-ink">
                        <thead>
                        <tr class="border-b border-b-line text-ink-2">
                            <th class="sticky top-0 z-10 bg-card py-1 w-10 text-center font-medium"></th>
                            <th class="sticky top-0 z-10 bg-card py-1 font-medium text-start"></th>
                            <th class="sticky top-0 z-10 bg-card py-1 px-3 ltr:text-right rtl:text-left font-medium">@lang('general.actions')</th>
                            <th class="sticky top-0 z-10 bg-card py-1 text-center font-medium w-16">@lang('general.done')!</th>
                        </tr>
                        </thead>

                        <tbody class="relative z-0">
                        @forelse($notes as $index => $note)
                            <tr
                                data-id="{{ $note->id }}"
                                class="border-b border-line-soft last:border-0 hover:bg-hover transition">
                                <td class="text-center font-semibold text-ink-2 align-middle num">
                                    {{ $index + 1 }}.
                                </td>

                                <td class="text-ink-2 font-medium align-middle truncate @if($note->is_done) line-through @endif">
                                    {{ Str::limit($note->content, 60, '...') }}
                                </td>

                                <td class="w-full py-2 px-4">

                                    <div class="flex  ltr:-me-12 rtl:-ml-8 items-left rtl:justify-start ltr:text-left rtl:text-right ltr:justify-end gap-4 rtl:flex-row-reverse">

                                        {{-- Edit --}}
                                        <button
                                            @click="openEditModal({{ $note->id }}, @js($note->content))"
                                            class="inline-flex items-center justify-center text-ink-2 hover:text-ink transition"
                                            title="{{ __('general.edit-note') }}"
                                            aria-label="{{ __('general.edit-note') }}">
                                            <img class="w-4 h-4" src="{{asset('assets/icons/Edit.svg')}}" alt="">
                                        </button>

                                        {{-- Delete --}}
                                        <button
                                            x-on:click="if (await $confirm({ title: @js(__('general.delete-note')), message: @js(__('general.delete-note-text')) })) $wire.delete({{ $note->id }})"
                                            class="inline-flex me-1 items-center justify-center text-ink-2 hover:text-ink transition"
                                            title="{{ __('general.delete-note') }}"
                                            aria-label="{{ __('general.delete-note') }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none"
                                                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>


                                        {{-- Done Checkbox --}}
                                        <button
                                            @click="toggleCheckbox({{ $note->id }})"
                                            title="{{ $note->is_done ? __('dashboard.mark-not-done') : __('dashboard.mark-done') }}"
                                            aria-label="{{ $note->is_done ? __('dashboard.mark-not-done') : __('dashboard.mark-done') }}"
                                            aria-pressed="{{ $note->is_done ? 'true' : 'false' }}"
                                            class="inline-flex items-center justify-center mt-[1px] w-4 h-4 rounded-full border border-ink-3 hover:border-ink transition">
                                            @if($note->is_done)
                                                <svg xmlns="http://www.w3.org/2000/svg"
                                                     class="w-4 h-4 rounded-full bg-ink text-on-ink"
                                                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                                </svg>
                                            @endif
                                        </button>

                                    </div>

                                </td>

                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center py-6 pt-[76px] text-ink-2 text-small">
                                    {{-- data-tile-empty: the grid folds the tile to one line (DESIGN.md, Dashboard tiles). --}}
                                    <p data-tile-empty>@lang('general.no-notes-1') <span class="font-bold text-ink">“@lang('general.add-note')”</span> @lang('general.no-notes-2') </p>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                    @endif
                </div>
            </div>
        </div>

        {{-- Moved to <body>: inside a dashboard tile (a size container that clips its card)
             a fixed dialog would be held to the tile. --}}
        <template x-teleport="body">
        <div
            x-show="showModal"
            x-cloak
            @keydown.escape.window="showModal=false"
            @click.self="showModal=false"
            class="fixed inset-0 z-50 flex items-center justify-center bg-backdrop backdrop-blur-sm"
            x-transition>
            {{-- A real modal: named by its heading, focus held inside while open and
                 returned to the button that opened it when it closes (x-trap). --}}
            <div role="dialog" aria-modal="true" aria-labelledby="notes-dialog-title"
                 x-trap.noscroll="showModal"
                 class="w-full max-w-md rounded-surface bg-card p-9 shadow-e3 border border-line-soft">
                <div class="flex items-start justify-between mb-4">
                    <h3 id="notes-dialog-title" class="text-h4 font-extrabold text-ink tracking-tight"
                        x-text="modalMode === 'create' ? @js(__('general.add-new-note')) : @js(__('general.edit-note'))"></h3>
                    <button type="button" @click="showModal=false" aria-label="{{ __('dashboard.close') }}" class="-m-3 inline-grid size-11 shrink-0 place-items-center rounded-control text-ink-2 hover:text-ink transition">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="space-y-4">

                    <div>
                        <label for="notes-dialog-content" class="block text-body font-medium text-ink mb-1">@lang('general.note-content')</label>
                        <textarea
                            id="notes-dialog-content"
                            x-model="modalContent"
                            placeholder="{{__('general.write-your-note')}}..."
                            class="block w-full h-28 rounded-control border-[1.5px] border-line bg-field text-body text-ink py-2 px-3 resize-none transition"
                        ></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-button :loading="false" x-on:click="saveModal()">
                        <span x-show="modalMode === 'create'">@lang('general.add-note')</span>
                        <span x-show="modalMode !== 'create'" x-cloak>@lang('general.save-changes')</span>
                    </x-button>
                    <x-button variant="outline" :loading="false" x-on:click="showModal=false">
                        @lang('general.cancel')
                    </x-button>
                </div>
            </div>
        </div>
        </template>

    </div>

    {{-- @script, not a plain <script>: the card is a lazy dashboard tile, which arrives
         in a Livewire update where a plain script never runs. Livewire runs this
         before the card's markup is morphed in, so notesBoard() exists by then. --}}
    @script
    <script>
        window.notesBoard = function () {
            return {
                showModal: false,
                modalMode: 'create',
                modalId: null,
                modalContent: '',
                modalType: 'note',

                init(ids) {
                    this.order = Array.isArray(ids) ? ids : [];
                },

                openNewNoteModal() {
                    this.modalMode = 'create';
                    this.modalId = null;
                    this.modalContent = '';
                    this.modalType = 'note';
                    this.showModal = true;
                },

                openEditModal(id, content) {
                    this.modalMode = 'edit';
                    this.modalId = id;
                    this.modalContent = content || '';
                    this.modalType = 'note';
                    this.showModal = true;
                },

                saveModal() {
                    const lw = Livewire.find('{{ $this->getId() }}');
                    if (!lw) return;

                    if (this.modalMode === 'create') {
                        lw.call('createNote', this.modalContent, this.modalType);
                    } else {
                        lw.call('updateContent', this.modalId, this.modalContent);
                    }

                    this.showModal = false;
                },

                toggleCheckbox(id) {
                    const lw = Livewire.find('{{ $this->getId() }}');
                    if (!lw) return;
                    lw.call('toggleDone', id);
                },
            };
        };
    </script>
    @endscript

    <style>
        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }
        .custom-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: var(--color-line) transparent;
            scrollbar-gutter: stable;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background-color: var(--color-line);
            border-radius: 999px;
        }
    </style>
</div>
