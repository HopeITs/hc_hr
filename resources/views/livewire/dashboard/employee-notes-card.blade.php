<div class="h-full w-full">
    <div
        x-data="notesBoard()"
        x-init="init(@js($this->notes->pluck('id')))"
        class="relative h-full w-full">
        <div class="flex h-full w-full flex-col overflow-hidden rounded-surface border border-neutral-200 bg-white shadow-[0px_0px_25px_-14px_rgba(88,_89,_91,_0.30)]">
            <x-dashboard-cards.card-header :title="__('general.notes')" :count="$this->notes->count()">
                <x-slot:icon>
                    <img class="block size-full object-contain" src="{{ asset('assets/icons/notes.svg') }}" alt="">
                </x-slot:icon>
                <x-slot:actions>
                    <button
                        @click="openNewNoteModal()"
                        class="flex items-center gap-2 rounded-[10px] bg-primary px-3 py-2 text-xs font-medium text-white transition hover:bg-primary-hover">
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        @lang('general.add-note')
                    </button>
                </x-slot:actions>
            </x-dashboard-cards.card-header>

            <div data-csb-exclude class="mx-6 mt-[11px] mb-4 pe-2 flex-1 min-h-0 overflow-y-auto custom-scrollbar">
                <div class="pt-1">

                    <table class="w-full text-body text-neutral-800 ">
                        <thead>
                        <tr class="border-b border-b-secondary-light text-secondary-medium">
                            <th class="sticky top-0 z-10 bg-white py-1 w-10 text-center font-medium"></th>
                            <th class="sticky top-0 z-10 bg-white py-1 font-medium text-left"></th>
                            <th class="sticky top-0 z-10 bg-white py-1 px-3 ltr:text-right rtl:text-left font-medium">@lang('general.actions')</th>
                            <th class="sticky top-0 z-10 bg-white py-1 text-center font-medium w-16">@lang('general.done')!</th>
                        </tr>
                        </thead>

                        <tbody class="relative z-0">
                        @forelse($notes as $index => $note)
                            <tr
                                data-id="{{ $note->id }}"
                                class="border-b border-secondary-light last:border-0 hover:bg-neutral-50 transition">
                                <td class="text-center font-semibold text-secondary-medium align-middle">
                                    {{ $index + 1 }}.
                                </td>

                                <td class="text-secondary-light font-medium align-middle truncate @if($note->is_done) line-through @endif">
                                    {{ Str::limit($note->content, 60, '...') }}
                                </td>

                                <td class="w-full py-2 px-4">

                                    <div class="flex  ltr:-me-12 rtl:-ml-8 items-left rtl:justify-start ltr:text-left rtl:text-right ltr:justify-end gap-4 rtl:flex-row-reverse">

                                        {{-- Edit --}}
                                        <button
                                            @click="openEditModal({{ $note->id }}, @js($note->content))"
                                            class="inline-flex items-center justify-center text-secondary-medium hover:text-primary transition"
                                            title="Edit Note">
                                            <img class="w-4 h-4" src="{{asset('assets/icons/Edit.svg')}}" alt="">
                                        </button>

                                        {{-- Delete --}}
                                        <button
                                            @click="confirmDelete({{ $note->id }})"
                                            class="inline-flex me-1 items-center justify-center text-secondary-light hover:text-primary transition"
                                            title="Delete Note">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none"
                                                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                      d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>


                                        {{-- Done Checkbox --}}
                                        <button
                                            @click="toggleCheckbox({{ $note->id }})"
                                            title="Toggle Done"
                                            class="inline-flex items-center justify-center mt-[1px] w-4 h-4 rounded-full border border-neutral-400 hover:border-primary transition">
                                            @if($note->is_done)
                                                <svg xmlns="http://www.w3.org/2000/svg"
                                                     class="w-4 h-4 rounded-full bg-primary text-small text-white"
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
                                <td colspan="4" class="text-center py-6 pt-[76px] text-secondary-light font-light text-[11.2px]">
                                    <p>@lang('general.no-notes-1') <span class="text-primary font-light">“@lang('general.add-note')”</span> @lang('general.no-notes-2') </p>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div
            x-show="showModal"
            x-cloak
            @keydown.escape.window="showModal=false"
            @click.self="showModal=false"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
            x-transition>
            <div class="w-full max-w-md rounded-surface bg-white p-9 shadow-e3 border border-neutral-100">
                <div class="flex items-start justify-between mb-4">
                    <h3 class="text-h4 font-semibold text-neutral-900 tracking-tight"
                        x-text="modalMode === 'create' ? '{{__('general.add-new-note')}}' : '{{__('general.edit-note')}}'"></h3>
                    <button @click="showModal=false" class="text-secondary-light hover:text-secondary-medium transition">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none"
                             viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="space-y-4">

                    <div>
                        <label class="block text-body font-medium text-neutral-800 mb-1">@lang('general.note-content')</label>
                        <textarea
                            x-model="modalContent"
                            placeholder="{{__('general.write-your-note')}}..."
                            class="block w-full h-28 rounded-surface border border-neutral-300 text-body text-neutral-800 py-2 px-3 resize-none focus:ring-2 focus:ring-primary focus:border-primary transition"
                        ></textarea>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        @click="saveModal()"
                        class="inline-flex items-center justify-center bg-primary hover:bg-primary-hover text-white px-4 py-2 rounded-surface text-small font-medium shadow-e1 transition"
                        x-text="modalMode === 'create' ? '{{__('general.add-note')}}' : '{{__('general.save-changes')}}'">
                    </button>
                    <button
                        @click="showModal=false"
                        class="inline-flex items-center justify-center border border-neutral-300 px-4 py-2 rounded-surface text-small font-medium text-neutral-800 hover:bg-neutral-100 transition">
                        @lang('general.cancel')
                    </button>
                </div>
            </div>
        </div>

        <div
            x-show="showDeleteModal"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
            x-transition>
            <div class="w-full max-w-sm bg-white rounded-surface shadow-e3 border border-neutral-200 p-9">
                <h2 class="text-h4 font-semibold text-neutral-800 mb-3">@lang('general.delete-note')</h2>
                <p class="text-body text-secondary-medium mb-6">
                    @lang('general.delete-note-text')
                </p>

                <div class="flex justify-end space-x-3">
                    <button
                        @click="showDeleteModal = false"
                        class="px-4 py-2 rounded-surface border border-neutral-300 text-small font-medium text-neutral-800 hover:bg-neutral-100 transition">
                        @lang('general.cancel')
                    </button>
                    <button
                        @click="
                            Livewire.find('{{ $this->getId() }}').call('delete', deleteId);
                            showDeleteModal = false;"
                        class="px-4 py-2 rounded-surface bg-primary text-white text-small font-medium hover:bg-primary-hover transition">
                        @lang('general.delete')
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script nonce="{{ Vite::cspNonce() }}">
        function notesBoard() {
            return {
                showModal: false,
                modalMode: 'create',
                modalId: null,
                modalContent: '',
                modalType: 'note',
                showDeleteModal: false,
                deleteId: null,

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

                confirmDelete(id) {
                    this.deleteId = id;
                    this.showDeleteModal = true;
                },
            };
        }
    </script>

    <style>
        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }
        .custom-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: rgba(88, 89, 91, 0.22) transparent;
            scrollbar-gutter: stable;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background-color: rgba(88, 89, 91, 0.22);
            border-radius: 999px;
        }
    </style>
</div>
