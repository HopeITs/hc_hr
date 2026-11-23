<?php

namespace App\Services\Chat;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A person's own chat settings, saved to their account so they follow them
 * across devices. No row means the defaults (sound on).
 */
class ChatSettings
{
    private const TABLE = 'messenger_settings';

    public function sound(User $user): bool
    {
        $value = DB::table(self::TABLE)->where('user_id', $user->id)->value('sound');

        return $value === null ? true : (bool) $value;
    }

    public function setSound(User $user, bool $on): void
    {
        DB::table(self::TABLE)->updateOrInsert(
            ['user_id' => $user->id],
            ['sound' => $on, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
