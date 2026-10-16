<?php

namespace App\Livewire\Archives;

use App\Livewire\Concerns\WithFeedback;
use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\Folder;
use App\Models\JobLevel;
use App\Models\Program;
use App\Models\User;
use App\Notifications\FolderShared;
use DOMDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class ArchivedFilesModule extends Component
{
    use WithFeedback, WithFileUploads;

    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    private const MAX_IMAGE_PIXELS = 40_000_000;

    private const ALLOWED_UPLOAD_EXTENSIONS = ['jpg', 'jpeg', 'png', 'svg', 'pdf', 'docx', 'xlsx', 'pptx'];

    private const OFFICE_EXTENSIONS = ['docx', 'xlsx', 'pptx'];

    private const BLOCKED_NAME_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml',
        'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'dll',
        'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'wsh', 'sh', 'bash', 'jar',
        'docm', 'xlsm', 'pptm',
    ];

    public User $user; // Store authenticated user once

    public bool $isShared;

    public ?Folder $currentFolder = null;

    public Collection $folders;

    public Collection $files;

    public array $finalCrumbs = [];

    public bool $showCreateFolderModal = false;

    public string $newFolderName = '';

    public array $filesToUpload = [];

    public bool $showPermissionsModal = false;

    public ?Folder $folderForPermissions = null;

    public ?Collection $allUsers = null;

    public array $selectedUsers = [];

    public string $userSearch = '';

    public ?int $editingFolderId = null;

    public string $editingFolderName = '';

    public ?int $editingFileId = null;

    public string $editingFileName = '';

    public string $permissionFilterType = '';

    public string $permissionFilterValue = '';

    public array $permissionFilterOptions = [];

    public array $activeFilters = [];

    public bool $allVisibleUsersSelected = false;

    public bool $showPreviewModal = false;

    public ?int $previewFileId = null;

    public bool $permissionUsersNeedRefresh = false;

    public function mount($folderId = null)
    {
        $this->user = auth()->user();
        // Derive from the route name so it can never be wrong, regardless of how
        // the component is mounted (full-page route, embedded tag, or AJAX re-render).
        $this->isShared = request()->routeIs('shared-folders.*');

        if ($folderId) {
            $folder = Folder::with('users')->find($folderId);
            if ($folder && ! $this->user->isActiveAdministrator() && ! $folder->users->contains($this->user->id)) {
                abort(403, 'You do not have permission to access this folder.');
            }
            $this->currentFolder = $folder;
        }
        $this->loadContent();
    }

    public function getPermissionFilterCategoriesProperty(): array
    {
        return [
            'department' => trans_choice('general.department', 1),
            'job_level' => trans_choice('general.job-level', 1),
            'program' => trans_choice('general.program', 1),
            'center' => trans_choice('general.center', 1),
            'city' => trans_choice('general.city', 1),
        ];
    }

    public function updatedPermissionFilterType($type)
    {
        $this->permissionFilterValue = '';
        $this->permissionFilterOptions = [];

        if ($type) {
            $collection = match ($type) {
                'department' => Department::all(),
                'program' => Program::all(),
                'job_level' => JobLevel::all(),
                'center' => Center::all(),
                'city' => City::all(),
                default => collect(),
            };

            $this->permissionFilterOptions = $collection
                ->mapWithKeys(fn ($item) => [$item->id => ($item->name ?? $item->name_en ?? $item->name_ar ?? (string) $item->id)])
                ->toArray();
        }
    }

    public function updatedPermissionFilterValue($value)
    {
        if ($this->permissionFilterType && $value) {
            $this->addFilter();
        }
    }

    public function addFilter()
    {
        if (! $this->permissionFilterType || ! $this->permissionFilterValue) {
            return;
        }

        $this->activeFilters[] = [
            'type' => $this->permissionFilterType,
            'value' => $this->permissionFilterValue,
            'type_label' => $this->permissionFilterCategories[$this->permissionFilterType],
            'value_label' => $this->permissionFilterOptions[$this->permissionFilterValue] ?? $this->permissionFilterValue,
        ];

        $this->permissionFilterType = '';
        $this->permissionFilterValue = '';
        $this->permissionFilterOptions = [];
        $this->allVisibleUsersSelected = false;
        $this->permissionUsersNeedRefresh = true;
    }

    public function removeFilter($index)
    {
        if (isset($this->activeFilters[$index])) {
            unset($this->activeFilters[$index]);
            $this->activeFilters = array_values($this->activeFilters);
            $this->allVisibleUsersSelected = false;
            $this->permissionUsersNeedRefresh = true;
        }
    }

    public function loadContent(): void
    {
        $parentId = $this->currentFolder?->id;
        $query = Folder::withCount('media as files_count');
        $query->where('parent_id', $parentId);

        if ($this->isShared) {
            $query->where(function ($q) {
                $q->where('is_shared', true)->orWhere(function ($subQ) {
                    $subQ->where('is_shared', false)->where('user_id', '!=', $this->user->id);
                });
            });
        } else {
            $query->where('is_shared', false)->where('user_id', $this->user->id);
        }

        if (! $this->user->isActiveAdministrator()) {
            $query->whereHas('users', fn ($q) => $q->where('user_id', $this->user->id));
        }

        $this->folders = $query->orderBy('name')->get();
        $this->files = $this->currentFolder
            ? $this->currentFolder->getMedia('archived_files')->filter(fn (Media $media) => $this->isAllowedArchiveMedia($media))->values()
            : collect();
        $this->generateBreadcrumbs();
    }

    public function createFolder()
    {
        $this->authorizeCurrentFolderManagement();
        $this->validate(['newFolderName' => 'required|string|max:255']);

        $folder = Folder::create([
            'name' => $this->newFolderName,
            'parent_id' => $this->currentFolder?->id,
            'user_id' => $this->user->id,
            'is_shared' => $this->isShared,
        ]);

        $inheritedUserIds = $this->currentFolder
            ? $this->currentFolder->users()->pluck('users.id')
            : collect();

        $inheritedUserIds->push((int) $this->user->id);
        $folder->users()->sync($inheritedUserIds->map(fn ($id) => (int) $id)->unique()->values());

        $this->showCreateFolderModal = false;
        $this->newFolderName = '';
        $this->loadContent();
        $this->editPermissions($folder->id);
    }

    public function editPermissions(int $folderId)
    {
        $folder = Folder::findOrFail($folderId);
        if ($folder->user_id !== $this->user->id && ! $this->user->isActiveAdministrator()) {
            abort(403, 'Only the creator can manage permissions.');
        }
        $this->folderForPermissions = $folder;
        $this->selectedUsers = $folder->users->pluck('id')->toArray();
        $this->allUsers = null;
        $this->permissionUsersNeedRefresh = true;
        $this->showPermissionsModal = true;
    }

    public function savePermissions()
    {
        if ($this->folderForPermissions) {
            if ($this->folderForPermissions->user_id !== $this->user->id && ! $this->user->isActiveAdministrator()) {
                abort(403, 'Only the creator can manage permissions.');
            }

            $newIds = collect($this->selectedUsers)->map(fn ($id) => (int) $id);
            $newIds->push((int) $this->folderForPermissions->user_id);
            $newIds = $newIds->unique();

            $existingIds = $this->folderForPermissions->users->pluck('id');
            $addedUserIds = $newIds->diff($existingIds);

            $this->folderForPermissions->users()->sync($newIds);

            if ($addedUserIds->isNotEmpty()) {
                $creatorName = $this->user->profile?->name ?? '-';
                foreach (User::whereIn('id', $addedUserIds)->get() as $user) {
                    $user->notify(new FolderShared($this->folderForPermissions, $creatorName));
                }

                $this->dispatch('notification-created');
            }
        }

        $this->closePermissionsModal();
        $this->loadContent();
    }

    public function closePermissionsModal()
    {
        $this->showPermissionsModal = false;
        $this->folderForPermissions = null;
        $this->selectedUsers = [];
        $this->userSearch = '';
        $this->permissionFilterType = '';
        $this->permissionFilterValue = '';
        $this->permissionFilterOptions = [];
        $this->activeFilters = [];
        $this->allVisibleUsersSelected = false;
        $this->permissionUsersNeedRefresh = false;
        $this->allUsers = null;
    }

    public function updatedUserSearch(): void
    {
        $this->permissionUsersNeedRefresh = true;
    }

    public function updatedFilesToUpload()
    {
        $this->authorizeCurrentFolderManagement();

        $rateKey = 'archive-upload:'.$this->user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            throw ValidationException::withMessages([
                'filesToUpload' => 'Too many uploads. Please wait a moment before trying again.',
            ]);
        }
        RateLimiter::hit($rateKey, 60);

        $this->validate([
            'filesToUpload' => ['array', 'max:20'],
            'filesToUpload.*' => ['file', 'max:10240'],
        ]);

        if (! $this->currentFolder) {
            $this->addError('filesToUpload', 'You must select a folder before uploading files.');

            return;
        }

        $failed = [];

        foreach ($this->filesToUpload as $file) {
            $safeFile = $this->validateArchiveUpload($file);

            try {
                if ($safeFile['extension'] === 'svg') {
                    $this->currentFolder
                        ->addMediaFromString($safeFile['contents'])
                        ->usingName($safeFile['displayName'])
                        ->usingFileName($safeFile['storedName'])
                        ->withCustomProperties(['validated_upload' => true])
                        ->toMediaCollection('archived_files', 'local');

                    continue;
                }

                $this->currentFolder
                    ->addMedia($file->getRealPath())
                    ->usingName($safeFile['displayName'])
                    ->usingFileName($safeFile['storedName'])
                    ->withCustomProperties(['validated_upload' => true])
                    ->toMediaCollection('archived_files', 'local');
            } catch (Throwable $e) {
                report($e);
                $failed[] = $safeFile['displayName'];
            }
        }

        $this->filesToUpload = [];
        $this->loadContent();

        if ($failed !== []) {
            $this->addError('filesToUpload', __('general.upload-failed-for').' '.implode(', ', $failed));
        }
    }

    public function downloadFile($mediaId)
    {
        $this->folderForMedia((int) $mediaId);

        return redirect()->route('folder-files.download', ['media' => $mediaId]);
    }

    public function previewFile(int $mediaId): void
    {
        $this->folderForMedia($mediaId);

        $this->previewFileId = $mediaId;
        $this->showPreviewModal = true;
    }

    public function closePreviewModal(): void
    {
        $this->showPreviewModal = false;
        $this->previewFileId = null;
    }

    public function confirmFileDeletion($mediaId)
    {
        $folder = $this->folderForMedia((int) $mediaId);
        $this->authorizeFolderManagement($folder);
        $media = Media::findOrFail($mediaId);
        $this->confirmItemDeletion('file', (int) $mediaId, $media->name);
    }

    public function confirmFolderDeletion($folderId)
    {
        $folder = Folder::findOrFail($folderId);
        $this->authorizeFolderManagement($folder);
        $this->confirmItemDeletion('folder', (int) $folderId, $folder->name);
    }

    /**
     * The payload comes back from the browser, so each branch authorizes the
     * item again rather than trusting that confirm*Deletion() already did.
     */
    public function deleteItem(array $payload): void
    {
        $id = (int) ($payload['id'] ?? 0);

        if (($payload['type'] ?? null) === 'file') {
            $folder = $this->folderForMedia($id);
            $this->authorizeFolderManagement($folder);
            Media::findOrFail($id)->delete();
        } elseif (($payload['type'] ?? null) === 'folder') {
            $folder = Folder::findOrFail($id);
            $this->authorizeFolderManagement($folder);
            $folder->delete();
        } else {
            return;
        }

        $this->toast();
        $this->loadContent();
    }

    public function startEditingFolder(int $folderId)
    {
        $folder = Folder::findOrFail($folderId);
        $this->authorizeFolderManagement($folder);
        $this->editingFolderId = $folder->id;
        $this->editingFolderName = $folder->name;
    }

    public function saveEditedFolder()
    {
        $this->validate(['editingFolderName' => 'required|string|max:255']);
        $folder = Folder::findOrFail($this->editingFolderId);
        $this->authorizeFolderManagement($folder);
        $folder->update(['name' => $this->editingFolderName]);
        $this->editingFolderId = null;
        $this->editingFolderName = '';
        $this->loadContent();
    }

    public function startEditingFile(int $fileId)
    {
        $folder = $this->folderForMedia($fileId);
        $this->authorizeFolderManagement($folder);
        $file = Media::findOrFail($fileId);
        $this->editingFileId = $file->id;
        $this->editingFileName = pathinfo($file->name, PATHINFO_FILENAME);
    }

    public function saveEditedFile()
    {
        $this->validate(['editingFileName' => 'required|string|max:255']);
        $file = Media::findOrFail($this->editingFileId);
        $folder = $this->folderForMedia((int) $file->id);
        $this->authorizeFolderManagement($folder);

        $newFileName = $this->safeDisplayFileName($this->editingFileName.'.'.$file->extension, $file->extension);
        $file->name = $newFileName;
        $file->save();

        $this->editingFileId = null;
        $this->editingFileName = '';
        $this->loadContent();
    }

    /**
     * Toggle a single user in/out of the selection.
     *
     * Selection is managed entirely server-side (the checkboxes are plain
     * wire:click buttons, NOT wire:model). This avoids Livewire's array-checkbox
     * morphing bug that previously assigned `true` to $selectedUsers and crashed
     * the modal whenever the filtered list re-rendered.
     */
    public function toggleUser(int $userId): void
    {
        // The creator always keeps access and cannot be toggled off.
        if ($userId === (int) $this->folderForPermissions?->user_id) {
            return;
        }

        $selected = array_map('intval', $this->selectedUsers);

        if (in_array($userId, $selected, true)) {
            $selected = array_diff($selected, [$userId]);
        } else {
            $selected[] = $userId;
        }

        // array_values keeps a sequential list so Livewire serializes it as a JSON array.
        $this->selectedUsers = array_values(array_unique($selected));
    }

    public function toggleSelectAllVisible(): void
    {
        $visibleUserIds = ($this->allUsers ?? collect())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $creatorId = (int) $this->folderForPermissions?->user_id;

        $toggleableUserIds = array_values(array_diff($visibleUserIds, [$creatorId]));
        $current = array_map('intval', $this->selectedUsers);

        // Decide from the actual selection state, not a stale flag.
        $allAlreadySelected = ! empty($toggleableUserIds) && empty(array_diff($toggleableUserIds, $current));

        if ($allAlreadySelected) {
            $this->selectedUsers = array_values(array_diff($current, $toggleableUserIds));
        } else {
            $this->selectedUsers = array_values(array_unique(array_merge($current, $toggleableUserIds)));
        }
    }

    public function clearFilters(): void
    {
        $this->activeFilters = [];
        $this->permissionFilterType = '';
        $this->permissionFilterValue = '';
        $this->permissionFilterOptions = [];
        $this->permissionUsersNeedRefresh = true;
    }

    public function render()
    {
        $hasAccessUsers = collect();
        $noAccessUsers = collect();

        if ($this->showPermissionsModal) {
            if ($this->allUsers === null || $this->permissionUsersNeedRefresh) {
                $this->allUsers = $this->getVisibleUsersQuery()->get();
                $this->permissionUsersNeedRefresh = false;
            }

            $creatorId = (int) $this->folderForPermissions?->user_id;
            $selectedIds = array_map('intval', $this->selectedUsers);

            [$creatorCollection, $otherUsers] = $this->allUsers->partition(fn ($u) => (int) $u->id === $creatorId);
            [$withAccess, $withoutAccess] = $otherUsers->partition(fn ($u) => in_array((int) $u->id, $selectedIds, true));

            $hasAccessUsers = $creatorCollection->merge(
                $withAccess->sortBy(fn ($u) => optional($u->profile)->name ?? $u->email),
            );
            $noAccessUsers = $withoutAccess
                ->sortBy(fn ($u) => optional($u->profile)->name ?? $u->email)
                ->values();

            $this->allUsers = $hasAccessUsers->merge($noAccessUsers);

            $toggleable = $otherUsers->pluck('id')->map(fn ($id) => (int) $id);
            $this->allVisibleUsersSelected = $toggleable->isNotEmpty()
                && $toggleable->every(fn ($id) => in_array($id, $selectedIds, true));
        }

        $previewFile = null;

        if ($this->previewFileId) {
            $this->folderForMedia((int) $this->previewFileId);
            $previewFile = Media::find($this->previewFileId);
        }

        return view('livewire.archives.archived-files-module', [
            'hasAccessUsers' => $hasAccessUsers,
            'noAccessUsers' => $noAccessUsers,
            'canManageCurrentFolder' => $this->userCanManageFolder($this->currentFolder),
            'previewFile' => $previewFile,
            'convertedOfficePdfUrl' => $this->convertedOfficePdfUrl($previewFile),
            'officePreviewData' => $this->officePreviewData($previewFile),
        ]);
    }

    protected function generateBreadcrumbs(): void
    {
        $baseCrumbUrl = $this->isShared ? route('shared-folders.index') : route('my-folders.index');
        $crumbs = [['label' => __('general.archived-files'), 'url' => $baseCrumbUrl]];
        $folderLinks = [];
        $folder = $this->currentFolder;

        while ($folder) {
            array_unshift($folderLinks, [
                'label' => $folder->name,
                'url' => $this->isShared ? route('shared-folders.index', ['folderId' => $folder->id]) : route('my-folders.index', ['folderId' => $folder->id]),
            ]);
            $folder = $folder->parent;
        }

        $this->finalCrumbs = array_merge($crumbs, $folderLinks);

        if (count($this->finalCrumbs) > 1) {
            $lastIndex = count($this->finalCrumbs) - 1;
            unset($this->finalCrumbs[$lastIndex]['url']);
        }
    }

    private function confirmItemDeletion(string $type, int $id, string $name): void
    {
        $this->confirm('deleteItem', ['type' => $type, 'id' => $id],
            title: __('general.delete_item'),
            message: __('general.delete_confirmation', ['item' => $name]),
        );
    }

    private function applyPermissionFilters(Builder $query): Builder
    {
        if (empty($this->activeFilters)) {
            return $query;
        }

        return $query->where(function ($q) {
            foreach ($this->activeFilters as $filter) {
                $q->whereHas('profile.contracts.position', function ($positionQuery) use ($filter) {
                    match ($filter['type']) {
                        'department' => $positionQuery->where('department_id', $filter['value']),
                        'job_level' => $positionQuery->where('job_level_id', $filter['value']),
                        'program' => $positionQuery->where('program_id', $filter['value']),
                        'center' => $positionQuery->where('center_id', $filter['value']),
                        'city' => $positionQuery->where('city_id', $filter['value']),
                        default => null,
                    };
                });
            }
        });
    }

    private function userCanAccessFolder(Folder $folder): bool
    {
        return $this->user->isActiveAdministrator()
            || $folder->users()->whereKey($this->user->id)->exists();
    }

    private function userCanManageFolder(?Folder $folder = null): bool
    {
        if ($this->isShared && ! $this->user->isActiveAdministrator()) {
            return false;
        }

        if (! $folder) {
            return ! $this->isShared || $this->user->isActiveAdministrator();
        }

        return $this->user->isActiveAdministrator() || (int) $folder->user_id === (int) $this->user->id;
    }

    private function authorizeSharedAction(): void
    {
        if ($this->isShared && ! $this->user->isActiveAdministrator()) {
            abort(403, 'You are not authorized to perform this action in Shared Folders.');
        }
    }

    private function authorizeCurrentFolderManagement(): void
    {
        if (! $this->userCanManageFolder($this->currentFolder)) {
            abort(403, 'Only the folder creator can manage this folder.');
        }
    }

    private function authorizeFolderManagement(Folder $folder): void
    {
        if (! $this->userCanManageFolder($folder)) {
            abort(403, 'Only the folder creator can manage this folder.');
        }
    }

    private function folderForMedia(int $mediaId): Folder
    {
        $media = Media::findOrFail($mediaId);

        abort_if($media->model_type !== Folder::class, 404);

        $folder = Folder::with('users')->findOrFail($media->model_id);

        if (! $this->userCanAccessFolder($folder)) {
            abort(403, 'You do not have permission to access this file.');
        }

        abort_unless($this->isAllowedArchiveMedia($media), 404);

        return $folder;
    }

    private function isAllowedArchiveMedia(Media $media): bool
    {
        return in_array(strtolower($media->extension ?? ''), self::ALLOWED_UPLOAD_EXTENSIONS, true);
    }

    private function validateArchiveUpload(TemporaryUploadedFile $file): array
    {
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();
        $size = $file->getSize();

        if (! $path || ! is_file($path) || $size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            $this->rejectUpload($originalName, 'The file is empty or exceeds the 10 MB limit.');
        }

        if (! in_array($extension, self::ALLOWED_UPLOAD_EXTENSIONS, true)) {
            $this->rejectUpload($originalName, 'Only JPG, JPEG, PNG, SVG, PDF, DOCX, XLSX, and PPTX files are allowed.');
        }

        $this->rejectSuspiciousFileName($originalName);
        $this->rejectEmbeddedPhp($path, $originalName);

        match ($extension) {
            'jpg', 'jpeg' => $this->validateJpeg($path, $originalName),
            'png' => $this->validatePng($path, $originalName),
            'svg' => $svg = $this->sanitizeSvg($path, $originalName),
            'pdf' => $this->validatePdf($path, $originalName),
            'docx', 'xlsx', 'pptx' => $this->validateOfficeOpenXml($path, $extension, $originalName),
            default => $this->rejectUpload($originalName, 'This file type is not allowed.'),
        };

        $displayName = $this->safeDisplayFileName($originalName, $extension);

        return [
            'extension' => $extension,
            'displayName' => $displayName,
            'storedName' => (string) Str::uuid().'.'.$extension,
            'contents' => $svg ?? null,
        ];
    }

    private function rejectSuspiciousFileName(string $originalName): void
    {
        $lowerName = strtolower($originalName);

        foreach (self::BLOCKED_NAME_EXTENSIONS as $blockedExtension) {
            if (preg_match('/\.'.preg_quote($blockedExtension, '/').'(\.|$)/i', $lowerName)) {
                $this->rejectUpload($originalName, 'The file name contains a blocked executable or macro extension.');
            }
        }

        if (str_contains($originalName, "\0") || str_contains($originalName, '/') || str_contains($originalName, '\\')) {
            $this->rejectUpload($originalName, 'The file name contains unsafe characters.');
        }
    }

    private function rejectEmbeddedPhp(string $path, string $originalName): void
    {
        $handle = fopen($path, 'rb');

        if (! $handle) {
            $this->rejectUpload($originalName, 'The file could not be inspected safely.');
        }

        // Scan the full file in 1 MB chunks; keep an 8-byte tail so a tag
        // split across two chunks is still caught.
        $tail = '';
        while (! feof($handle)) {
            $chunk = fread($handle, 1024 * 1024) ?: '';
            $window = $tail.$chunk;
            if (stripos($window, '<?php') !== false || stripos($window, '<?=') !== false) {
                fclose($handle);
                $this->rejectUpload($originalName, 'The file contains executable PHP code.');
            }
            $tail = substr($chunk, -8);
        }
        fclose($handle);
    }

    private function validateJpeg(string $path, string $originalName): void
    {
        $mime = File::mimeType($path);

        if ($mime !== 'image/jpeg' || ! str_starts_with((string) file_get_contents($path, false, null, 0, 3), "\xFF\xD8\xFF")) {
            $this->rejectUpload($originalName, 'The file is not a valid JPEG image.');
        }

        $this->validateImageDimensions($path, $originalName);
    }

    private function validatePng(string $path, string $originalName): void
    {
        $mime = File::mimeType($path);
        $signature = file_get_contents($path, false, null, 0, 8);

        if ($mime !== 'image/png' || $signature !== "\x89PNG\r\n\x1A\n") {
            $this->rejectUpload($originalName, 'The file is not a valid PNG image.');
        }

        $this->validateImageDimensions($path, $originalName);
    }

    private function validateImageDimensions(string $path, string $originalName): void
    {
        $image = @getimagesize($path);

        if (! $image || empty($image[0]) || empty($image[1])) {
            $this->rejectUpload($originalName, 'The image dimensions could not be verified.');
        }

        if (($image[0] * $image[1]) > self::MAX_IMAGE_PIXELS) {
            $this->rejectUpload($originalName, 'The image dimensions are too large.');
        }
    }

    private function sanitizeSvg(string $path, string $originalName): string
    {
        $contents = file_get_contents($path);

        if (! is_string($contents) || trim($contents) === '') {
            $this->rejectUpload($originalName, 'The SVG file is empty.');
        }

        $lower = strtolower($contents);
        $blockedPatterns = [
            '<script', '<foreignobject', '<iframe', '<object', '<embed', '<link',
            'javascript:', 'vbscript:', 'data:text/html', 'data:application',
            '<?php', '<![cdata[', 'onload=', 'onclick=', 'onerror=', 'onmouseover=',
        ];

        foreach ($blockedPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                $this->rejectUpload($originalName, 'The SVG contains active or external content.');
            }
        }

        if (preg_match('/\son[a-z0-9_-]+\s*=/i', $contents)) {
            $this->rejectUpload($originalName, 'The SVG contains event handler attributes.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || strtolower($document->documentElement?->localName ?? '') !== 'svg') {
            $this->rejectUpload($originalName, 'The file is not a valid SVG image.');
        }

        return $document->saveXML($document->documentElement) ?: $contents;
    }

    private function validatePdf(string $path, string $originalName): void
    {
        $mime = File::mimeType($path);
        $signature = file_get_contents($path, false, null, 0, 5);

        if (! in_array($mime, ['application/pdf', 'application/octet-stream'], true) || $signature !== '%PDF-') {
            $this->rejectUpload($originalName, 'The file is not a valid PDF document.');
        }
    }

    private function validateOfficeOpenXml(string $path, string $extension, string $originalName): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->rejectUpload($originalName, 'Office file validation is not available on this server.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            $this->rejectUpload($originalName, 'The Office file is not a valid zipped Office document.');
        }

        $maxEntries = (int) config('uploads.office.max_entries', 1000);
        $maxTotalBytes = (int) config('uploads.office.max_total_uncompressed_bytes', 100 * 1024 * 1024);
        $maxEntryBytes = (int) config('uploads.office.max_entry_uncompressed_bytes', 20 * 1024 * 1024);
        $maxRatio = (float) config('uploads.office.max_compression_ratio', 50);

        if ($zip->numFiles < 1 || $zip->numFiles > $maxEntries) {
            $zip->close();
            $this->rejectUpload($originalName, 'The Office archive contains too many entries.');
        }

        $requiredEntries = match ($extension) {
            'docx' => ['[Content_Types].xml', 'word/document.xml'],
            'xlsx' => ['[Content_Types].xml', 'xl/workbook.xml'],
            'pptx' => ['[Content_Types].xml', 'ppt/presentation.xml'],
        };

        foreach ($requiredEntries as $entry) {
            if ($zip->locateName($entry) === false) {
                $zip->close();
                $this->rejectUpload($originalName, 'The Office file structure is invalid.');
            }
        }

        $totalUncompressedBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if (! is_array($stat)) {
                $zip->close();
                $this->rejectUpload($originalName, 'The Office archive could not be inspected safely.');
            }

            $uncompressedBytes = (int) ($stat['size'] ?? 0);
            $compressedBytes = (int) ($stat['comp_size'] ?? 0);
            $totalUncompressedBytes += $uncompressedBytes;

            if ($uncompressedBytes < 0
                || $compressedBytes < 0
                || $uncompressedBytes > $maxEntryBytes
                || $totalUncompressedBytes > $maxTotalBytes
                || ($uncompressedBytes > 0 && $compressedBytes === 0)
                || ($compressedBytes > 0 && ($uncompressedBytes / $compressedBytes) > $maxRatio)
                || (int) ($stat['encryption_method'] ?? 0) !== 0) {
                $zip->close();
                $this->rejectUpload($originalName, 'The Office archive exceeds safe expansion limits or is encrypted.');
            }

            $entryName = $zip->getNameIndex($index) ?: '';
            $lowerEntry = strtolower($entryName);

            if (
                str_contains($entryName, "\0")
                || str_starts_with($entryName, '/')
                || str_contains($entryName, '../')
                || str_contains($entryName, '..\\')
            ) {
                $zip->close();
                $this->rejectUpload($originalName, 'The Office file contains unsafe archive paths.');
            }

            if (
                str_contains($lowerEntry, 'vbaproject.bin')
                || str_contains($lowerEntry, '/activex/')
                || str_contains($lowerEntry, '/embeddings/')
                || preg_match('/\.(php|phtml|phar|js|html?|exe|bat|cmd|ps1|vbs|jar)$/i', $lowerEntry)
            ) {
                $zip->close();
                $this->rejectUpload($originalName, 'The Office file contains macros, embedded objects, or unsafe payloads.');
            }
        }

        $relationships = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index) ?: '';
            if (str_ends_with(strtolower($entryName), '.rels')) {
                $relationships[] = $zip->getFromName($entryName) ?: '';
            }
        }

        $zip->close();

        foreach ($relationships as $relationshipXml) {
            if (preg_match('/TargetMode\s*=\s*["\']External["\']/i', $relationshipXml)) {
                $this->rejectUpload($originalName, 'The Office file contains external links or remote content.');
            }
        }
    }

    private function safeDisplayFileName(string $originalName, string $extension): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $safeBase = Str::of($baseName)
            ->replaceMatches('/[^A-Za-z0-9\-_ .\p{Arabic}]/u', '')
            ->squish()
            ->trim()
            ->limit(120, '');

        return ($safeBase->isNotEmpty() ? (string) $safeBase : 'archive-file').'.'.$extension;
    }

    private function rejectUpload(string $originalName, string $reason): never
    {
        throw ValidationException::withMessages([
            'filesToUpload' => $originalName.': '.$reason,
        ]);
    }

    private function decodeXmlText(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function officeConverterPath(): ?string
    {
        static $resolved = false;
        static $path = null;

        if ($resolved) {
            return $path;
        }

        $configured = env('OFFICE_PREVIEW_SOFFICE_PATH');

        $candidates = array_filter([
            $configured,
            'soffice',
            'libreoffice',
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        ]);

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '\\') || str_contains($candidate, '/')) {
                if (is_file($candidate)) {
                    $resolved = true;
                    $path = $candidate;

                    return $path;
                }

                continue;
            }

            try {
                $process = new Process([$candidate, '--version']);
                $process->setTimeout(5);
                $process->run();

                if ($process->isSuccessful()) {
                    $resolved = true;
                    $path = $candidate;

                    return $path;
                }
            } catch (Throwable) {
                continue;
            }
        }

        $resolved = true;

        return null;
    }

    private function convertedOfficePdfUrl(?Media $media): ?string
    {
        if (! $media || ! config('uploads.office.conversion_enabled', false)) {
            return null;
        }

        $extension = strtolower($media->extension ?? '');

        if (! in_array($extension, self::OFFICE_EXTENSIONS, true)) {
            return null;
        }

        $previewDirectory = storage_path('app/private/office-previews');
        File::ensureDirectoryExists($previewDirectory);

        $targetName = $this->officePreviewFileName($media);
        $targetPath = $previewDirectory.DIRECTORY_SEPARATOR.$targetName;

        if (is_file($targetPath)) {
            return route('folder-files.office-preview', ['media' => $media->id]);
        }

        if ($this->convertOfficeWithLibreOffice($media, $previewDirectory, $targetPath)) {
            return route('folder-files.office-preview', ['media' => $media->id]);
        }

        if ($this->convertOfficeWithMicrosoftOffice($media, $targetPath)) {
            return route('folder-files.office-preview', ['media' => $media->id]);
        }

        return null;
    }

    private function officePreviewFileName(Media $media): string
    {
        return $media->id.'-'.sha1($media->file_name.'|'.$media->size).'.pdf';
    }

    private function convertOfficeWithLibreOffice(Media $media, string $previewDirectory, string $targetPath): bool
    {
        $converter = $this->officeConverterPath();

        if (! $converter) {
            return false;
        }

        $expectedOutput = $previewDirectory.DIRECTORY_SEPARATOR.pathinfo($media->getPath(), PATHINFO_FILENAME).'.pdf';

        try {
            $process = new Process([
                $converter,
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $previewDirectory,
                $media->getPath(),
            ]);
            $process->setTimeout(90);
            $process->run();
        } catch (Throwable) {
            return false;
        }

        if (! is_file($expectedOutput)) {
            return false;
        }

        if ($expectedOutput !== $targetPath) {
            File::move($expectedOutput, $targetPath);
        }

        return is_file($targetPath);
    }

    private function convertOfficeWithMicrosoftOffice(Media $media, string $targetPath): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        $extension = strtolower($media->extension ?? '');
        $input = $media->getPath();

        $script = match ($extension) {
            'doc', 'docx' => <<<'POWERSHELL'
$ErrorActionPreference = "Stop"
$word = $null
$document = $null
try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $document = $word.Documents.Open($input, $false, $true)
    $document.ExportAsFixedFormat($output, 17)
} finally {
    if ($document -ne $null) { $document.Close($false) | Out-Null }
    if ($word -ne $null) { $word.Quit() | Out-Null }
}
POWERSHELL,
            'xls', 'xlsx' => <<<'POWERSHELL'
$ErrorActionPreference = "Stop"
$excel = $null
$workbook = $null
try {
    $excel = New-Object -ComObject Excel.Application
    $excel.Visible = $false
    $excel.DisplayAlerts = $false
    $workbook = $excel.Workbooks.Open($input, 3, $true)
    $workbook.ExportAsFixedFormat(0, $output)
} finally {
    if ($workbook -ne $null) { $workbook.Close($false) | Out-Null }
    if ($excel -ne $null) { $excel.Quit() | Out-Null }
}
POWERSHELL,
            'ppt', 'pptx' => <<<'POWERSHELL'
$ErrorActionPreference = "Stop"
$powerPoint = $null
$presentation = $null
try {
    $powerPoint = New-Object -ComObject PowerPoint.Application
    $presentation = $powerPoint.Presentations.Open($input, $true, $true, $false)
    $presentation.SaveAs($output, 32)
} finally {
    if ($presentation -ne $null) { $presentation.Close() | Out-Null }
    if ($powerPoint -ne $null) { $powerPoint.Quit() | Out-Null }
}
POWERSHELL,
            default => null,
        };

        if (! $script) {
            return false;
        }

        $encodedInput = base64_encode(mb_convert_encoding($input, 'UTF-16LE', 'UTF-8'));
        $encodedOutput = base64_encode(mb_convert_encoding($targetPath, 'UTF-16LE', 'UTF-8'));
        $bootstrap = <<<'POWERSHELL'
$input = [System.Text.Encoding]::Unicode.GetString([System.Convert]::FromBase64String("__INPUT__"))
$output = [System.Text.Encoding]::Unicode.GetString([System.Convert]::FromBase64String("__OUTPUT__"))
POWERSHELL;

        $fullScript = str_replace(
            ['__INPUT__', '__OUTPUT__'],
            [$encodedInput, $encodedOutput],
            $bootstrap,
        ).PHP_EOL.$script;

        try {
            $process = new Process([
                'powershell',
                '-NoProfile',
                '-ExecutionPolicy',
                'Bypass',
                '-Command',
                $fullScript,
            ]);
            $process->setTimeout(120);
            $process->run();
        } catch (Throwable) {
            return false;
        }

        return is_file($targetPath);
    }

    private function officePreviewData(?Media $media): array
    {
        if (! $media || ! class_exists(ZipArchive::class)) {
            return ['available' => false, 'message' => 'Preview is not available for this file on this server.'];
        }

        $extension = strtolower($media->extension ?? '');

        if (! in_array($extension, ['docx', 'xlsx'], true)) {
            return ['available' => false, 'message' => 'Preview is not available for this file type.'];
        }

        $zip = new ZipArchive;

        if ($zip->open($media->getPath()) !== true) {
            return ['available' => false, 'message' => 'This file could not be opened for preview.'];
        }

        try {
            return match ($extension) {
                'docx' => $this->docxPreviewData($zip),
                'xlsx' => $this->xlsxPreviewData($zip),
                default => ['available' => false, 'message' => 'Preview is not available for this file type.'],
            };
        } finally {
            $zip->close();
        }
    }

    private function docxPreviewData(ZipArchive $zip): array
    {
        $xml = $this->readPreviewXml($zip, 'word/document.xml');

        if (! $xml) {
            return ['available' => false, 'message' => 'No document content was found in this file.'];
        }

        preg_match_all('/<w:p\b.*?<\/w:p>/s', $xml, $paragraphMatches);

        $paragraphs = collect($paragraphMatches[0] ?? [])
            ->map(function ($paragraph) {
                preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $paragraph, $textMatches);

                return $this->decodeXmlText(implode('', $textMatches[1] ?? []));
            })
            ->filter()
            ->take(80)
            ->values()
            ->all();

        return [
            'available' => ! empty($paragraphs),
            'type' => 'docx',
            'paragraphs' => $paragraphs,
            'message' => empty($paragraphs) ? 'No readable text was found in this Word document.' : null,
        ];
    }

    private function xlsxPreviewData(ZipArchive $zip): array
    {
        $sharedStrings = [];
        $sharedXml = $this->readPreviewXml($zip, 'xl/sharedStrings.xml');

        if ($sharedXml) {
            preg_match_all('/<si\b.*?<\/si>/s', $sharedXml, $stringMatches);
            foreach ($stringMatches[0] ?? [] as $item) {
                preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $item, $textMatches);
                $sharedStrings[] = $this->decodeXmlText(implode('', $textMatches[1] ?? []));
            }
        }

        $sheetName = null;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (preg_match('/^xl\/worksheets\/sheet\d+\.xml$/', $name)) {
                $sheetName = $name;
                break;
            }
        }

        $sheetXml = $sheetName ? $this->readPreviewXml($zip, $sheetName) : null;

        if (! $sheetXml) {
            return ['available' => false, 'message' => 'No worksheet content was found in this Excel file.'];
        }

        preg_match_all('/<row\b.*?<\/row>/s', $sheetXml, $rowMatches);

        $rows = [];
        foreach (array_slice($rowMatches[0] ?? [], 0, 30) as $rowXml) {
            preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $rowXml, $cellMatches, PREG_SET_ORDER);
            $row = [];

            foreach ($cellMatches as $cellMatch) {
                $attributes = $cellMatch[1] ?? '';
                $cellXml = $cellMatch[2] ?? '';
                preg_match('/\bt="([^"]+)"/', $attributes, $typeMatch);
                preg_match('/<v[^>]*>(.*?)<\/v>/s', $cellXml, $valueMatch);
                preg_match('/<is\b.*?<t[^>]*>(.*?)<\/t>.*?<\/is>/s', $cellXml, $inlineMatch);

                $type = $typeMatch[1] ?? '';
                $value = $valueMatch[1] ?? '';

                if ($type === 's') {
                    $row[] = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $row[] = $this->decodeXmlText($inlineMatch[1] ?? '');
                } else {
                    $row[] = $this->decodeXmlText($value);
                }
            }

            if (! empty(array_filter($row, fn ($value) => filled($value)))) {
                $rows[] = array_slice($row, 0, 12);
            }
        }

        return [
            'available' => ! empty($rows),
            'type' => 'xlsx',
            'rows' => $rows,
            'message' => empty($rows) ? 'No readable cells were found in this Excel file.' : null,
        ];
    }

    private function readPreviewXml(ZipArchive $zip, string $entry): ?string
    {
        $stat = $zip->statName($entry);
        $maxBytes = (int) config('uploads.office.max_preview_xml_bytes', 10 * 1024 * 1024);

        if (! is_array($stat) || (int) ($stat['size'] ?? 0) > $maxBytes) {
            return null;
        }

        $contents = $zip->getFromName($entry, $maxBytes + 1);

        return is_string($contents) && strlen($contents) <= $maxBytes
            ? $contents
            : null;
    }

    private function getVisibleUsersQuery(): Builder
    {
        $query = User::query()->with('profile')->whereHas('profile');

        if (trim($this->userSearch)) {
            $parts = array_filter(explode(' ', trim($this->userSearch)));

            $query->whereHas('profile', function ($q) use ($parts) {
                $q->where(function ($outer) use ($parts) {
                    foreach ($parts as $part) {
                        $outer->where(function ($inner) use ($part) {
                            $inner->where('first_name_en', 'LIKE', "%{$part}%")
                                ->orWhere('first_name_ar', 'LIKE', "%{$part}%")
                                ->orWhere('last_name_en', 'LIKE', "%{$part}%")
                                ->orWhere('last_name_ar', 'LIKE', "%{$part}%");
                        });
                    }
                });
            });
        }

        return $this->applyPermissionFilters($query);
    }
}
