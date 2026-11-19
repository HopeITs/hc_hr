<?php

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class ChatPrivacyTest extends ChatTestCase
{
    public function test_only_the_two_people_can_see_a_private_chat(): void
    {
        [$chat, $a, $b] = $this->privateChat();
        $outsider = $this->person();

        $this->assertTrue(Gate::forUser($a)->allows('view', $chat));
        $this->assertTrue(Gate::forUser($b)->allows('view', $chat));
        $this->assertFalse(Gate::forUser($outsider)->allows('view', $chat));
    }

    public function test_no_role_reads_a_chat_it_is_not_in_not_even_super_admin_or_hr(): void
    {
        [$chat] = $this->privateChat();

        foreach (['super-admin', 'HR-SYR', 'general-manager'] as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $boss = $this->person();
            $boss->assignRole($role);

            $this->assertFalse(Gate::forUser($boss)->allows('view', $chat), $roleName.' must not read the chat');
            $this->assertCount(0, app(\App\Services\Chat\ChatSearchIndex::class)->search($boss, 'anything'));
        }
    }

    public function test_an_outsider_cannot_send_into_a_chat(): void
    {
        [$chat] = $this->privateChat();

        $this->expectException(AuthorizationException::class);
        $this->chat()->send($this->person(), $chat, 'hello');
    }

    public function test_the_same_two_people_always_get_the_same_private_chat(): void
    {
        $a = $this->person();
        $b = $this->person();

        $first = $this->chat()->openPrivate($a, $b);
        $again = $this->chat()->openPrivate($b, $a);

        $this->assertTrue($first->is($again));
        $this->assertSame(1, ChatConversation::query()->where('type', 'private')->count());
    }

    public function test_nobody_can_start_or_write_a_private_chat_with_a_deactivated_person(): void
    {
        [$chat, $a, $b] = $this->privateChat();
        $this->deactivate($b);

        $this->assertFalse(Gate::forUser($a)->allows('send', $chat));
        // The history stays readable for the active side.
        $this->assertTrue(Gate::forUser($a)->allows('view', $chat));

        $this->expectException(ValidationException::class);
        $this->chat()->openPrivate($a, $this->tap($this->person(), fn ($p) => $this->deactivate($p)));
    }

    public function test_a_removed_group_member_loses_the_group(): void
    {
        $owner = $this->person();
        $member = $this->person();
        $group = $this->groupService()->create($owner, 'Team', [$member->id]);

        $this->assertTrue(Gate::forUser($member)->allows('view', $group));

        $this->groupService()->removeMember($owner, $group, $member);

        $this->assertFalse(Gate::forUser($member)->allows('view', $group->fresh()));
    }

    private function tap($value, callable $callback)
    {
        $callback($value);

        return $value;
    }
}
