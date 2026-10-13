<?php

namespace Tests\Feature\Security;

use App\Livewire\Dashboard\EmployeeNotesCard;
use App\Livewire\Position\ProfileDrawer;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Employee;
use App\Models\EmployeeNote;
use App\Models\User;
use App\Services\ChecklistWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * One test per defect that was actually exploitable, written from the
 * attacker's side: tamper with the payload the browser controls and assert the
 * server refuses. A test that only drives the happy path would have passed
 * against the vulnerable code in every one of these cases.
 */
class AuthorizationRegressionTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    // --- Employee notes IDOR (report item 5) -----------------------------

    public function test_notes_are_read_from_the_session_not_from_the_payload(): void
    {
        $city = $this->makeCity('ALP', '001');
        $mine = $this->makeEmployee($city, 'note-owner');
        $theirs = $this->makeEmployee($city, 'note-victim');

        $user = User::factory()->create(['employee_id' => $mine->id]);

        EmployeeNote::create([
            'employee_id' => $theirs->id,
            'type' => 'note',
            'content' => 'Confidential note belonging to someone else',
            'is_done' => false,
            'order' => 1,
            'color' => '#111827',
        ]);

        $component = Livewire::actingAs($user)->test(EmployeeNotesCard::class);

        // Layer 1: #[Locked] rejects the tampered snapshot outright.
        try {
            $component->set('employeeId', $theirs->id);
            $this->fail('Expected the locked property to reject the update.');
        } catch (CannotUpdateLockedPropertyException) {
            // expected
        }

        // Layer 2, the one that actually matters: force the property past
        // #[Locked] and confirm the query still resolves the owner from the
        // session, so the other employee's note never appears.
        $component->instance()->employeeId = $theirs->id;

        $this->assertCount(0, $component->instance()->notes);
    }

    public function test_a_tampered_employee_id_cannot_redirect_a_new_note(): void
    {
        $city = $this->makeCity('ALP', '001');
        $mine = $this->makeEmployee($city, 'note-owner');
        $theirs = $this->makeEmployee($city, 'note-victim');
        $user = User::factory()->create(['employee_id' => $mine->id]);

        $component = Livewire::actingAs($user)->test(EmployeeNotesCard::class);

        // Forced past #[Locked] deliberately: the ownership decision must not
        // depend on that attribute being present.
        $component->instance()->employeeId = $theirs->id;

        $component->set('newText', 'planted')->call('addText');

        $this->assertDatabaseHas('employee_notes', [
            'employee_id' => $mine->id,
            'content' => 'planted',
        ]);
        $this->assertDatabaseMissing('employee_notes', ['employee_id' => $theirs->id]);
    }

    public function test_another_employees_note_cannot_be_deleted_by_id(): void
    {
        $city = $this->makeCity('ALP', '001');
        $mine = $this->makeEmployee($city, 'note-owner');
        $theirs = $this->makeEmployee($city, 'note-victim');
        $user = User::factory()->create(['employee_id' => $mine->id]);

        $victimNote = EmployeeNote::create([
            'employee_id' => $theirs->id,
            'type' => 'note',
            'content' => 'not yours',
            'is_done' => false,
            'order' => 1,
            'color' => '#111827',
        ]);

        // findOrFail runs on the owner-scoped query, so a foreign id is simply
        // not found — Laravel renders this as a 404.
        Livewire::actingAs($user)
            ->test(EmployeeNotesCard::class)
            ->call('delete', $victimNote->id)
            ->assertNotFound();

        $this->assertDatabaseHas('employee_notes', ['id' => $victimNote->id]);
    }

    // --- Profile drawer (report item 7) ----------------------------------

    public function test_the_profile_drawer_refuses_an_employee_from_another_city(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $homs = $this->makeCity('HMS', '002');

        $hr = $this->makeUser($aleppo, 'HR-ALP', ['employees.view']);
        $outsider = $this->makeEmployee($homs, 'homs-person');

        // Dispatching the event directly is exactly what an attacker does; the
        // org chart never renders a button for this employee.
        Livewire::actingAs($hr)
            ->test(ProfileDrawer::class)
            ->call('openProfile', $outsider->id)
            ->assertStatus(403);
    }

    public function test_the_profile_drawer_opens_for_an_employee_in_the_same_city(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['employees.view']);
        $colleague = $this->makeEmployee($aleppo, 'aleppo-person');

        Livewire::actingAs($hr)
            ->test(ProfileDrawer::class)
            ->call('openProfile', $colleague->id)
            ->assertSet('employeeId', $colleague->id);
    }

    public function test_delivery_cannot_be_confirmed_for_someone_elses_assignment(): void
    {
        $city = $this->makeCity('ALP', '001');
        $assignee = $this->makeEmployee($city, 'assignee');
        $attacker = $this->makeEmployee($city, 'attacker');
        $holder = $this->makeEmployee($city, 'holder');

        $checklist = $this->checklist([
            'employee_id' => $holder->id,
            'delivered_id' => $assignee->id,
        ]);

        $this->expectException(NotFoundHttpException::class);

        app(ChecklistWorkflow::class)->markDelivered(
            checklistId: $checklist->id,
            actorEmployeeId: $attacker->id,
            notes: null,
        );
    }

    public function test_delivery_cannot_run_twice(): void
    {
        $city = $this->makeCity('ALP', '001');
        $assignee = $this->makeEmployee($city, 'assignee');
        $holder = $this->makeEmployee($city, 'holder');

        $checklist = $this->checklist([
            'employee_id' => $holder->id,
            'delivered_id' => $assignee->id,
        ]);

        $workflow = app(ChecklistWorkflow::class);
        $workflow->markDelivered($checklist->id, $assignee->id, null);

        // The expected source status is part of the query, so the second
        // submission matches nothing rather than re-stamping delivered_at.
        $this->expectException(NotFoundHttpException::class);
        $workflow->markDelivered($checklist->id, $assignee->id, null);
    }

    public function test_a_return_cannot_be_received_before_it_was_initiated(): void
    {
        $city = $this->makeCity('ALP', '001');
        $receiver = $this->makeEmployee($city, 'receiver');
        $holder = $this->makeEmployee($city, 'holder');

        $checklist = $this->checklist([
            'employee_id' => $holder->id,
            'delivered_id' => $holder->id,
            'receiver_id' => $receiver->id,
            'returned_status' => null,
        ]);

        $this->expectException(NotFoundHttpException::class);

        app(ChecklistWorkflow::class)->markReceived($checklist->id, $receiver->id, null);
    }

    public function test_hr_cannot_finalize_a_checklist_belonging_to_another_employee(): void
    {
        $city = $this->makeCity('ALP', '001');
        $holder = $this->makeEmployee($city, 'holder');
        $other = $this->makeEmployee($city, 'other');

        $checklist = $this->checklist([
            'employee_id' => $holder->id,
            'delivered_id' => $holder->id,
            'returned_status' => 'received',
        ]);

        $this->expectException(NotFoundHttpException::class);

        app(ChecklistWorkflow::class)->finalizeReturn($checklist->id, $other);
    }

    // --- Deactivation ends live sessions (report item 10) ----------------

    public function test_a_signed_in_user_loses_access_the_moment_they_are_deactivated(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'about-to-leave');
        $user = User::factory()->create(['employee_id' => $employee->id]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $employee->update(['is_active' => false]);

        // Same session, next request.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deactivation_clears_the_remember_token_and_stands_roles_down(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'about-to-leave');
        $user = $this->makeUser($city, 'HR-ALP', ['employees.view'], $employee);
        $user->forceFill(['remember_token' => 'still-valid'])->save();

        $employee->update(['is_active' => false]);

        $user->refresh()->unsetRelation('roles');

        $this->assertNull($user->remember_token);
        $this->assertNull($user->activeRole());
        $this->assertFalse($user->hasActivePermission('employees.view'));
    }

    public function test_deactivation_is_recorded_in_the_security_log(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'about-to-leave');
        User::factory()->create(['employee_id' => $employee->id]);

        $employee->update(['is_active' => false]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'description' => 'access_revoked',
        ]);
    }

    // --- Inactive privileged role (report item 11) -----------------------

    public function test_an_inactive_super_admin_role_grants_nothing(): void
    {
        $aleppo = $this->makeCity('ALP', '001');

        $user = $this->makeUser($aleppo, 'HR-ALP', ['employees.view']);
        $user = $this->assignInactiveRole($user, 'super-admin');

        // Historically assigned, not currently active.
        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertFalse($user->isActiveAdministrator());
        $this->assertSame([$aleppo->id], $user->activeCityIds());
    }

    public function test_an_active_super_admin_role_is_recognised(): void
    {
        $city = $this->makeCity('SYR', '000');
        $user = $this->makeUser($city, 'super-admin');

        $this->assertTrue($user->isActiveAdministrator());
        $this->assertNull($user->activeCityIds());
    }

    // --- Sensitive values stay out of the activity log (item 14) ---------

    public function test_salary_and_identifiers_are_not_written_to_the_activity_log(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'logged-person', [
            'national_number' => '01020304050',
        ]);

        $employee->update([
            'national_number' => '09080706050',
            'personal_phone' => '+963900000000',
        ]);

        $properties = DB::table('activity_log')
            ->where('subject_type', Employee::class)
            ->pluck('properties')
            ->implode(' ');

        $this->assertStringNotContainsString('09080706050', $properties);
        $this->assertStringNotContainsString('+963900000000', $properties);

        // The audit trail still records which fields changed.
        $this->assertStringContainsString('sensitive_fields_changed', $properties);
        $this->assertStringContainsString('national_number', $properties);
    }

    // --- Checklist transitions (report item 6) ---------------------------

    private function checklist(array $attributes): Checklist
    {
        $item = ChecklistItem::query()->create(['name' => 'Laptop '.uniqid(), 'order' => 1]);

        return Checklist::create(array_merge([
            'item_id' => $item->id,
            'delivered_status' => 'pending',
        ], $attributes));
    }
}
