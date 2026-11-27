<x-slot:title>
    {{ __('general.folders') }} · {{ $isShared ? __('general.folders-shared-with-me') : __('general.folders-mine') }}
</x-slot:title>

<div>
    <livewire:layout.breadcrumbs
        :title="__('general.folders')"
        :crumbs="$finalCrumbs"
    />

    {{-- One toolbar: the mode toggle, and the actions it allows at the end. The toggle
         sets the row's height, so a mode without actions (Shared with me, for a
         non-admin) does not move the folders. Phones: the actions are icon-only. --}}
    <div class="mt-6"
        @if ($currentFolder && $canManageCurrentFolder)
        x-data="{ uploading: false, progress: 0 }"
        x-on:livewire-upload-start="uploading = true"
        x-on:livewire-upload-finish="uploading = false; progress = 0"
        x-on:livewire-upload-error="uploading = false; progress = 0"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
        @endif
    >
        <div class="flex items-center gap-2">
            {{-- Ownership, not shared/unshared: "Shared with me" also lists other people's
                 private folders they gave this user access to. Links, so each mode has its
                 own URL (bookmarks, back/forward); switching starts at that mode's root. --}}
            <nav aria-label="{{ __('general.folders-scope') }}"
                 class="grid flex-1 min-w-0 grid-cols-2 gap-1 p-1 rounded-[18px] bg-surface sm:flex-none sm:w-80">
                @foreach ([false => __('general.folders-mine'), true => __('general.folders-shared-with-me')] as $shared => $label)
                    <a href="{{ \App\Livewire\Archives\ArchivedFilesModule::folderUrl(null, (bool) $shared) }}" wire:navigate
                       @if ($isShared === (bool) $shared) aria-current="page" @endif
                       class="inline-flex items-center justify-center min-h-11 px-1.5 sm:px-4 rounded-[14px] text-small sm:text-body leading-tight whitespace-nowrap text-center transition {{ $isShared === (bool) $shared ? 'bg-card text-ink font-bold shadow-e1' : 'text-ink-2 font-semibold hover:text-ink hover:bg-hover' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            <div class="flex items-center gap-2 ms-auto shrink-0">
                @if ($canManageCurrentFolder)
                    <x-button icon="plus" :loading="false" wire:click="$set('showCreateFolderModal', true)" class="max-sm:min-w-11 max-sm:px-3">
                        <span class="max-sm:sr-only">{{ __('general.new_folder') }}</span>
                    </x-button>
                @endif

                @if ($currentFolder && $canManageCurrentFolder)
                    <label for="file-upload" class="hc-btn hc-btn--outline cursor-pointer max-sm:min-w-11 max-sm:px-3">
                        <x-icons.upload class="size-5" />
                        <span class="max-sm:sr-only">{{ __('general.upload_files') }}</span>
                        <input x-ref="fileInput" id="file-upload" wire:model.live="filesToUpload" type="file" class="sr-only" multiple accept=".jpg,.jpeg,.png,.svg,.pdf,.docx,.xlsx,.pptx,.txt">
                    </label>
                @endif
            </div>
        </div>

        {{-- HR-039: private folders are not hidden from the system administrator (support access). --}}
        @unless ($isShared)
            <p class="mt-3 text-small text-ink-2">{{ __('general.folders-admin-access-note') }}</p>
        @endunless

        @if ($currentFolder && $canManageCurrentFolder)
            <div x-show="uploading" x-cloak class="mt-3 w-full bg-surface rounded-full h-2.5">
                <div class="bg-ink h-2.5 rounded-full" :style="`width: ${progress}%`"></div>
            </div>
            @error('filesToUpload')
                <p class="hc-err mt-2">{{ $message }}</p>
            @enderror
        @endif
    </div>

    {{-- The Policies folder: what its files become, and how to update one. --}}
    @if ($inPolicyFolder)
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-surface border border-line bg-soft p-4" data-testid="policy-folder-banner">
            <p class="text-small text-ink">{{ __('policies.folder-banner') }}</p>
            @if (\App\Livewire\Policies\Index::canOpen(auth()->user()))
                <x-button :href="route('policies.index')" variant="outline" size="sm">{{ __('policies.title') }}</x-button>
            @endif
        </div>
    @endif

    {{-- Replacing a policy's file with its new version. --}}
    @if ($replacingMediaId)
        <div class="mt-3 space-y-2 rounded-surface border border-ink bg-card p-4" data-testid="policy-replace">
            <p class="text-small text-ink">{{ __('policies.replace-hint', ['name' => $replacingName]) }}</p>
            <div class="flex flex-wrap items-center gap-2">
                <input type="file" wire:model="replacementFile" accept=".pdf,.docx,.txt" class="text-small text-ink" data-testid="policy-replace-file">
                <x-button variant="quiet" size="sm" wire:click="cancelReplacePolicyFile">{{ __('general.cancel') }}</x-button>
                <span class="text-small text-ink-2" wire:loading wire:target="replacementFile">…</span>
            </div>
            @error('replacementFile') <p class="hc-err">{{ $message }}</p> @enderror
        </div>
    @endif

    {{-- No "New folder" at the top of Shared with me: say where to make one instead. --}}
    @if ($isShared && ! $currentFolder && ! $canManageCurrentFolder)
        <p class="mt-3 text-small text-ink-2" data-testid="shared-root-hint">{{ __('folders.shared-root-hint') }}</p>
    @endif

    <div
        @if ($currentFolder && $canManageCurrentFolder)
        x-data="{ dragging: false }"
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="dragging = false"
        x-on:drop.prevent="
            dragging = false;
            const input = document.getElementById('file-upload');
            if (input) {
                input.files = $event.dataTransfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        "
        @endif
        class="relative p-4 mt-4 transition border border-line-soft shadow-e1 bg-soft/80 sm:rounded-surface"
        @if ($currentFolder && $canManageCurrentFolder) :class="dragging ? 'border-ink bg-surface' : ''" @endif
    >
        @if ($currentFolder && $canManageCurrentFolder)
            <div x-show="dragging" x-transition class="absolute inset-3 z-10 flex items-center justify-center border-2 border-dashed rounded-surface pointer-events-none border-ink bg-card/80" style="display: none;">
                <div class="text-center">
                    <x-icons.upload class="mx-auto size-10 text-ink-3" />
                    <p class="mt-2 text-body font-semibold text-ink">Drop files here to upload</p>
                    <p class="text-small text-ink-2">JPG, PNG, SVG, PDF, DOCX, XLSX, PPTX and TXT files are supported</p>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
            @forelse($folders as $folder)
            <div class="relative group ">
                <a href="{{ \App\Livewire\Archives\ArchivedFilesModule::folderUrl($folder->id, $isShared) }}" wire:navigate class="flex flex-col items-center justify-center h-40 p-4 text-center transition bg-card border border-line-soft rounded-surface shadow-e1 hover:-translate-y-0.5 hover:border-line hover:shadow-e3">
                    <span class="flex items-center justify-center rounded-surface size-16 bg-transparent ring-1 ring-transparent group-hover:bg-ink group-hover:ring-ink">
                        <x-icons.folder class="w-10 h-10 text-ink-2 group-hover:text-on-ink" />
                    </span>
                    <span class="w-full mt-3 text-body font-semibold text-ink truncate">{{ $folder->name }}</span>
                    @php $folderRole = $folderRoles[$folder->id] ?? null; @endphp
                    @if ($folder->user_id === auth()->id())
                        <span class="mt-1 text-small text-ink-2">{{ __('general.by_me') }}</span>
                    @else
                        <span class="mt-1 text-small text-ink-2">{{ __('general.by') }}: {{ $folder->is_policy_library ? __('policies.folder-owner') : (optional($folder->creator?->profile)->name ?? __('general.unknown')) }}</span>
                        {{-- What this person may do here, in words (status = words, not colour). --}}
                        @if ($folderRole && $folderRole !== \App\Services\Folders\FolderAccess::OWNER)
                            <span class="mt-0.5 text-caption text-ink-3" data-testid="folder-role">{{ __('folders.roles.'.$folderRole) }}</span>
                        @endif
                    @endif
                </a>

                {{-- This folder on the viewer's dashboard (DashboardLayout::ITEMS, item.folder.<id>). --}}
                <div class="absolute top-2 start-2">
                    <livewire:dashboard.add-to-dashboard :tile="\App\Support\Dashboard\DashboardLayout::itemKey('folder', $folder->id)" :compact="true" :key="'atd-folder-'.$folder->id" />
                </div>

                {{-- Editors share and rename; only the owner deletes (FolderAccess). --}}
                @if (in_array($folderRole, [\App\Services\Folders\FolderAccess::EDITOR, \App\Services\Folders\FolderAccess::OWNER], true))
                <div x-data="{ open: false }" @click.away="open = false" class="absolute top-2 end-2">
                    <button type="button" @click="open = !open" aria-label="{{ __('general.actions') }}" class="inline-flex items-center justify-center transition rounded-control size-11 text-ink-2 hover:text-ink hover:bg-hover">
                        <svg class="w-5 h-5 text-ink-2"
                             viewBox="0 0 16 16"
                             fill="currentColor"
                             xmlns="http://www.w3.org/2000/svg">
                            <path d="M9.5 13a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zm0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zm0-5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0z"/>
                        </svg>
                    </button>

                    <div x-show="open"
                         x-transition
                         class="absolute end-0 z-20 w-52 mt-2 overflow-hidden ltr:origin-top-right rtl:origin-top-left bg-card border border-line-soft rounded-surface shadow-e3"
                         style="display: none;"
                         @click="open = false">

                        <div class="py-2">
                            <div class="px-4 pb-2 text-small font-semibold tracking-wide text-ink-2 uppercase">
                                {{ __('general.files') }} <span class="text-ink">{{ $folder->files_count }}</span>
                            </div>
                            <div class="border-t border-line-soft"></div>
                            <button wire:click.prevent="editPermissions({{ $folder->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-start text-ink transition hover:bg-hover hover:text-ink">
                                <x-icons.cog class="flex-shrink-0 text-current size-5" />
                                <span>{{ __('general.permissions') }}</span>
                            </button>
                            <button wire:click.prevent="startEditingFolder({{ $folder->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-start text-ink transition hover:bg-hover hover:text-ink">
                                <svg class="flex-shrink-0 text-current size-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m14.304 4.844 2.852 2.852M7 7H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-4.5m2.409-9.91a2.017 2.017 0 0 1 0 2.853l-6.844 6.844L8 14l.713-3.565 6.844-6.844a2.015 2.015 0 0 1 2.852 0Z"/>
                                </svg>
                                <span>{{ __('general.edit') }}</span>
                            </button>
                            @if ($folderRole === \App\Services\Folders\FolderAccess::OWNER)
                            <button wire:click.prevent="confirmFolderDeletion({{ $folder->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-start text-ink transition hover:bg-hover hover:text-ink">
                                <svg class="flex-shrink-0 text-current size-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 7h14m-9 3v8m4-8v8M10 3h4a1 1 0 0 1 1 1v3H9V4a1 1 0 0 1 1-1ZM6 7h12v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V7Z"/>
                                </svg>
                                <span>{{ __('general.delete') }}</span>
                            </button>
                            @endif
                        </div>
                    </div>
                </div>
                @endif
            </div>
            @empty
                @if ($files->isEmpty() && $filelessPolicies->isEmpty())
                    <p class="text-center text-ink-2 col-span-full">{{ __('general.folder_empty') }}</p>
                @endif
            @endforelse

            @foreach ($files as $file)
            <div class="relative group">
                {{-- This file on the viewer's dashboard (item.file.<id>). --}}
                <div class="absolute top-2 start-2 z-10">
                    <livewire:dashboard.add-to-dashboard :tile="\App\Support\Dashboard\DashboardLayout::itemKey('file', $file->id)" :compact="true" :key="'atd-file-'.$file->id" />
                </div>
                <button type="button" wire:click="previewFile({{ $file->id }})" class="flex flex-col items-center justify-center w-full h-40 p-4 text-center transition bg-card border border-line-soft rounded-surface shadow-e1 cursor-pointer hover:-translate-y-0.5 hover:border-line hover:shadow-e3">
                    @if (in_array(strtolower($file->extension ?? ''), ['jpg', 'jpeg', 'png'], true))
                    <img src="{{ route('folder-files.preview', ['media' => $file->id]) }}" alt="{{ $file->name }}" class="object-cover w-16 h-16 rounded-surface ring-1 ring-line-soft" />
                    @else
                    <span class="flex items-center justify-center rounded-surface size-16 bg-transparent ring-1 ring-transparent group-hover:bg-ink group-hover:ring-ink">
                        <x-icons.document-text class="w-10 h-10 text-ink-2 group-hover:text-on-ink" />
                    </span>
                    @endif
                    <span class="w-full mt-3 text-body font-semibold text-ink truncate">{{ $file->name }}</span>
                    <span class="mt-1 text-small text-ink-2">{{ strtoupper($file->extension) }} · {{ $file->size >= 1048576 ? number_format($file->size / 1048576, 1).' MB' : number_format($file->size / 1024, 1).' KB' }}</span>
                </button>
                @if ($policy = $policiesByMedia[$file->id] ?? null)
                    <div class="mt-2 flex flex-wrap items-center justify-center gap-2 text-small" data-testid="policy-status-{{ $file->id }}">
                        @if ($policy->read_status === \App\Models\PolicyDocument::READING)
                            <x-status tone="work" icon="clock" :label="__('policies.status.reading')" />
                        @elseif ($policy->read_status === \App\Models\PolicyDocument::FAILED)
                            <x-status tone="stop" icon="warning" :label="__('policies.status.failed')" />
                            <span class="w-full text-center text-ink-2">{{ __('policies.error.'.($policy->read_error ?? 'unreadable')) }}</span>
                        @endif
                        @if (filled($policy->content))
                            <a href="{{ route('policies.show', $policy->id) }}" wire:navigate class="text-ink underline" aria-label="{{ __('policies.view-policy-named', ['title' => $policy->title]) }}" data-testid="policy-view-{{ $file->id }}">{{ __('policies.view-policy') }}</a>
                        @endif
                    </div>
                @endif

                <div x-data="{ open: false }" @click.away="open = false" class="absolute top-2 end-2">
                    <button type="button" @click="open = !open" aria-label="{{ __('general.a11y.file-actions', ['name' => $file->name]) }}" aria-haspopup="true" :aria-expanded="open.toString()" class="inline-flex items-center justify-center transition rounded-control size-11 text-ink-2 hover:text-ink hover:bg-hover" data-testid="file-actions-{{ $file->id }}">
                        <svg class="w-5 h-5 text-current" aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 8a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path></svg>
                    </button>

                    <div x-show="open"
                         x-transition
                         class="absolute end-0 z-20 w-52 mt-2 overflow-hidden ltr:origin-top-right rtl:origin-top-left bg-card border border-line-soft rounded-surface shadow-e3"
                         style="display: none;"
                         @click="open = false">

                        <div class="py-2">
                            <button type="button" wire:click.prevent="previewFile({{ $file->id }})" aria-label="{{ __('general.a11y.preview-file', ['name' => $file->name]) }}" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-start text-ink transition hover:bg-hover hover:text-ink">
                                <x-icons.eye class="flex-shrink-0 text-current size-5" aria-hidden="true" />
                                <span>{{ __('general.preview_file') }}</span>
                            </button>
                            <button type="button" wire:click.prevent="downloadFile({{ $file->id }})" aria-label="{{ __('general.a11y.download-file', ['name' => $file->name]) }}" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-start text-ink transition hover:bg-hover hover:text-ink">
                                <svg class="flex-shrink-0 text-current size-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v11" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m7.5 9.5 4.5 4.5 4.5-4.5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 19h14" />
                                </svg>
                                <span>{{ __('general.download_file') }}</span>
                            </button>
                            @if ($canManageCurrentFolder)
                            <div class="my-1 border-t border-line-soft"></div>
                            <button wire:click.prevent="startEditingFile({{ $file->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-start text-ink transition hover:bg-hover hover:text-ink">
                                <svg class="flex-shrink-0 text-current size-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m14.304 4.844 2.852 2.852M7 7H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-4.5m2.409-9.91a2.017 2.017 0 0 1 0 2.853l-6.844 6.844L8 14l.713-3.565 6.844-6.844a2.015 2.015 0 0 1 2.852 0Z"/>
                                </svg>
                                <span>{{ __('general.edit_name') }}</span>
                            </button>
                            @if (isset($policiesByMedia[$file->id]))
                            <button wire:click.prevent="startReplacePolicyFile({{ $file->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-start text-ink transition hover:bg-hover hover:text-ink" data-testid="policy-replace-{{ $file->id }}">
                                <x-icons.upload class="flex-shrink-0 text-current size-5" />
                                <span>{{ __('policies.replace') }}</span>
                            </button>
                            @endif
                            @endif
                            @if ($canDeleteInCurrentFolder)
                            <button wire:click.prevent="confirmFileDeletion({{ $file->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-start text-ink transition hover:bg-hover hover:text-ink">
                                <svg class="flex-shrink-0 text-current size-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 7h14m-9 3v8m4-8v8M10 3h4a1 1 0 0 1 1 1v3H9V4a1 1 0 0 1 1-1ZM6 7h12v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V7Z"/>
                                </svg>
                                <span>{{ __('general.delete_file') }}</span>
                            </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            @endforeach

            {{-- Policies kept as text only (from before the folder): open on their own page. --}}
            @foreach ($filelessPolicies as $policy)
            <a href="{{ route('policies.show', $policy->id) }}" wire:navigate class="group flex flex-col items-center justify-center h-40 p-4 text-center transition bg-card border border-line-soft rounded-surface shadow-e1 hover:-translate-y-0.5 hover:border-line hover:shadow-e3" aria-label="{{ __('policies.view-policy-named', ['title' => $policy->title]) }}" data-testid="policy-text-{{ $policy->id }}">
                <span class="flex items-center justify-center rounded-surface size-16 ring-1 ring-transparent group-hover:bg-ink group-hover:ring-ink">
                    <x-icons.document-text class="w-10 h-10 text-ink-2 group-hover:text-on-ink" />
                </span>
                <span class="w-full mt-3 text-body font-semibold text-ink truncate">{{ $policy->title }}</span>
                <span class="mt-1 text-small text-ink-2">{{ __('policies.view-policy') }}</span>
            </a>
            @endforeach
        </div>
    </div>

    @if ($showCreateFolderModal)
    <div class="fixed inset-0 z-40 bg-backdrop" aria-hidden="true" wire:click="$set('showCreateFolderModal', false)" x-data x-on:keydown.escape.window="$wire.$set('showCreateFolderModal', false)"></div>
    <div class="fixed z-50 w-full max-w-lg -translate-x-1/2 -translate-y-1/2 top-1/2 left-1/2">
        <div class="mx-4 bg-card rounded-surface shadow-e3">
            <div class="px-6 pt-6">
                <h3 class="text-h4 font-medium leading-6 text-ink">{{ __('general.create_new_folder') }}</h3>
                {{-- createFolder() stores is_shared = the current mode, so the mode decides whose folder it is. --}}
                <p class="mt-2 text-small text-ink-2">{{ $isShared ? __('general.folders-new-shared') : __('general.folders-new-mine') }}</p>
                <div class="mt-4">
                    <label for="folderName" class="block text-body font-medium text-ink">{{ __('general.folder_name') }}</label>
                    <input type="text" id="folderName" wire:model.defer="newFolderName" wire:keydown.enter="createFolder" class="block w-full mt-1 border-line rounded-control shadow-e1 sm:text-body">
                    @error('newFolderName')
                    <span class="hc-err mt-2">{{ $message }}</span>
                    @enderror
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 px-6 py-4 mt-4 rounded-b-lg bg-soft">
                <x-button full wire:click="createFolder">{{ __('general.create') }}</x-button>
                <x-button variant="outline" full :loading="false" wire:click="$set('showCreateFolderModal', false)">{{ __('general.cancel') }}</x-button>
            </div>
        </div>
    </div>
    @endif

    @if ($editingFolderId)
    <div class="fixed inset-0 z-40 bg-backdrop" aria-hidden="true" wire:click="$set('editingFolderId', null)" x-data x-on:keydown.escape.window="$wire.$set('editingFolderId', null)"></div>
    <div class="fixed z-50 w-full max-w-lg -translate-x-1/2 -translate-y-1/2 top-1/2 left-1/2">
        <div class="mx-4 bg-card rounded-surface shadow-e3">
            <div class="px-6 pt-6">
                <h3 class="text-h4 font-medium text-ink">{{ __('general.edit_folder') }}</h3>
                <div class="mt-4">
                    <label for="editingFolderName" class="block text-body font-medium text-ink">{{ __('general.folder_name') }}</label>
                    <input type="text" id="editingFolderName" wire:model.defer="editingFolderName" wire:keydown.enter="saveEditedFolder" class="block w-full mt-1 border-line rounded-control shadow-e1 sm:text-body">
                    @error('editingFolderName')
                    <span class="hc-err mt-2">{{ $message }}</span>
                    @enderror
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 px-6 py-4 mt-4 rounded-b-lg bg-soft">
                <x-button full wire:click="saveEditedFolder">{{ __('general.save') }}</x-button>
                <x-button variant="outline" full :loading="false" wire:click="$set('editingFolderId', null)">{{ __('general.cancel') }}</x-button>
            </div>
        </div>
    </div>
    @endif

    @if ($editingFileId)
    <div class="fixed inset-0 z-40 bg-backdrop" aria-hidden="true" wire:click="$set('editingFileId', null)" x-data x-on:keydown.escape.window="$wire.$set('editingFileId', null)"></div>
    <div class="fixed z-50 w-full max-w-lg -translate-x-1/2 -translate-y-1/2 top-1/2 left-1/2">
        <div class="mx-4 bg-card rounded-surface shadow-e3">
            <div class="px-6 pt-6">
                <h3 class="text-h4 font-medium text-ink">{{ __('general.edit_file_name') }}</h3>
                <div class="mt-4">
                    <label for="editingFileName" class="block text-body font-medium text-ink">{{ __('general.file_name') }}</label>
                    <input type="text" id="editingFileName" wire:model.defer="editingFileName" wire:keydown.enter="saveEditedFile" class="block w-full mt-1 border-line rounded-control shadow-e1 sm:text-body">
                    @error('editingFileName')
                    <span class="hc-err mt-2">{{ $message }}</span>
                    @enderror
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 px-6 py-4 mt-4 rounded-b-lg bg-soft">
                <x-button full wire:click="saveEditedFile">{{ __('general.save') }}</x-button>
                <x-button variant="outline" full :loading="false" wire:click="$set('editingFileId', null)">{{ __('general.cancel') }}</x-button>
            </div>
        </div>
    </div>
    @endif

    @if ($showPreviewModal && $previewFile)
    @php
        $previewExtension = strtolower($previewFile->extension ?? '');
        $canPreviewInOffice = in_array($previewExtension, ['docx', 'xlsx', 'pptx'], true);
        $canExtractOfficeText = in_array($previewExtension, ['docx', 'xlsx'], true);
    @endphp
    <div class="fixed inset-0 z-40 bg-backdrop" aria-hidden="true" wire:click="closePreviewModal" x-data x-on:keydown.escape.window="$wire.closePreviewModal()"></div>
    <div class="fixed z-50 w-full max-w-5xl -translate-x-1/2 -translate-y-1/2 top-1/2 left-1/2">
        <div class="flex flex-col max-h-[88vh] mx-4 overflow-hidden bg-card border border-line-soft rounded-surface shadow-e3">
            <div class="flex items-center justify-between gap-4 px-4 py-4 border-b border-line-soft bg-soft">
                <div class="min-w-0">
                    <h3 class="text-body font-bold text-ink truncate">{{ $previewFile->name }}</h3>
                    <p class="mt-1 text-small text-ink-2">{{ strtoupper($previewFile->extension) }} · {{ $previewFile->size >= 1048576 ? number_format($previewFile->size / 1048576, 1).' MB' : number_format($previewFile->size / 1024, 1).' KB' }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <x-button size="sm" wire:click="downloadFile({{ $previewFile->id }})">
                        Download
                    </x-button>
                    <button type="button" wire:click="closePreviewModal" class="inline-flex items-center justify-center text-ink-2 transition rounded-control size-11 hover:bg-hover hover:text-ink">
                        <span class="sr-only">Close</span>
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="flex-1 min-h-0 p-4 overflow-auto bg-surface">
                @if (in_array($previewExtension, ['jpg', 'jpeg', 'png'], true))
                    <div class="flex items-center justify-center min-h-[55vh]">
                        <img src="{{ route('folder-files.preview', ['media' => $previewFile->id]) }}" alt="{{ $previewFile->name }}" class="max-w-full max-h-[70vh] rounded-surface shadow-e3 bg-card">
                    </div>
                @elseif ($previewExtension === 'pdf')
                    <iframe src="{{ route('folder-files.preview', ['media' => $previewFile->id]) }}" title="{{ $previewFile->name }}" class="w-full h-[70vh] bg-card border border-line-soft rounded-surface"></iframe>
                @elseif ($convertedOfficePdfUrl)
                    <iframe src="{{ $convertedOfficePdfUrl }}" title="{{ $previewFile->name }}" class="w-full h-[70vh] bg-card border border-line-soft rounded-surface"></iframe>
                @elseif ($canExtractOfficeText)
                    @if (($officePreviewData['available'] ?? false) && ($officePreviewData['type'] ?? null) === 'docx')
                        <div class="max-w-4xl p-8 mx-auto bg-card border border-line-soft rounded-surface shadow-e1">
                            <div class="prose prose-sm max-w-none">
                                @foreach ($officePreviewData['paragraphs'] as $paragraph)
                                    <p class="mb-3 leading-7 text-ink">{{ $paragraph }}</p>
                                @endforeach
                            </div>
                        </div>
                    @elseif (($officePreviewData['available'] ?? false) && ($officePreviewData['type'] ?? null) === 'xlsx')
                        <div class="overflow-auto bg-card border border-line-soft rounded-surface shadow-e1">
                            <table class="min-w-full text-body border-collapse">
                                <tbody class="divide-y divide-line-soft">
                                    @foreach ($officePreviewData['rows'] as $row)
                                        <tr class="divide-x divide-line-soft">
                                            @foreach ($row as $cell)
                                                <td class="min-w-32 px-3 py-2 text-ink whitespace-nowrap">{{ $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="flex items-center justify-center min-h-[55vh]">
                            <div class="max-w-lg p-8 text-center bg-card border border-line-soft rounded-surface shadow-e1">
                                <span class="inline-flex items-center justify-center mx-auto rounded-surface size-16 bg-surface ring-1 ring-line-soft">
                                    <x-icons.document-text class="size-9 text-ink-3" />
                                </span>
                                <h4 class="mt-4 text-body font-bold text-ink">{{ $previewFile->name }}</h4>
                                <p class="mt-2 text-body leading-6 text-ink-2">
                                    {{ $officePreviewData['message'] ?? 'A local preview could not be generated for this Office file.' }}
                                </p>
                                <x-button class="mt-4" wire:click="downloadFile({{ $previewFile->id }})">
                                    Download file
                                </x-button>
                            </div>
                        </div>
                    @endif
                @elseif ($canPreviewInOffice)
                    <div class="flex items-center justify-center min-h-[55vh]">
                        <div class="max-w-lg p-8 text-center bg-card border border-line-soft rounded-surface shadow-e1">
                            <span class="inline-flex items-center justify-center mx-auto rounded-surface size-16 bg-surface ring-1 ring-line-soft">
                                <x-icons.document-text class="size-9 text-ink-3" />
                            </span>
                            <h4 class="mt-4 text-body font-bold text-ink">{{ $previewFile->name }}</h4>
                            <p class="mt-2 text-body leading-6 text-ink-2">
                                A full local Office preview needs LibreOffice or Microsoft Office available on the server. Until then, this file can be downloaded and opened in its native app.
                            </p>
                            <x-button class="mt-4" wire:click="downloadFile({{ $previewFile->id }})">
                                Download file
                            </x-button>
                        </div>
                    </div>
                @else
                    <div class="flex items-center justify-center min-h-[55vh]">
                        <div class="max-w-md p-8 text-center bg-card border border-line-soft rounded-surface shadow-e1">
                            <span class="inline-flex items-center justify-center mx-auto rounded-surface size-16 bg-surface ring-1 ring-line-soft">
                                <x-icons.document-text class="size-9 text-ink-3" />
                            </span>
                            <h4 class="mt-4 text-body font-bold text-ink">{{ $previewFile->name }}</h4>
                            <p class="mt-2 text-body text-ink-2">Preview is available for images, PDF, Word, Excel and PowerPoint files. Download this file to open it in its native app.</p>
                            <x-button class="mt-4" wire:click="downloadFile({{ $previewFile->id }})">
                                Download file
                            </x-button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
    @endif

    @if ($showPermissionsModal)
    <div class="fixed inset-0 z-40 bg-backdrop" aria-hidden="true" wire:click="closePermissionsModal" x-data x-on:keydown.escape.window="$wire.closePermissionsModal()"></div>
    <div class="fixed z-50 w-full max-w-3xl -translate-x-1/2 -translate-y-1/2 top-1/2 left-1/2">
        {{-- Scrolls inside itself: with the group section it is taller than a phone screen. --}}
        <div class="flex flex-col mx-4 max-h-[calc(100dvh-2rem)] overflow-y-auto bg-card border border-line-soft rounded-surface shadow-e3">
            <div class="px-6 py-4 border-b border-line-soft bg-soft">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-h4 font-bold text-ink">{{ __('general.assign_permissions', ['folder' => $folderForPermissions?->name]) }}</h3>
                        <p class="mt-1 text-small text-ink-2">{{ __('folders.roles-help') }}</p>
                        @if ($folderForPermissions?->parent_id && $folderForPermissions?->inherits_access)
                            <p class="mt-1 text-small text-ink-2">{{ __('folders.share.inherits') }}</p>
                        @endif
                    </div>
                    <span class="inline-flex items-center justify-center flex-shrink-0 text-ink-2 bg-card border border-line-soft rounded-surface shadow-e1 size-10">
                        <x-icons.cog class="size-5" />
                    </span>
                </div>

                {{-- Share with a group: kept as the group, so later members have access too. --}}
                <section class="p-4 mt-4 bg-card border border-line-soft rounded-surface shadow-e1" aria-labelledby="share-groups-title" data-testid="share-groups">
                    <h4 id="share-groups-title" class="text-body font-bold text-ink">{{ __('folders.share.groups-title') }}</h4>
                    <p class="mt-0.5 text-small text-ink-2">{{ __('folders.share.groups-hint') }}</p>

                    @if ($groupShares)
                        <ul class="mt-3 divide-y divide-line-soft border border-line-soft rounded-control">
                            @foreach ($groupShares as $i => $share)
                                @php $members = $groupMembers[$i] ?? []; @endphp
                                <li class="flex flex-wrap items-center gap-2 px-3 py-2" wire:key="group-share-{{ $share['type'] }}-{{ $share['id'] }}">
                                    <span class="flex-1 min-w-0">
                                        <span class="block font-semibold text-ink truncate">{{ $groupTypes[$share['type']] ?? $share['type'] }}: {{ $share['label'] }}</span>
                                        <span class="block text-caption text-ink-3 num">{{ trans_choice('folders.share.members-now', count($members), ['count' => count($members)]) }}</span>
                                    </span>
                                    <select aria-label="{{ __('folders.share.role-of', ['name' => $share['label']]) }}"
                                            wire:change="setGroupRole({{ $i }}, $event.target.value)"
                                            class="py-1.5 text-small border-line rounded-control">
                                        @foreach (\App\Services\Folders\FolderAccess::SHARE_ROLES as $role)
                                            <option value="{{ $role }}" @selected($share['role'] === $role)>{{ __('folders.roles.'.$role) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="removeGroupShare({{ $i }})"
                                            aria-label="{{ __('folders.share.remove-group', ['group' => $share['label']]) }}"
                                            class="inline-flex items-center justify-center size-11 text-ink-2 rounded-control hover:bg-hover hover:text-ink">
                                        <svg viewBox="0 0 14 14" class="size-3.5 stroke-current" aria-hidden="true"><path d="M4 4l6 6m0-6l-6 6" stroke-width="1.6"/></svg>
                                    </button>
                                    {{-- The people in it now, one click away: who a group share reaches. --}}
                                    @if ($members)
                                        <details class="w-full" data-testid="group-people">
                                            <summary class="cursor-pointer text-small font-semibold text-ink-2 hover:text-ink">{{ __('folders.share.people-in-group', ['group' => $share['label']]) }}</summary>
                                            <ul class="flex flex-wrap gap-1.5 pt-2">
                                                @foreach ($members as $member)
                                                    <li class="px-2.5 py-0.5 text-small border rounded-full bg-surface border-line-soft text-ink">{{ $member['name'] }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-2 text-small text-ink-3">{{ __('folders.share.no-groups') }}</p>
                    @endif

                    <div class="flex flex-wrap items-end gap-2 mt-3">
                        <label class="flex-1 min-w-[9rem]">
                            <span class="block text-caption font-semibold text-ink-2">{{ __('folders.share.group-type') }}</span>
                            <select wire:model.live="groupType" class="block w-full py-2 text-body border-line rounded-control">
                                <option value="">{{ __('general.select_category') }}</option>
                                @foreach ($groupTypes as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex-1 min-w-[9rem]">
                            <span class="block text-caption font-semibold text-ink-2">{{ __('folders.share.group-value') }}</span>
                            <select wire:model="groupValue" @disabled(! $groupOptions) class="block w-full py-2 text-body border-line rounded-control">
                                <option value="">{{ __('general.select_value') }}</option>
                                @foreach ($groupOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="w-32">
                            <span class="block text-caption font-semibold text-ink-2">{{ __('folders.share.role') }}</span>
                            <select wire:model="groupRole" class="block w-full py-2 text-body border-line rounded-control">
                                @foreach (\App\Services\Folders\FolderAccess::SHARE_ROLES as $role)
                                    <option value="{{ $role }}">{{ __('folders.roles.'.$role) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <x-button variant="outline" wire:click="addGroupShare">{{ __('folders.share.add-group') }}</x-button>
                    </div>
                    @error('groupValue')
                        <p class="hc-err mt-2">{{ $message }}</p>
                    @enderror
                </section>

                {{-- Share with people: one by one, each with a role. --}}
                <h4 class="mt-5 text-body font-bold text-ink">{{ __('folders.share.people-title') }}</h4>
                <p class="mt-0.5 text-small text-ink-2">{{ __('folders.share.people-hint') }}</p>
                <p class="mt-0.5 text-small text-ink-3">{{ __('folders.share.people-any') }}</p>

                {{-- Search --}}
                <div class="mt-3">
                    <div class="relative">
                        <span class="absolute inset-y-0 flex items-center text-ink-3 pointer-events-none start-0 ps-3">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z"/>
                            </svg>
                        </span>
                        <input type="text" wire:model.live.debounce.300ms="userSearch"
                               placeholder="{{ __('general.search_employees') }}"
                               class="block w-full border-line rounded-surface shadow-e1 ps-12 sm:text-body">
                    </div>
                </div>

                {{-- Category filter builder --}}
                <div class="p-4 mt-4 bg-card border border-line-soft rounded-surface shadow-e1">
                    <div class="flex items-center justify-between">
                        <span class="text-small font-semibold tracking-wide text-ink-2 uppercase">{{ __('general.filter_by_category') }}</span>
                        @if (!empty($activeFilters))
                            <button type="button" wire:click="clearFilters" class="text-small font-semibold text-ink-2 underline-offset-4 transition hover:text-ink hover:underline">
                                {{ __('general.clear_all') }}
                            </button>
                        @endif
                    </div>

                    <div class="flex flex-col gap-2 mt-2 sm:flex-row">
                        <select wire:model.live="permissionFilterType"
                                class="block w-full py-2 text-body border-line rounded-control sm:w-1/2">
                            <option value="">{{ __('general.select_category') }}</option>
                            @foreach($this->permissionFilterCategories as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>

                        @if (!empty($permissionFilterOptions))
                            <select wire:model.live="permissionFilterValue"
                                    class="block w-full py-2 text-body border-line rounded-control sm:w-1/2">
                                <option value="">{{ __('general.select_value') }}</option>
                                @foreach($permissionFilterOptions as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>

                    @if (!empty($activeFilters))
                        <div class="flex flex-wrap gap-2 mt-3">
                            @foreach ($activeFilters as $index => $filter)
                            <span class="inline-flex items-center rounded-full bg-surface border border-line-soft ps-3 pe-2 py-1 text-small font-semibold text-ink">
                                {{ $filter['type_label'] }}: {{ $filter['value_label'] }}
                                <button type="button" wire:click="removeFilter({{ $index }})"
                                        class="inline-flex items-center justify-center size-6 text-ink-2 transition rounded-full ms-1 hover:bg-hover hover:text-ink">
                                    <span class="sr-only">Remove</span>
                                    <svg viewBox="0 0 14 14" class="w-3 h-3 stroke-current"><path d="M4 4l6 6m0-6l-6 6" stroke-width="1.6"/></svg>
                                </button>
                            </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Result count + select all --}}
                @if ($allUsers)
                    <div class="flex items-center justify-between mt-4">
                        <p class="text-body text-ink-2">
                            <span class="font-semibold text-ink">{{ $allUsers->count() }}</span> {{ trans_choice('general.employee', $allUsers->count()) }}
                            <span class="mx-1 text-ink-3">&middot;</span>
                            <span class="font-semibold text-ink">{{ count($selectedUsers) }}</span> {{ __('general.selected') }}
                        </p>
                        @if ($allUsers->isNotEmpty())
                            <x-button variant="link" size="sm" wire:click="toggleSelectAllVisible">
                                {{ $allVisibleUsersSelected ? __('general.deselect_all_visible') : __('general.select_all_visible') }}
                            </x-button>
                        @endif
                    </div>
                @endif
            </div>
            <div class="relative mx-6 mt-4 overflow-y-auto border border-line-soft rounded-surface h-72" wire:loading.class="opacity-40" wire:target="permissionFilterType, permissionFilterValue, userSearch, clearFilters, removeFilter, toggleSelectAllVisible">
                <div wire:loading wire:target="permissionFilterType, permissionFilterValue, userSearch, clearFilters, removeFilter, toggleSelectAllVisible" class="absolute inset-0 z-10 flex items-center justify-center bg-card/80 rounded-surface">
                    <x-icons.spinner class="w-8 h-8 text-ink-2" />
                </div>

                @if($allUsers && $allUsers->isNotEmpty())

                    {{-- ── HAS ACCESS ─────────────────────────────────────── --}}
                    @if($hasAccessUsers->isNotEmpty())
                    <div class="sticky top-0 z-10 flex items-center gap-2 px-4 py-2 bg-card border-b border-line-soft">
                        <svg class="size-3.5 text-ink flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd"/>
                        </svg>
                        <span class="text-caption font-bold tracking-widest uppercase text-ink-2">{{ __('general.has_access') }}</span>
                        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-4 px-2 text-caption font-bold text-on-ink rounded-full bg-ink">{{ $hasAccessUsers->count() }}</span>
                    </div>
                    @foreach ($hasAccessUsers as $user)
                        @php
                            $isCreator = $user->id === $folderForPermissions?->user_id;
                            $uName     = optional($user->profile)->name ?? $user->email;
                        @endphp
                        <div class="flex items-center border-s-[3px] border-ink @if($isCreator) bg-soft/60 @else bg-card @endif" wire:key="perm-user-{{ $user->id }}">
                            <button type="button"
                                    @if(!$isCreator) wire:click="toggleUser({{ $user->id }})" @else aria-disabled="true" @endif
                                    aria-pressed="true"
                                    class="flex items-center flex-1 min-w-0 gap-3 px-4 py-2 text-start transition-colors @if($isCreator) cursor-default @else hover:bg-hover @endif">
                                <span class="flex-shrink-0 flex items-center justify-center w-5 h-5 rounded border bg-ink border-ink text-on-ink">
                                    <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.58l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                                </span>
                                <span class="flex-1 min-w-0">
                                    <span class="block text-body font-semibold text-ink truncate">{{ $uName }}</span>
                                    @isset($viaGroups[$user->id])<span class="block text-caption text-ink-3" data-testid="via-group">{{ __("folders.share.through-group", ["group" => implode(", ", $viaGroups[$user->id])]) }}</span>@endisset
                                    @if($isCreator)<span class="text-caption text-ink-3">{{ __('folders.roles.owner') }}</span>@endif
                                </span>
                            </button>
                            @unless ($isCreator)
                                <select aria-label="{{ __('folders.share.role-of', ['name' => $uName]) }}"
                                        wire:change="setUserRole({{ $user->id }}, $event.target.value)"
                                        class="me-3 py-1.5 text-small border-line rounded-control">
                                    @foreach (\App\Services\Folders\FolderAccess::SHARE_ROLES as $role)
                                        <option value="{{ $role }}" @selected(($userRoles[$user->id] ?? 'viewer') === $role)>{{ __('folders.roles.'.$role) }}</option>
                                    @endforeach
                                </select>
                            @endunless
                        </div>
                    @endforeach
                    @endif

                    {{-- ── NO ACCESS ──────────────────────────────────────── --}}
                    @if($noAccessUsers->isNotEmpty())
                    <div class="sticky top-0 z-10 flex items-center gap-2 px-4 py-2 bg-card border-b border-line-soft @if($hasAccessUsers->isNotEmpty()) border-t border-line-soft @endif">
                        <svg class="size-3.5 text-ink-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd"/>
                        </svg>
                        <span class="text-caption font-bold tracking-widest uppercase text-ink-3">{{ __('general.no_access') }}</span>
                        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-4 px-2 text-caption font-bold text-ink-2 rounded-full bg-surface">{{ $noAccessUsers->count() }}</span>
                    </div>
                    @foreach ($noAccessUsers as $user)
                        @php
                            $uName = optional($user->profile)->name ?? $user->email;
                        @endphp
                        <button type="button"
                                wire:key="perm-user-{{ $user->id }}"
                                wire:click="toggleUser({{ $user->id }})"
                                aria-pressed="false"
                                class="flex items-center w-full gap-3 px-4 py-2 text-start transition-colors hover:bg-hover bg-card border-s-[3px] border-transparent">
                            <span class="flex-1 min-w-0">
                                <span class="block text-body font-medium text-ink-2 truncate">{{ $uName }}</span>
                                @isset($viaGroups[$user->id])<span class="block text-caption text-ink-3" data-testid="via-group">{{ __("folders.share.through-group", ["group" => implode(", ", $viaGroups[$user->id])]) }}</span>@endisset
                            </span>
                            <span class="flex-shrink-0 flex items-center justify-center w-5 h-5 rounded border bg-card border-line"></span>
                        </button>
                    @endforeach
                    @endif

                @elseif($allUsers !== null)
                    <p class="py-12 text-body text-center text-ink-3">{{ __('general.no_employees_found') }}</p>
                @endif
            </div>
            <div class="grid grid-cols-2 gap-3 px-6 py-4 mt-4 border-t border-line-soft bg-soft">
                <x-button full wire:click="savePermissions">{{ __('general.save_permissions') }}</x-button>
                <x-button variant="outline" full wire:click="closePermissionsModal">{{ __('general.cancel') }}</x-button>
            </div>
        </div>
    </div>
    @endif
</div>
