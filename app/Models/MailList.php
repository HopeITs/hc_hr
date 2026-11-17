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
    /** The list every member of staff belongs to. */
    public const ALL_STAFF_ADDRESS = 'team@hcsyria.org';

    /** The domain of the organisation's own mailboxes. */
    public const DOMAIN = 'hcsyria.org';

    /** The groups the page offers, in order; 'other' catches anything new. */
    public const CATEGORIES = ['organisation', 'functions', 'cities', 'projects'];

    protected $fillable = ['name', 'address', 'position'];

    /** Which group a list belongs to, from the name of its address. */
    public static function categoryOf(string $address): string
    {
        $name = strtolower(strstr($address, '@', true) ?: $address);

        return match (true) {
            in_array($name, ['team', 'managers', 'board', 'headoffice'], true) => 'organisation',
            in_array($name, ['pr', 'meal', 'financial', 'logistics', 'ct', 'design', 'it'], true) => 'functions',
            in_array($name, ['homs'], true), str_starts_with($name, 'hope.') => 'cities',
            str_starts_with($name, 'micro.'), str_starts_with($name, 'studyzone.'),
            in_array($name, ['disc.syria', 'relief.aleppo', 'leap.students'], true) => 'projects',
            default => 'other',
        };
    }

    public function members(): HasMany
    {
        return $this->hasMany(MailListMember::class);
    }

    /** The accounts of the members that have one, to share a folder with. */
    public function users()
    {
        return User::query()->whereIn('email', $this->members()->select('email'));
    }

    /**
     * A new account on the organisation's domain joins the all-staff list as
     * pending: it is in the group here at once, and waits for the control
     * panel's holder to add it there. Does nothing when the list is not set
     * up, the address is not the organisation's, or they are already on it.
     */
    public static function queueNewAccount(User $user): void
    {
        $email = strtolower((string) $user->email);

        if (! str_ends_with($email, '@'.self::DOMAIN)) {
            return;
        }

        $list = static::query()->where('address', self::ALL_STAFF_ADDRESS)->first();

        $list?->members()->firstOrCreate(
            ['email' => $email],
            ['status' => MailListMember::PENDING, 'added_by_user_id' => auth()->id()],
        );
    }
}
