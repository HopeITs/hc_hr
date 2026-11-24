<?php

namespace App\Support\Search\Providers;

use App\Livewire\Archives\ArchivedFilesModule;
use App\Models\Folder;
use App\Models\User;
use App\Support\Search\NameMatch;
use App\Support\Search\SearchProvider;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;

/**
 * Folders by name: the ones the person made or was given (the archive's own rule), every folder
 * for an administrator. Files inside are not searched; a result opens the folder.
 */
final class FoldersProvider implements SearchProvider
{
    public function allows(User $user): bool
    {
        return $user->hasActivePermission('archives.view');
    }

    public function search(User $user, string $term, int $limit): array
    {
        $query = Folder::query()->with('parent:id,name')->where('name', 'like', NameMatch::like($term));

        // Own folders and those shared by name or through a group (FolderAccess::sharedWith).
        // Subfolders reached only by inheritance are found by opening their parent.
        if (! $user->isActiveAdministrator()) {
            $shared = app(\App\Services\Folders\FolderAccess::class)->sharedWith($user)->select('folders.id');
            $query->where(fn (Builder $mine) => $mine
                ->where('user_id', $user->id)
                ->orWhereIn('id', $shared));
        }

        return $query->orderBy('name')->limit($limit)->get()
            ->map(fn (Folder $folder): array => SearchResult::make(
                'folders',
                $folder->id,
                (string) $folder->name,
                SearchResult::line($folder->parent?->name, (int) $folder->user_id !== (int) $user->id ? __('smart_search.shared-with-me') : null),
                ArchivedFilesModule::folderUrl($folder->id, (int) $folder->user_id !== (int) $user->id),
            ))
            ->all();
    }
}
