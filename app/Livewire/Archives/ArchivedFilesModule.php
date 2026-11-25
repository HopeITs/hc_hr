<?php

namespace App\Livewire\Archives;

use App\Livewire\Concerns\WithFeedback;
use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\Folder;
use App\Models\FolderGroupShare;
use App\Models\JobLevel;
use App\Models\MailList;
use App\Models\Program;
use App\Models\User;
use App\Notifications\FolderShared;
use App\Services\Folders\FolderAccess;
use DOMDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
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

    /** Also read by DashboardLayout: a file card is only for a type this page shows. */
    public const ALLOWED_UPLOAD_EXTENSIONS = ['jpg', 'jpeg', 'png', 'svg', 'pdf', 'docx', 'xlsx', 'pptx'];

    /** The ?scope= value of "Shared with me"; anything else is "My folders". */
    public const SHARED_SCOPE = 'shared';

    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    private const MAX_IMAGE_PIXELS = 40_000_000;

    private const OFFICE_EXTENSIONS = ['docx', 'xlsx', 'pptx'];

    private const BLOCKED_NAME_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml',
        'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'dll',
        'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'wsh', 'sh', 'bash', 'jar',
        'docm', 'xlsm', 'pptm',
    ];

    public User $user; // Store authenticated user once

    #[Locked]
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

    /** @var array<int|string, string> each selected person's role (FolderAccess::SHARE_ROLES), by user id */
    public array $userRoles = [];

    /** @var list<array{type: string, id: int, role: string, label: string}> the groups the folder is shared with */
    public array $groupShares = [];

    public string $groupType = '';

    public string $groupValue = '';

    public string $groupRole = FolderAccess::VIEWER;

    /** @var array<int|string, string> */
    public array $groupOptions = [];

    public function mount($folderId = null)
    {
        $this->user = auth()->user();
        // Read once from the page's ?scope=; locked, so a later update cannot flip it.
        $this->isShared = request()->query('scope') === self::SHARED_SCOPE;

        if ($folderId) {
            $folder = Folder::query()->find($folderId);
            if ($folder && ! $this->access()->canView($this->user, $folder)) {
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
            // Everyone on one of the organisation's mailing lists (their work email).
            'mail_list' => __('mail_lists.folder_filter'),
        ];
    }

    public function updatedPermissionFilterType($type)
    {
        $this->permissionFilterValue = '';
        $this->permissionFilterOptions = $type ? $this->groupOptionsFor((string) $type) : [];
    }

    /** "Share with a group": the second list follows the type chosen. */
    public function updatedGroupType($type): void
    {
        $this->groupValue = '';
        $this->groupOptions = in_array($type, FolderAccess::GROUP_TYPES, true) ? $this->groupOptionsFor((string) $type) : [];
    }

    /** Adds the chosen group to the dialog's list; nothing is saved until "Save". */
    public function addGroupShare(): void
    {
        $this->authorizePermissionsFolder();

        $type = $this->groupType;
        $id = (int) $this->groupValue;
        $options = in_array($type, FolderAccess::GROUP_TYPES, true) ? $this->groupOptionsFor($type) : [];

        if (! isset($options[$id])) {
            $this->addError('groupValue', __('folders.share.pick-group'));

            return;
        }

        $role = in_array($this->groupRole, FolderAccess::SHARE_ROLES, true) ? $this->groupRole : FolderAccess::VIEWER;

        $this->groupShares = collect($this->groupShares)
            ->reject(fn (array $share) => $share['type'] === $type && (int) $share['id'] === $id)
            ->push(['type' => $type, 'id' => $id, 'role' => $role, 'label' => $options[$id]])
            ->values()->all();

        $this->groupType = '';
        $this->groupValue = '';
        $this->groupOptions = [];
        $this->groupRole = FolderAccess::VIEWER;
        $this->resetErrorBag('groupValue');
    }

    public function removeGroupShare(int $index): void
    {
        $this->authorizePermissionsFolder();

        unset($this->groupShares[$index]);
        $this->groupShares = array_values($this->groupShares);
    }

    public function setGroupRole(int $index, string $role): void
    {
        $this->authorizePermissionsFolder();

        if (isset($this->groupShares[$index]) && in_array($role, FolderAccess::SHARE_ROLES, true)) {
            $this->groupShares[$index]['role'] = $role;
        }
    }

    public function setUserRole(int $userId, string $role): void
    {
        $this->authorizePermissionsFolder();

        if (in_array($userId, array_map('intval', $this->selectedUsers), true) && in_array($role, FolderAccess::SHARE_ROLES, true)) {
            $this->userRoles[$userId] = $role;
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
        $access = $this->access();
        $with = ['creator.profile', 'groupShares'];

        if ($this->currentFolder) {
            // Inside a folder: its subfolders this person may open (most take the folder's access).
            $folders = Folder::query()->withCount('media as files_count')->with($with)
                ->where('parent_id', $this->currentFolder->id)
                ->orderBy('name')->get()
                ->filter(fn (Folder $folder) => $access->canView($this->user, $folder))
                ->values();
        } elseif (! $this->isShared) {
            // My folders: the ones I own.
            $folders = Folder::query()->withCount('media as files_count')->with($with)
                ->whereNull('parent_id')->where('user_id', $this->user->id)
                ->orderBy('name')->get();
        } elseif ($this->user->isActiveAdministrator()) {
            // An administrator sees every other person's folders here.
            $folders = Folder::query()->withCount('media as files_count')->with($with)
                ->whereNull('parent_id')
                ->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $this->user->id))
                ->orderBy('name')->get();
        } else {
            // Shared with me: shared by name or through a group I am in now, at any depth.
            $folders = $access->sharedWith($this->user)->withCount('media as files_count')->with($with)
                ->orderBy('name')->get();
        }

        $this->folders = $folders;
        $this->files = $this->currentFolder
            ? $this->currentFolder->getMedia('archived_files')->filter(fn (Media $media) => $this->isAllowedArchiveMedia($media))->values()
            : collect();
        $this->generateBreadcrumbs();
    }

    public function createFolder()
    {
        $this->authorizeCurrentFolderManagement();
        $this->validate(['newFolderName' => 'required|string|max:255']);

        // Whoever makes it owns it; inside another folder it takes that folder's access.
        $folder = Folder::create([
            'name' => $this->newFolderName,
            'parent_id' => $this->currentFolder?->id,
            'user_id' => $this->user->id,
            'is_shared' => false,
            'inherits_access' => true,
        ]);

        $this->showCreateFolderModal = false;
        $this->newFolderName = '';
        $this->loadContent();

        // A new top-level folder is shared at once; a subfolder already has its parent's people.
        if (! $this->currentFolder) {
            $this->editPermissions($folder->id);
        }
    }

    public function editPermissions(int $folderId)
    {
        $folder = Folder::query()->with(['users', 'groupShares'])->findOrFail($folderId);
        abort_unless($this->access()->canEdit($this->user, $folder), 403, 'Only the owner or an editor can share this folder.');

        $this->folderForPermissions = $folder;
        $owner = (int) $folder->user_id;
        $people = $folder->users->reject(fn (User $user) => (int) $user->id === $owner);
        $this->selectedUsers = $people->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $this->userRoles = $people->mapWithKeys(fn (User $user) => [(int) $user->id => $this->shareRole($user->pivot->role)])->all();
        $options = [];
        $this->groupShares = $folder->groupShares->map(function (FolderGroupShare $share) use (&$options) {
            $options[$share->group_type] ??= $this->groupOptionsFor($share->group_type);

            return [
                'type' => $share->group_type,
                'id' => (int) $share->group_id,
                'role' => $this->shareRole($share->role),
                'label' => $options[$share->group_type][$share->group_id] ?? '#'.$share->group_id,
            ];
        })->values()->all();
        $this->allUsers = null;
        $this->permissionUsersNeedRefresh = true;
        $this->showPermissionsModal = true;
    }

    public function savePermissions()
    {
        $folder = $this->authorizePermissionsFolder();
        $owner = (int) $folder->user_id;

        // People, each with a role; the owner is the owner, not a share.
        $people = collect($this->selectedUsers)->map(fn ($id) => (int) $id)->unique()
            ->reject(fn (int $id) => $id === $owner)
            ->mapWithKeys(fn (int $id) => [$id => ['role' => $this->shareRole($this->userRoles[$id] ?? null)]]);

        $before = $folder->users()->pluck('users.id')->map(fn ($id) => (int) $id);
        $folder->users()->sync($people->all());

        // Groups, kept as groups: whoever is in one later has access too.
        $wanted = collect($this->groupShares)
            ->filter(fn (array $share) => in_array($share['type'] ?? null, FolderAccess::GROUP_TYPES, true))
            ->keyBy(fn (array $share) => $share['type'].':'.(int) $share['id']);
        $existing = $folder->groupShares()->get()->keyBy(fn (FolderGroupShare $share) => $share->group_type.':'.$share->group_id);

        $existing->except($wanted->keys()->all())->each->delete();
        $newGroups = [];

        foreach ($wanted as $key => $share) {
            if (! $existing->has($key)) {
                $newGroups[] = $share;
            }

            FolderGroupShare::query()->updateOrCreate(
                ['folder_id' => $folder->id, 'group_type' => $share['type'], 'group_id' => (int) $share['id']],
                ['role' => $this->shareRole($share['role'] ?? null), 'shared_by_user_id' => $existing->get($key)?->shared_by_user_id ?? $this->user->id],
            );
        }

        // Tell who was just given access: the people added, and who is in a group added now.
        $told = $people->keys()->diff($before);

        foreach ($newGroups as $share) {
            $told = $told->merge($this->access()->members($share['type'], (int) $share['id'])->pluck('users.id'));
        }

        $told = $told->map(fn ($id) => (int) $id)->unique()->reject(fn (int $id) => in_array($id, [$owner, (int) $this->user->id], true));

        if ($told->isNotEmpty()) {
            Notification::send(
                User::query()->whereIn('id', $told->all())->get(),
                new FolderShared($folder, $this->user->profile?->name ?? '-'),
            );
            $this->dispatch('notification-created');
        }

        $this->closePermissionsModal();
        $this->loadContent();
    }

    public function closePermissionsModal()
    {
        $this->showPermissionsModal = false;
        $this->folderForPermissions = null;
        $this->selectedUsers = [];
        $this->userRoles = [];
        $this->groupShares = [];
        $this->groupType = '';
        $this->groupValue = '';
        $this->groupOptions = [];
        $this->groupRole = FolderAccess::VIEWER;
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
        $this->authorizeFolderDeletion($folder);
        $media = Media::findOrFail($mediaId);
        $this->confirmItemDeletion('file', (int) $mediaId, $media->name);
    }

    public function confirmFolderDeletion($folderId)
    {
        $folder = Folder::findOrFail($folderId);
        $this->authorizeFolderDeletion($folder);
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
            $this->authorizeFolderDeletion($folder);
            Media::findOrFail($id)->delete();
        } elseif (($payload['type'] ?? null) === 'folder') {
            $folder = Folder::findOrFail($id);
            $this->authorizeFolderDeletion($folder);
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
            unset($this->userRoles[$userId]);
        } else {
            $selected[] = $userId;
            $this->userRoles[$userId] ??= FolderAccess::VIEWER;
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
            foreach ($toggleableUserIds as $id) {
                unset($this->userRoles[$id]);
            }
        } else {
            $this->selectedUsers = array_values(array_unique(array_merge($current, $toggleableUserIds)));
            foreach ($toggleableUserIds as $id) {
                $this->userRoles[$id] ??= FolderAccess::VIEWER;
            }
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

        // The people each group holds now, so the dialog can name them, and the groups that
        // reach each person (shown beside them in the list below).
        $groupMembers = [];
        $viaGroups = [];

        if ($this->showPermissionsModal) {
            foreach ($this->groupShares as $i => $share) {
                $members = $this->access()->members($share['type'], (int) $share['id'])->with('profile')->get()
                    ->map(fn (User $member) => ['id' => (int) $member->id, 'name' => $member->profile?->name ?? $member->email])
                    ->sortBy('name')->values();

                $groupMembers[$i] = $members->all();

                foreach ($members as $member) {
                    $viaGroups[$member['id']][] = $share['label'];
                }
            }
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
            'canDeleteInCurrentFolder' => $this->currentFolder !== null && $this->access()->canDelete($this->user, $this->currentFolder),
            // Each listed folder's role for this person: what its menu offers.
            'folderRoles' => $this->folders->mapWithKeys(fn (Folder $folder) => [$folder->id => $this->access()->role($this->user, $folder)])->all(),
            // Who each group in the dialog holds now (names), and which group reaches each person.
            'groupMembers' => $groupMembers,
            'viaGroups' => $viaGroups,
            'groupTypes' => $this->permissionFilterCategories,
            'previewFile' => $previewFile,
            'convertedOfficePdfUrl' => $this->convertedOfficePdfUrl($previewFile),
            'officePreviewData' => $this->officePreviewData($previewFile),
        ]);
    }

    /** A folder (or the root, when null) of the folders page, in the given mode. */
    public static function folderUrl(int|string|null $folderId = null, bool $shared = false): string
    {
        return route('folders.index', array_filter([
            'folderId' => $folderId,
            'scope' => $shared ? self::SHARED_SCOPE : null,
        ]));
    }

    protected function generateBreadcrumbs(): void
    {
        $crumbs = [['label' => __('general.folders'), 'url' => self::folderUrl(null, $this->isShared)]];
        $folderLinks = [];
        $folder = $this->currentFolder;

        // Up to the highest folder this person may open: a folder shared on its own
        // must not show the names of the folders above it.
        while ($folder && $this->access()->canView($this->user, $folder)) {
            array_unshift($folderLinks, [
                'label' => $folder->name,
                'url' => self::folderUrl($folder->id, $this->isShared),
            ]);
            $folder = $folder->parent;
        }

        $this->finalCrumbs = array_merge($crumbs, $folderLinks);

        if (count($this->finalCrumbs) > 1) {
            $lastIndex = count($this->finalCrumbs) - 1;
            unset($this->finalCrumbs[$lastIndex]['url']);
        }
    }

    /** The groups of one type, by id, with their names (departments, cities, mailing lists ...). */
    private function groupOptionsFor(string $type): array
    {
        $collection = match ($type) {
            'department' => Department::all(),
            'program' => Program::all(),
            'job_level' => JobLevel::all(),
            'center' => Center::all(),
            'city' => City::all(),
            'mail_list' => MailList::query()->orderBy('position')->orderBy('name')->get(),
            default => collect(),
        };

        return $collection
            ->mapWithKeys(fn ($item) => [$item->id => ($item->name ?? $item->name_en ?? $item->name_ar ?? (string) $item->id)])
            ->toArray();
    }

    /** The folder whose sharing is open, re-read and re-checked on every change to it. */
    private function authorizePermissionsFolder(): Folder
    {
        $folder = $this->folderForPermissions ? Folder::query()->find($this->folderForPermissions->id) : null;

        abort_unless($folder && $this->access()->canEdit($this->user, $folder), 403, 'Only the owner or an editor can share this folder.');

        return $folder;
    }

    private function shareRole(?string $role): string
    {
        return in_array($role, FolderAccess::SHARE_ROLES, true) ? $role : FolderAccess::VIEWER;
    }

    private function access(): FolderAccess
    {
        return app(FolderAccess::class);
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

        // Each filter is a group, read exactly as a group share reads it (FolderAccess::members):
        // the people it narrows to are the people sharing with that group would reach. Several
        // filters narrow together (in HR and in Aleppo).
        foreach ($this->activeFilters as $filter) {
            if (in_array($filter['type'], FolderAccess::GROUP_TYPES, true)) {
                $query->whereIn($query->qualifyColumn('id'), $this->access()->members($filter['type'], (int) $filter['value'])->select('users.id'));
            }
        }

        return $query;
    }

    private function userCanAccessFolder(Folder $folder): bool
    {
        return $this->access()->canView($this->user, $folder);
    }

    /**
     * Upload, make a subfolder, rename (FolderAccess: editor or owner). At the top
     * of My folders anyone may make a folder; at the top of "Shared with me" only
     * an administrator (everyone else makes one in My folders and shares it).
     */
    private function userCanManageFolder(?Folder $folder = null): bool
    {
        if (! $folder) {
            return ! $this->isShared || $this->user->isActiveAdministrator();
        }

        return $this->access()->canEdit($this->user, $folder);
    }

    private function authorizeCurrentFolderManagement(): void
    {
        if (! $this->userCanManageFolder($this->currentFolder)) {
            abort(403, 'Only the owner or an editor can change this folder.');
        }
    }

    private function authorizeFolderManagement(Folder $folder): void
    {
        if (! $this->userCanManageFolder($folder)) {
            abort(403, 'Only the owner or an editor can change this folder.');
        }
    }

    /** Deleting stays with the owner (and an administrator), as on Google Drive. */
    private function authorizeFolderDeletion(Folder $folder): void
    {
        if (! $this->access()->canDelete($this->user, $folder)) {
            abort(403, 'Only the owner can delete here.');
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

        $configured = config('uploads.office.soffice_path');

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
        // People who left cannot sign in; offering them only invites sharing by mistake.
        $query = User::query()->with('profile')->whereHas('profile', fn ($q) => $q->where('is_active', true));

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
