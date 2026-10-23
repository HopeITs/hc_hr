<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's personal saved table view (B4-D section 5.1): table STATE only,
 * visible to its owner and only under the role context it was saved in.
 */
class SavedTableView extends Model
{
    public const LIMIT = 10;

    protected $fillable = ['user_id', 'table', 'role_context', 'name', 'state'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['state' => 'array'];
    }
}
