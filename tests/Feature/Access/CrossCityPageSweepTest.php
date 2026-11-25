<?php

namespace Tests\Feature\Access;

use App\Models\Permission;
use App\Models\Role;
use App\Support\AccessPermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Pages that take a record id must refuse someone outside that record's scope:
 * the HR role of another city, and a plain employee who is not the person.
 * The URL is typed by hand, so no menu or button hides it.
 */
class CrossCityPageSweepTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    public function test_record_pages_refuse_another_city_and_another_employee(): void
    {
        $this->withoutVite();

        foreach (AccessPermissionCatalog::names() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (['employee', 'HR-ALP', 'HR-HMS'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $aleppo = $this->makeCity('ALP', '001');
        $homs = $this->makeCity('HMS', '002');

        $target = $this->makeEmployee($homs, 'homs-person');
        $contract = $this->makeContractFor($target, ['position_id' => $this->makePosition($homs)->id]);

        // The profile page, the hover card and the photo are the directory tier
        // (access-policy.md §4.2): every colleague sees them by design, and the
        // tiers inside the profile are covered by EmployeeProfile*VisibilityTest.
        $pages = [
            "/employees/{$target->id}/edit",
            "/onboarding/manager-session/{$target->id}",
            "/contracts/{$contract->id}",
            "/contracts/{$contract->id}/edit",
            "/contracts/{$contract->id}/amend",
            "/contracts/{$contract->id}/renew",
            "/contracts/{$contract->id}/export",
            "/contracts/{$contract->id}/export-preview",
            "/positions/{$contract->position_id}/edit",
        ];

        $outsiders = [
            'HR-ALP' => $this->makeUser($aleppo, 'HR-ALP'),
            'employee' => $this->makeUser($homs, 'employee'),
        ];
        $insider = $this->makeUser($homs, 'HR-HMS');

        $leaks = [];
        foreach ($pages as $page) {
            foreach ($outsiders as $label => $user) {
                $status = $this->actingAs($user)->get($page)->getStatusCode();
                if ($status < 300 || $status >= 500) {
                    $leaks[] = "{$label} {$page} → {$status}";
                }
            }

            // Control: the city's own HR must not get a server error either.
            $status = $this->actingAs($insider)->get($page)->getStatusCode();
            // 503 is the PDF renderer saying no Chrome is installed here.
            if ($status >= 500 && $status !== 503) {
                $leaks[] = "HR-HMS (own city) {$page} → {$status}";
            }
        }

        $this->assertSame([], $leaks, implode(PHP_EOL, $leaks));
    }
}
