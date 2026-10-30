<x-slot:title>
    {{ $isShared ? __('general.shared-folders') : __('general.my-folders') }}
</x-slot:title>

<div>
    <div class="flex flex-col gap-4 md:flex-row md:justify-between md:items-start">
        <livewire:layout.breadcrumbs
            :title="$isShared ? __('general.shared-folders') : __('general.my-folders')"
            :crumbs="$finalCrumbs"
        />

        <div class="flex items-center flex-shrink-0 gap-2">
            @if ($canManageCurrentFolder)
                <x-button icon="plus" :loading="false" wire:click="$set('showCreateFolderModal', true)">
                    {{ __('general.new_folder') }}
                </x-button>
            @endif

            @if ($currentFolder && $canManageCurrentFolder)
                <div
                    x-data="{ uploading: false, progress: 0 }"
                    x-on:livewire-upload-start="uploading = true"
                    x-on:livewire-upload-finish="uploading = false; progress = 0"
                    x-on:livewire-upload-error="uploading = false; progress = 0"
                    x-on:livewire-upload-progress="progress = $event.detail.progress"
                    class="w-full sm:w-auto"
                >
                    <label for="file-upload" class="hc-btn hc-btn--outline w-full cursor-pointer">
                        <x-icons.upload class="size-5" />
                        {{ __('general.upload_files') }}
                        <input x-ref="fileInput" id="file-upload" wire:model.live="filesToUpload" type="file" class="sr-only" multiple accept=".jpg,.jpeg,.png,.svg,.pdf,.docx,.xlsx,.pptx">
                    </label>
                    <div x-show="uploading" class="mt-2 w-full bg-surface rounded-full h-2.5">
                        <div class="bg-ink h-2.5 rounded-full" :style="`width: ${progress}%`"></div>
                    </div>
                    @error('filesToUpload')
                        <p class="hc-err mt-2">{{ $message }}</p>
                    @enderror
                </div>
            @endif
        </div>
    </div>

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
        class="relative p-4 mt-6 transition border border-line-soft shadow-e1 bg-soft/80 sm:rounded-surface"
        @if ($currentFolder && $canManageCurrentFolder) :class="dragging ? 'border-ink bg-surface' : ''" @endif
    >
        @if ($currentFolder && $canManageCurrentFolder)
            <div x-show="dragging" x-transition class="absolute inset-3 z-10 flex items-center justify-center border-2 border-dashed rounded-surface pointer-events-none border-ink bg-card/80" style="display: none;">
                <div class="text-center">
                    <x-icons.upload class="mx-auto size-10 text-ink-3" />
                    <p class="mt-2 text-body font-semibold text-ink">Drop files here to upload</p>
                    <p class="text-small text-ink-2">JPG, PNG, SVG, PDF, DOCX, XLSX and PPTX files are supported</p>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
            @forelse($folders as $folder)
            <div class="relative group ">
                <a href="{{ $isShared ? route('shared-folders.index', ['folderId' => $folder->id]) : route('my-folders.index', ['folderId' => $folder->id]) }}" wire:navigate class="flex flex-col items-center justify-center h-40 p-4 text-center transition bg-card border border-line-soft rounded-surface shadow-e1 hover:-translate-y-0.5 hover:border-line hover:shadow-e3">
                    <span class="flex items-center justify-center rounded-surface size-16 bg-transparent ring-1 ring-transparent group-hover:bg-ink group-hover:ring-ink">
                        <x-icons.folder class="w-10 h-10 text-ink-2 group-hover:text-on-ink" />
                    </span>
                    <span class="w-full mt-3 text-body font-semibold text-ink truncate">{{ $folder->name }}</span>
                    @if ($folder->user_id === auth()->id())
                        <span class="mt-1 text-small text-ink-2">{{ __('general.by_me') }}</span>
                    @else
                        <span class="mt-1 text-small text-ink-2">{{ __('general.by') }}: {{ optional($folder->creator->profile)->name ?? __('general.unknown') }}</span>
                    @endif
                </a>

                @if (!$isShared && ($folder->user_id === auth()->id() || auth()->user()->isActiveAdministrator()))
                <div x-data="{ open: false }" @click.away="open = false" class="absolute top-2 right-2">
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
                         class="absolute right-0 z-20 w-52 mt-2 overflow-hidden origin-top-right bg-card border border-line-soft rounded-surface shadow-e3"
                         style="display: none;"
                         @click="open = false">

                        <div class="py-2">
                            <div class="px-4 pb-2 text-small font-semibold tracking-wide text-ink-2 uppercase">
                                {{ __('general.files') }} <span class="text-ink">{{ $folder->files_count }}</span>
                            </div>
                            <div class="border-t border-line-soft"></div>
                            <button wire:click.prevent="editPermissions({{ $folder->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-left text-ink transition hover:bg-hover hover:text-ink">
                                <x-icons.cog class="flex-shrink-0 text-current size-5" />
                                <span>{{ __('general.permissions') }}</span>
                            </button>
                            <button wire:click.prevent="startEditingFolder({{ $folder->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-left text-ink transition hover:bg-hover hover:text-ink">
                                <svg class="flex-shrink-0 text-current size-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m14.304 4.844 2.852 2.852M7 7H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-4.5m2.409-9.91a2.017 2.017 0 0 1 0 2.853l-6.844 6.844L8 14l.713-3.565 6.844-6.844a2.015 2.015 0 0 1 2.852 0Z"/>
                                </svg>
                                <span>{{ __('general.edit') }}</span>
                            </button>
                            <button wire:click.prevent="confirmFolderDeletion({{ $folder->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-left text-ink transition hover:bg-hover hover:text-ink">
                                <svg class="flex-shrink-0 text-current size-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 7h14m-9 3v8m4-8v8M10 3h4a1 1 0 0 1 1 1v3H9V4a1 1 0 0 1 1-1ZM6 7h12v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V7Z"/>
                                </svg>
                                <span>{{ __('general.delete') }}</span>
                            </button>
                        </div>
                    </div>
                </div>
                @endif
            </div>
            @empty
                @if ($files->isEmpty())
                    <p class="text-center text-ink-2 col-span-full">{{ __('general.folder_empty') }}</p>
                @endif
            @endforelse

            @foreach ($files as $file)
            <div class="relative group">
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

                <div x-data="{ open: false }" @click.away="open = false" class="absolute top-2 right-2">
                    <button type="button" @click="open = !open" aria-label="{{ __('general.actions') }}" class="inline-flex items-center justify-center transition rounded-control size-11 text-ink-2 hover:text-ink hover:bg-hover">
                        <svg class="w-5 h-5 text-current" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 8a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path></svg>
                    </button>

                    <div x-show="open"
                         x-transition
                         class="absolute right-0 z-20 w-52 mt-2 overflow-hidden origin-top-right bg-card border border-line-soft rounded-surface shadow-e3"
                         style="display: none;"
                         @click="open = false">

                        <div class="py-2">
                            <button wire:click.prevent="previewFile({{ $file->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-left text-ink transition hover:bg-hover hover:text-ink">
                                <x-icons.eye class="flex-shrink-0 text-current size-5" />
                                <span>Preview</span>
                            </button>
                            <button wire:click.prevent="downloadFile({{ $file->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-left text-ink transition hover:bg-hover hover:text-ink">
                                <svg class="flex-shrink-0 text-current size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v11" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m7.5 9.5 4.5 4.5 4.5-4.5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 19h14" />
                                </svg>
                                <span>Download</span>
                            </button>
                            @if ($canManageCurrentFolder)
                            <div class="my-1 border-t border-line-soft"></div>
                            <button wire:click.prevent="startEditingFile({{ $file->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-left text-ink transition hover:bg-hover hover:text-ink">
                                <svg class="flex-shrink-0 text-current size-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                    <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m14.304 4.844 2.852 2.852M7 7H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-4.5m2.409-9.91a2.017 2.017 0 0 1 0 2.853l-6.844 6.844L8 14l.713-3.565 6.844-6.844a2.015 2.015 0 0 1 2.852 0Z"/>
                                </svg>
                                <span>{{ __('general.edit_name') }}</span>
                            </button>
                            <button wire:click.prevent="confirmFileDeletion({{ $file->id }})" class="flex items-center w-full gap-3 px-4 py-2 text-body font-medium text-left text-ink transition hover:bg-hover hover:text-ink">
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
        </div>
    </div>

    @if ($showCreateFolderModal)
    <div class="fixed inset-0 z-40 bg-backdrop" wire:click="$set('showCreateFolderModal', false)"></div>
    <div class="fixed z-50 w-full max-w-lg -translate-x-1/2 -translate-y-1/2 top-1/2 left-1/2">
        <div class="mx-4 bg-card rounded-surface shadow-e3">
            <div class="px-6 pt-6">
                <h3 class="text-h4 font-medium leading-6 text-ink">{{ __('general.create_new_folder') }}</h3>
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
    <div class="fixed inset-0 z-40 bg-backdrop" wire:click="$set('editingFolderId', null)"></div>
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
    <div class="fixed inset-0 z-40 bg-backdrop" wire:click="$set('editingFileId', null)"></div>
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
    <div class="fixed inset-0 z-40 bg-backdrop" wire:click="closePreviewModal"></div>
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
    <div class="fixed inset-0 z-40 bg-backdrop" wire:click="closePermissionsModal"></div>
    <div class="fixed z-50 w-full max-w-3xl -translate-x-1/2 -translate-y-1/2 top-1/2 left-1/2">
        <div class="flex flex-col mx-4 overflow-hidden bg-card border border-line-soft rounded-surface shadow-e3">
            <div class="px-6 py-4 border-b border-line-soft bg-soft">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-h4 font-bold text-ink">{{ __('general.assign_permissions', ['folder' => $folderForPermissions?->name]) }}</h3>
                        <p class="mt-1 text-body text-ink-2">{{ __('general.select_employees') }}</p>
                    </div>
                    <span class="inline-flex items-center justify-center flex-shrink-0 text-ink-2 bg-card border border-line-soft rounded-surface shadow-e1 size-10">
                        <x-icons.cog class="size-5" />
                    </span>
                </div>
                {{-- Search --}}
                <div class="mt-4">
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
                        <button type="button"
                                wire:key="perm-user-{{ $user->id }}"
                                @if(!$isCreator) wire:click="toggleUser({{ $user->id }})" @endif
                                class="flex items-center w-full gap-3 px-4 py-2 text-start border-s-[3px] border-ink transition-colors @if($isCreator) cursor-default bg-soft/60 @else hover:bg-hover bg-card @endif">
                            <span class="flex-1 min-w-0">
                                <span class="block text-body font-semibold text-ink truncate">{{ $uName }}</span>
                                @if($isCreator)<span class="text-caption text-ink-3">{{ __('general.me_creator') }}</span>@endif
                            </span>
                            <span class="flex-shrink-0 flex items-center justify-center w-5 h-5 rounded border bg-ink border-ink text-on-ink">
                                <svg class="size-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.58l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                            </span>
                        </button>
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
                                class="flex items-center w-full gap-3 px-4 py-2 text-start transition-colors hover:bg-hover bg-card border-s-[3px] border-transparent">
                            <span class="flex-1 min-w-0">
                                <span class="block text-body font-medium text-ink-2 truncate">{{ $uName }}</span>
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
