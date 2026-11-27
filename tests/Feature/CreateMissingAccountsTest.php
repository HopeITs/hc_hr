<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\RecruitmentHiringConversion;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeActivation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * T9 (scenario run 2026-11-26): a new account's role is its active one, and
 * HR can give an account, once and by hand, to active employees who never got
 * one (HR decision 2026-11-26; B5D-Q8 still rules out a scheduled sweep).
 */
class CreateMissingAccountsTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Role::findOrCreate('employee', 'web');
        $this->city = $this->makeCity('ALP', '001');
    }

    public function test_an_account_made_at_activation_has_its_employee_role_active(): void
    {
        $employee = $this->makeEmployee($this->city, 't9.new', ['is_active' => false]);
        $contract = $this->makeContractFor($employee, ['start_date' => now()->subDay(), 'end_date' => now()->addYear()]);

        $this->assertSame(EmployeeActivation::RESULT_ACCOUNT_CREATED, app(EmployeeActivation::class)->activateForContract($contract));

        $user = User::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('employee', $user->myRole());
        $this->assertTrue($user->roles()->wherePivot('is_active', true)->exists());
    }

    public function test_the_command_lists_by_default_and_writes_nothing(): void
    {
        $employee = $this->makeEmployee($this->city, 't9.listed');
        $this->makeContractFor($employee, ['start_date' => now()->subDay(), 'end_date' => now()->addYear()]);

        $this->artisan('employees:create-missing-accounts')
            ->expectsOutputToContain('t9.listed@hcsyria.org')
            ->assertSuccessful();

        $this->assertFalse(User::query()->where('employee_id', $employee->id)->exists());
        Notification::assertNothingSent();
    }

    public function test_apply_creates_accounts_only_for_a_username_and_a_running_contract(): void
    {
        $ready = $this->makeEmployee($this->city, 't9.ready');
        $this->makeContractFor($ready, ['start_date' => now()->subDay(), 'end_date' => now()->addYear()]);
        $noContract = $this->makeEmployee($this->city, 't9.nocontract');
        $noUsername = $this->makeEmployee($this->city, 't9.tmp');
        // NULL on hr_db (the June import); the test schema says NOT NULL.
        $noUsername->forceFill(['username' => ''])->saveQuietly();
        $this->makeContractFor($noUsername, ['start_date' => now()->subDay(), 'end_date' => now()->addYear()]);

        $this->artisan('employees:create-missing-accounts', ['--apply' => true])->assertSuccessful();

        $account = User::query()->where('employee_id', $ready->id)->firstOrFail();
        $this->assertSame('t9.ready@hcsyria.org', $account->email);
        $this->assertTrue($account->roles()->wherePivot('is_active', true)->exists());
        Notification::assertSentTo($account, ResetPassword::class);

        $this->assertFalse(User::query()->where('employee_id', $noContract->id)->exists());
        $this->assertFalse(User::query()->where('employee_id', $noUsername->id)->exists());
    }

    /**
     * T13c (decision 2026-11-27): HR creates a recruitment hire's account by
     * hand, so --apply leaves them out. A conversion that never completed does
     * not count as a hire.
     */
    public function test_apply_leaves_recruitment_hires_to_hr(): void
    {
        $hired = $this->makeEmployee($this->city, 't13c.hired');
        $hiredContract = $this->makeContractFor($hired, ['start_date' => now()->subDay(), 'end_date' => now()->addYear()]);
        $this->recordConversion($hired->id, $hiredContract->id, RecruitmentHiringConversion::STATUS_CONVERTED, 1);

        $failed = $this->makeEmployee($this->city, 't13c.failed');
        $this->makeContractFor($failed, ['start_date' => now()->subDay(), 'end_date' => now()->addYear()]);
        $this->recordConversion($failed->id, null, RecruitmentHiringConversion::STATUS_FAILED, 2);

        $this->artisan('employees:create-missing-accounts')
            ->expectsOutputToContain('t13c.hired: skipped, hired through recruitment')
            ->assertSuccessful();

        $this->artisan('employees:create-missing-accounts', ['--apply' => true])->assertSuccessful();

        $this->assertFalse(User::query()->where('employee_id', $hired->id)->exists());
        $this->assertTrue(User::query()->where('employee_id', $failed->id)->exists());
    }

    private function recordConversion(int $employeeId, ?int $contractId, string $status, int $offerId): void
    {
        // Offers, applicants and their versions are beside the point here.
        // The test runs inside a transaction that is rolled back, so deferred
        // foreign keys are never checked (foreign_keys itself cannot be
        // switched off inside a transaction on SQLite).
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('recruitment_hiring_conversions')->insert([
            'job_offer_id' => $offerId,
            'job_offer_version_id' => $offerId,
            'applicant_id' => $offerId,
            'employee_id' => $employeeId,
            'contract_id' => $contractId,
            'employee_payload_json' => '{}',
            'status' => $status,
            'started_at' => now(),
            'converted_by_user_id' => 1,
            'converted_at' => $status === RecruitmentHiringConversion::STATUS_CONVERTED ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
