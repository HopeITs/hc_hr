<?php

namespace Tests\Feature\Chat;

use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChatGroupsTest extends ChatTestCase
{
    public function test_creating_a_group_makes_the_creator_owner_and_skips_inactive_people(): void
    {
        $owner = $this->person();
        $member = $this->person();
        $inactive = $this->person();
        $this->deactivate($inactive);

        $group = $this->groupService()->create($owner, 'Contracts team', [$member->id, $inactive->id, $owner->id]);

        $this->assertSame($owner->id, $group->owner_id);
        $this->assertSame([$owner->id, $member->id], $group->activeParticipants()->orderBy('id')->pluck('user_id')->all());
        $this->assertTrue($group->participantFor($owner)->isOwner());
        $this->assertSame(ChatMessage::EVENT_GROUP_CREATED, $group->messages()->first()->meta['event']);
    }

    public function test_a_plain_member_has_no_admin_rights(): void
    {
        $owner = $this->person();
        $member = $this->person();
        $group = $this->groupService()->create($owner, 'Team', [$member->id]);

        $this->expectException(AuthorizationException::class);
        $this->groupService()->addMembers($member, $group, [$this->person()->id]);
    }

    public function test_an_admin_uses_only_the_rights_given(): void
    {
        $owner = $this->person();
        $admin = $this->person();
        $group = $this->groupService()->create($owner, 'Team', [$admin->id]);

        $this->groupService()->promote($owner, $group, $admin, ['can_add_members' => true]);

        $added = $this->groupService()->addMembers($admin, $group, [$newcomer = $this->person()->id]);
        $this->assertSame([$newcomer], $added->pluck('id')->all());

        $this->assertFalse(Gate::forUser($admin)->allows('removeMembers', $group));
        $this->assertFalse(Gate::forUser($admin)->allows('changeInfo', $group));
    }

    public function test_an_admin_cannot_hand_out_a_right_they_do_not_hold(): void
    {
        $owner = $this->person();
        $admin = $this->person();
        $member = $this->person();
        $group = $this->groupService()->create($owner, 'Team', [$admin->id, $member->id]);
        $this->groupService()->promote($owner, $group, $admin, ['can_add_admins' => true, 'can_add_members' => true]);

        $this->groupService()->promote($admin, $group, $member, ['can_add_members' => true]);
        $this->assertTrue($group->participantFor($member)->isAdmin());

        $this->expectException(ValidationException::class);
        $this->groupService()->promote($admin, $group, $member, ['can_remove_members' => true]);
    }

    public function test_an_admin_cannot_change_an_admin_someone_else_promoted_but_the_owner_can(): void
    {
        $owner = $this->person();
        $adminA = $this->person();
        $adminB = $this->person();
        $group = $this->groupService()->create($owner, 'Team', [$adminA->id, $adminB->id]);
        $all = array_fill_keys(ChatParticipant::RIGHTS, true);
        $this->groupService()->promote($owner, $group, $adminA, $all);
        $this->groupService()->promote($owner, $group, $adminB, $all);

        try {
            $this->groupService()->removeMember($adminA, $group, $adminB);
            $this->fail('An admin removed an admin they did not promote.');
        } catch (ValidationException) {
        }

        try {
            $this->groupService()->demote($adminA, $group, $adminB);
            $this->fail('An admin demoted an admin they did not promote.');
        } catch (ValidationException) {
        }

        $this->groupService()->demote($owner, $group, $adminB);
        $this->assertFalse($group->participantFor($adminB)->isAdmin());
    }

    public function test_the_owner_can_never_be_removed_or_demoted(): void
    {
        $owner = $this->person();
        $admin = $this->person();
        $group = $this->groupService()->create($owner, 'Team', [$admin->id]);
        $this->groupService()->promote($owner, $group, $admin, array_fill_keys(ChatParticipant::RIGHTS, true));

        $this->expectException(ValidationException::class);
        $this->groupService()->removeMember($admin, $group, $owner);
    }

    public function test_an_owner_who_leaves_passes_the_group_to_the_oldest_admin_else_the_oldest_member(): void
    {
        $owner = $this->person();
        $first = $this->person();
        $second = $this->person();
        $group = $this->groupService()->create($owner, 'Team', [$first->id, $second->id]);
        $this->groupService()->promote($owner, $group, $second, ['can_add_members' => true]);

        $this->groupService()->leave($owner, $group);

        $this->assertSame($second->id, $group->fresh()->owner_id);
        $this->assertTrue($group->fresh()->participantFor($second)->isOwner());

        // No admins left: the oldest member takes over.
        $this->groupService()->leave($second, $group->fresh());
        $this->assertSame($first->id, $group->fresh()->owner_id);
    }

    public function test_a_deactivated_employee_stays_in_the_group_and_keeps_ownership(): void
    {
        $owner = $this->person();
        $member = $this->person();
        $group = $this->groupService()->create($owner, 'Team', [$member->id]);

        $this->deactivate($owner);
        $this->deactivate($member);

        $group->refresh();
        $this->assertSame($owner->id, $group->owner_id);
        $this->assertTrue($group->participantFor($owner)->isOwner());
        $this->assertNotNull($group->participantFor($member));
    }

    public function test_renaming_needs_the_right_and_is_announced(): void
    {
        $owner = $this->person();
        $member = $this->person();
        $group = $this->groupService()->create($owner, 'Old name', [$member->id]);

        $this->groupService()->rename($owner, $group, 'New name');
        $this->assertSame('New name', $group->fresh()->title);
        $this->assertSame(ChatMessage::EVENT_TITLE_CHANGED, $group->messages()->latest('id')->first()->meta['event']);

        $this->expectException(AuthorizationException::class);
        $this->groupService()->rename($member, $group, 'Mine now');
    }

    public function test_a_group_admin_with_the_right_can_delete_anyones_message(): void
    {
        $owner = $this->person();
        $admin = $this->person();
        $member = $this->person();
        $group = $this->groupService()->create($owner, 'Team', [$admin->id, $member->id]);
        $this->groupService()->promote($owner, $group, $admin, ['can_delete_messages' => true]);
        $message = $this->chat()->send($member, $group, 'spam');

        $this->chat()->deleteForEveryone($admin, $message);

        $this->assertNull(ChatMessage::query()->find($message->id));
    }
}
