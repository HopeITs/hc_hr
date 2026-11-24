<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A folder shared with a group, kept as the group (App\Services\Folders\FolderAccess):
 * whoever is in it now has access, so joining the group gives it and leaving takes it.
 */
class FolderGroupShare extends Model
{
    protected $fillable = ['folder_id', 'group_type', 'group_id', 'role', 'shared_by_user_id'];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    protected function casts(): array
    {
        return ['group_id' => 'integer'];
    }
}
