<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Employee;
use App\Models\User;
use App\Services\DigitalIdManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesDigitalIdCards;
use Tests\TestCase;

class SecurityAuthorizationTest extends TestCase
{
    use CreatesDigitalIdCards, RefreshDatabase;

    public function test_city_scoped_hr_cannot_update_an_employee_in_another_city(): void
    {
        $aleppo = $this->city('ALP', '001');
        $homs = $this->city('HMS', '002');
        $user = $this->userInCity($aleppo);
        $permission = Permission::findOrCreate('employees.update', 'web');
        $role = Role::findOrCreate('HR-ALP', 'web');
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $user->roles()->updateExistingPivot($role->id, ['is_active' => true]);
        $user->unsetRelation('roles');

        $localEmployee = $this->employee($aleppo, 'local');
        $remoteEmployee = $this->employee($homs, 'remote');

        $this->actingAs($user);

        $this->assertTrue(Gate::allows('update', $localEmployee));
        $this->assertFalse(Gate::allows('update', $remoteEmployee));
    }

    public function test_private_employee_documents_require_owner_or_scoped_hr_access(): void
    {
        Storage::fake('local');
        $city = $this->city('TST', '999');
        $employee = $this->employee($city, 'document-owner');
        $owner = User::factory()->create(['employee_id' => $employee->id]);
        $media = $employee
            ->addMedia(UploadedFile::fake()->create('identity.pdf', 10, 'application/pdf'))
            ->toMediaCollection('attachments', 'local');

        $response = $this->actingAs($owner)
            ->get(route('employee-files.download', $media));

        $response->assertOk();
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        // D1 (profile-contract.md section 7): refused like an unknown file, so
        // the id does not confirm that a private document exists.
        $outsider = User::factory()->create();
        $this->actingAs($outsider)
            ->get(route('employee-files.download', $media))
            ->assertNotFound();
    }

    /**
     * access-policy.md §7 #2 as revised by §9 (DEC-02 option A, A2D-Q6): the
     * link is the readable /ID/[city][program][employee id]-[index], with no
     * secret part. HR accepted in writing that it can be guessed, so this no
     * longer asserts unguessability. It asserts that the signed format resolves
     * for an active employee's enabled card, and that a disabled card, an
     * inactive employee and an old username-style link all get the not-valid
     * page instead.
     */
    public function test_digital_id_link_resolves_only_for_an_active_enabled_card(): void
    {
        $city = $this->city('DIG', '998');
        $employee = $this->employee($city, 'digital-id');
        $this->giveCurrentPosition($employee, '007');

        $link = '/ID/998007'.$employee->id.'-0';
        $this->assertSame(url($link), $employee->fresh()->digitalIdUrl());
        $this->get($link)->assertOk()->assertSee('Security');

        $this->get('/ID/'.$employee->username)
            ->assertNotFound()
            ->assertSee('This card is not valid')
            ->assertDontSee('Security');

        app(DigitalIdManager::class)->disable($employee->fresh());
        $this->get($link)->assertNotFound()->assertSee('This card is not valid')->assertDontSee('Security');

        app(DigitalIdManager::class)->enable($employee->fresh());
        $this->get($link)->assertOk();

        $employee->fresh()->update(['is_active' => false]);
        $this->get($link)->assertNotFound()->assertSee('This card is not valid')->assertDontSee('Security');
    }

    private function city(string $slug, string $code): City
    {
        return City::query()->forceCreate([
            'name_en' => $slug,
            'name_ar' => $slug,
            'slug' => $slug,
            'code' => $code,
            'is_active' => true,
        ]);
    }

    private function employee(City $city, string $username): Employee
    {
        return Employee::query()->create([
            'city_id' => $city->id,
            'first_name_en' => 'Security',
            'first_name_ar' => 'أمان',
            'last_name_en' => 'Test',
            'last_name_ar' => 'اختبار',
            'username' => $username,
            'is_active' => true,
        ]);
    }

    private function userInCity(City $city): User
    {
        $employee = $this->employee($city, 'security-user-'.$city->id);

        return User::factory()->create(['employee_id' => $employee->id]);
    }
}
