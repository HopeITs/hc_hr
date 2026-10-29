<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's dashboard arrangement for one role (HasDashboardLayout).
 * The layout is whatever the browser last sent, already cleaned; it is
 * cleaned again on every read, so it never grants a tile on its own.
 */
class SavedDashboardLayout extends Model
{
    protected $table = 'dashboard_layouts';

    protected $fillable = ['user_id', 'role', 'layout'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['layout' => 'array'];
    }
}
