<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Groups: create, rename, members in and out, administrators and their
 * rights, leaving. Follows Telegram's rules:
 *
 *  - the owner holds every right and can never be removed or demoted;
 *  - an admin can only hand out rights they hold themself;
 *  - an admin can only change or remove admins they promoted (the owner can
 *    change anyone's);
 *  - an owner who leaves passes the group to the oldest admin, else the
 *    oldest member.
 *
 * A deactivated employee stays in their groups, and a group they own stays
 * theirs: they simply cannot sign in. Nobody inactive can be added.
 */
class ChatGroupService
{
    /** @param list<int> $memberIds user ids, besides the owner */
    public function create(User $owner, string $title, array $memberIds): ChatConversation
    {
        $title = $this->cleanTitle($title);
        $members = $this->activeUsers($memberIds)->reject(fn (User $u) => $u->is($owner));

        if ($members->count() + 1 > (int) config('chat.max_group_members', 500)) {
            throw ValidationException::withMessages(['members' => __('chat.errors.group_full', ['max' => config('chat.max_group_members')])]);
        }

        return DB::transaction(function () use ($owner, $title, $members) {
            $group = ChatConversation::query()->create([
                'type' => ChatConversation::TYPE_GROUP,
                'title' => $title,
                'owner_id' => $owner->id,
            ]);

            $group->participants()->create([
                'user_id' => $owner->id,
                'role' => ChatParticipant::ROLE_OWNER,
                'joined_at' => now(),
            ]);

            foreach ($members as $member) {
                $group->participants()->create([
                    'user_id' => $member->id,
                    'role' => ChatParticipant::ROLE_MEMBER,
                    'joined_at' => now(),
                ]);
            }

            $this->service($group, $owner, ChatMessage::EVENT_GROUP_CREATED, ['users' => $members->pluck('id')->all()]);

            return $group;
        });
    }

    public function rename(User $actor, ChatConversation $group, string $title): void
    {
        Gate::forUser($actor)->authorize('changeInfo', $group);

        $title = $this->cleanTitle($title);

        if ($title === $group->title) {
            return;
        }

        DB::transaction(function () use ($actor, $group, $title) {
            $group->forceFill(['title' => $title])->save();
            $this->service($group, $actor, ChatMessage::EVENT_TITLE_CHANGED);
        });
    }

    /**
     * Add people (again, if they left). Already-present and inactive people
     * are skipped.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, User> the people actually added
     */
    public function addMembers(User $actor, ChatConversation $group, array $userIds): Collection
    {
        Gate::forUser($actor)->authorize('addMembers', $group);

        $present = $group->activeParticipants()->pluck('user_id')->all();
        $adding = $this->activeUsers($userIds)->reject(fn (User $u) => in_array($u->id, $present, true))->values();

        if ($adding->isEmpty()) {
            return $adding;
        }

        if (count($present) + $adding->count() > (int) config('chat.max_group_members', 500)) {
            throw ValidationException::withMessages(['members' => __('chat.errors.group_full', ['max' => config('chat.max_group_members')])]);
        }

        DB::transaction(function () use ($actor, $group, $adding) {
            foreach ($adding as $user) {
                ChatParticipant::query()->updateOrCreate(
                    ['conversation_id' => $group->id, 'user_id' => $user->id],
                    [...$this->plainMember(), 'joined_at' => now(), 'left_at' => null],
                );
            }

            $this->service($group, $actor, ChatMessage::EVENT_MEMBERS_ADDED, ['users' => $adding->pluck('id')->all()]);
        });

        return $adding;
    }

    public function removeMember(User $actor, ChatConversation $group, User $target): void
    {
        Gate::forUser($actor)->authorize('removeMembers', $group);

        $member = $this->memberOrFail($group, $target);

        if ($member->isOwner() || $target->is($actor)) {
            throw ValidationException::withMessages(['member' => __('chat.errors.cannot_remove')]);
        }

        if ($member->isAdmin() && ! $this->canManageAdmin($actor, $group, $member)) {
            throw ValidationException::withMessages(['member' => __('chat.errors.not_your_admin')]);
        }

        DB::transaction(function () use ($actor, $group, $member, $target) {
            $member->forceFill([...$this->plainMember(), 'left_at' => now()])->save();
            $this->service($group, $actor, ChatMessage::EVENT_MEMBER_REMOVED, ['users' => [$target->id]]);
        });
    }

    /**
     * Make a member an admin, or change an admin's rights.
     *
     * @param  array<string, bool>  $rights  keys from ChatParticipant::RIGHTS
     */
    public function promote(User $actor, ChatConversation $group, User $target, array $rights): ChatParticipant
    {
        Gate::forUser($actor)->authorize('addAdmins', $group);

        $member = $this->memberOrFail($group, $target);
        $actorMember = $group->participantFor($actor);

        if ($member->isOwner() || $target->is($actor)) {
            throw ValidationException::withMessages(['member' => __('chat.errors.cannot_promote')]);
        }

        if ($member->isAdmin() && ! $this->canManageAdmin($actor, $group, $member)) {
            throw ValidationException::withMessages(['member' => __('chat.errors.not_your_admin')]);
        }

        $granted = [];

        foreach (ChatParticipant::RIGHTS as $right) {
            $wanted = (bool) ($rights[$right] ?? false);

            if ($wanted && ! $actorMember->hasRight($right)) {
                throw ValidationException::withMessages(['rights' => __('chat.errors.right_not_held')]);
            }

            $granted[$right] = $wanted;
        }

        $member->forceFill([
            ...$granted,
            'role' => ChatParticipant::ROLE_ADMIN,
            // Who promoted them decides who may change them later; an owner
            // editing someone else's admin leaves that as it was.
            'promoted_by' => $member->isAdmin() ? $member->promoted_by : $actor->id,
        ])->save();

        return $member;
    }

    public function demote(User $actor, ChatConversation $group, User $target): void
    {
        Gate::forUser($actor)->authorize('addAdmins', $group);

        $member = $this->memberOrFail($group, $target);

        if (! $member->isAdmin()) {
            return;
        }

        if (! $this->canManageAdmin($actor, $group, $member)) {
            throw ValidationException::withMessages(['member' => __('chat.errors.not_your_admin')]);
        }

        $member->forceFill($this->plainMember())->save();
    }

    /** Leave a group. An owner's group passes to the oldest admin, else the oldest member. */
    public function leave(User $user, ChatConversation $group): void
    {
        if (! $group->isGroup()) {
            throw ValidationException::withMessages(['conversation' => __('chat.errors.not_a_group')]);
        }

        $member = $this->memberOrFail($group, $user);

        DB::transaction(function () use ($user, $group, $member) {
            $wasOwner = $member->isOwner();
            $member->forceFill([...$this->plainMember(), 'left_at' => now()])->save();
            $this->service($group, $user, ChatMessage::EVENT_MEMBER_LEFT);

            if (! $wasOwner) {
                return;
            }

            $heir = $group->activeParticipants()
                ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [ChatParticipant::ROLE_ADMIN])
                ->orderBy('joined_at')
                ->orderBy('id')
                ->first();

            $group->forceFill(['owner_id' => $heir?->user_id])->save();

            if ($heir) {
                $heir->forceFill([...$this->plainMember(), 'role' => ChatParticipant::ROLE_OWNER, 'promoted_by' => null])->save();
                $this->service($group, null, ChatMessage::EVENT_OWNERSHIP_PASSED, ['users' => [$heir->user_id]]);
            }
        });
    }

    /** The owner can change any admin; another admin only those they promoted. */
    private function canManageAdmin(User $actor, ChatConversation $group, ChatParticipant $admin): bool
    {
        $actorMember = $group->participantFor($actor);

        return $actorMember?->isOwner() || $admin->promoted_by === $actor->id;
    }

    private function memberOrFail(ChatConversation $group, User $user): ChatParticipant
    {
        $member = $group->isGroup() ? $group->participantFor($user) : null;

        if (! $member) {
            throw ValidationException::withMessages(['member' => __('chat.errors.not_a_member')]);
        }

        return $member;
    }

    /** @return array<string, mixed> a plain member: no role, no rights */
    private function plainMember(): array
    {
        return [
            'role' => ChatParticipant::ROLE_MEMBER,
            'promoted_by' => null,
            ...array_fill_keys(ChatParticipant::RIGHTS, false),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function activeUsers(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->whereHas('profile', fn ($q) => $q->where('is_active', true))
            ->get();
    }

    private function cleanTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            throw ValidationException::withMessages(['title' => __('chat.errors.title_required')]);
        }

        if (mb_strlen($title) > (int) config('chat.max_group_title_length', 128)) {
            throw ValidationException::withMessages(['title' => __('chat.errors.title_too_long', ['max' => config('chat.max_group_title_length')])]);
        }

        return $title;
    }

    /** A service line in the chat ("Rana added George"), recorded as ids only. */
    private function service(ChatConversation $group, ?User $actor, string $event, array $meta = []): ChatMessage
    {
        $message = ChatMessage::query()->create([
            'conversation_id' => $group->id,
            'sender_id' => null,
            'type' => ChatMessage::TYPE_SERVICE,
            'meta' => ['event' => $event, 'actor' => $actor?->id, ...$meta],
        ]);

        $group->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();

        return $message;
    }
}
