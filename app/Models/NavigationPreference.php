<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's favourites, recents and phone bar (NavigationMemory). The
 * stored lists are only ever read through the active role's menu and the
 * directory scope, so a row never opens a page or a profile on its own.
 */
class NavigationPreference extends Model
{
    protected $fillable = ['user_id', 'favourites', 'recent_pages', 'recent_people', 'bottom_bar'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'favourites' => 'array',
            'recent_pages' => 'array',
            'recent_people' => 'array',
            'bottom_bar' => 'array',
        ];
    }
}
