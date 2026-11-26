<?php

namespace App\Services\Policies;

use App\Models\Folder;
use App\Models\User;

/**
 * The one folder of the Folders module that holds the policies.
 *
 * Every signed-in employee reads it (and what is inside, at any depth); an HR
 * role or super-admin holding employees.update manages it as its owner:
 * upload, replace, rename, delete. Its own sharing is not used. The folder
 * itself is never deleted.
 *
 * Each PDF, Word or text file in it is a policy (PolicySync).
 */
class PolicyFolder
{
    /** File types read into a policy; any other file in the folder stays a plain file. */
    public const READABLE = ['pdf', 'docx', 'txt'];

    /** The folder, made on first use. */
    public function library(): Folder
    {
        return Folder::query()->where('is_policy_library', true)->whereNull('parent_id')->first()
            ?? Folder::query()->forceCreate([
                'name' => __('policies.folder-name', [], config('app.locale')),
                'parent_id' => null,
                'user_id' => null,
                'is_shared' => true,
                'inherits_access' => true,
                'is_policy_library' => true,
            ]);
    }

    /** The folder or one inside it. */
    public function contains(?Folder $folder): bool
    {
        $seen = [];

        for ($current = $folder; $current !== null && ! isset($seen[$current->id]); $current = $current->parent) {
            $seen[$current->id] = true;

            if ($current->is_policy_library) {
                return true;
            }
        }

        return false;
    }

    public function isLibrary(?Folder $folder): bool
    {
        return (bool) $folder?->is_policy_library && $folder->parent_id === null;
    }

    /** Upload, replace, rename and delete policies; choose which are required. */
    public static function canManage(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $role = (string) $user->myRole();

        return ($role === 'super-admin' || str_starts_with($role, 'HR-'))
            && $user->hasActivePermission('employees.update');
    }

    public static function isReadable(?string $extension): bool
    {
        return in_array(strtolower((string) $extension), self::READABLE, true);
    }
}
