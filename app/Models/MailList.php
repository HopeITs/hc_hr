<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mailing list of the organisation (managers@, hope.aleppo@ …): its own
 * address and the work emails of its members. The app only reads it to email
 * a list or share a folder with its members; the real distribution is the
 * mail provider's.
 */
class MailList extends Model
{
    protected $fillable = ['name', 'address', 'position'];

    public function members(): HasMany
    {
        return $this->hasMany(MailListMember::class);
    }

    /** The accounts of the members that have one, to share a folder with. */
    public function users()
    {
        return User::query()->whereIn('email', $this->members()->select('email'));
    }
}
