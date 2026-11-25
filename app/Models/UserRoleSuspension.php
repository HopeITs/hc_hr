<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A raised role stood down while its holder has no running contract (OrgRoleSync). */
class UserRoleSuspension extends Model
{
    public const REASON_NO_CONTRACT = 'no_running_contract';

    protected $fillable = ['user_id', 'role_id', 'reason', 'suspended_at'];

    protected $casts = ['suspended_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
