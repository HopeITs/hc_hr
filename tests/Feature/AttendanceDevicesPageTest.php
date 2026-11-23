<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Devices;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceSighting;
use App\Models\Center;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The device list: HR adds a device that called by choosing its center, sees which devices went
 * quiet and which centers have none, and a city HR only reaches its own centers.
 */
class AttendanceDevicesPageTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The page is hidden by default (config/attendance.php devices_page); these tests are about the page itself.
        config(['attendance.devices_page' => true]);
    }

    public function test_the_device_page_is_hidden_until_it_is_turned_on(): void
    {
        config(['attendance.devices_page' => false]);
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['attendance.view-own', 'attendance.view', 'attendance.manage']);

        $this->actingAs($hr)->get(route('attendance.devices'))->assertNotFound();
        $this->actingAs($hr)->get(route('attendance.upload'))->assertOk()->assertDontSee(route('attendance.devices'));
    }

    public function test_only_those_who_manage_attendance_open_the_device_list(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($aleppo, 'employee', ['attendance.view-own']);

        Livewire::actingAs($employee)->test(Devices::class)->assertForbidden();
        $this->actingAs($employee)->get(route('attendance.devices'))->assertForbidden();
    }

    public function test_a_device_that_called_is_added_by_choosing_its_center(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $center = $this->center($aleppo, 'Hope Center Aleppo');
        $hr = $this->makeUser($aleppo, 'HR-SYR', ['attendance.manage']);
        AttendanceDeviceSighting::query()->create(['serial' => 'SFAA123456', 'last_ip' => '5.6.7.8', 'attempts' => 3, 'last_seen_at' => now()]);

        $page = Livewire::actingAs($hr)->test(Devices::class)
            ->assertSeeHtml('data-testid="device-sightings"')
            ->assertSee('SFAA123456')
            // No center chosen: not added.
            ->call('register', 'SFAA123456')
            ->assertHasErrors('newCenter.SFAA123456');

        $this->assertSame(0, AttendanceDevice::query()->count());

        $page->set('newCenter.SFAA123456', (string) $center->id)
            ->set('newName.SFAA123456', 'Front door')
            ->call('register', 'SFAA123456')
            ->assertHasNoErrors();

        $device = AttendanceDevice::query()->sole();
        $this->assertSame(['SFAA123456', 'Front door', $center->id], [$device->serial, $device->name, $device->center_id]);
        $this->assertSame(0, AttendanceDeviceSighting::query()->count());
    }

    public function test_the_list_shows_the_state_of_each_device_and_the_centers_without_one(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $withDevice = $this->center($aleppo, 'Alpha Center');
        $this->center($aleppo, 'Bravo Center');
        $hr = $this->makeUser($aleppo, 'HR-SYR', ['attendance.manage']);
        AttendanceDevice::query()->create(['serial' => 'QUIET00001', 'name' => 'Alpha clock', 'center_id' => $withDevice->id, 'last_seen_at' => now()->subDays(5)]);

        Livewire::actingAs($hr)->test(Devices::class)
            ->assertSeeHtml('data-health="silent"')
            ->assertSee('Alpha clock')
            // HR sees the devices: no list of centers without one, no device settings (that is IT's).
            ->assertDontSeeHtml('data-testid="centers-without-device"')
            ->assertDontSee('Bravo Center')
            ->assertDontSeeHtml('data-testid="device-setup"');
    }

    public function test_a_device_can_be_blocked_edited_and_removed(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $first = $this->center($aleppo, 'Alpha Center');
        $second = $this->center($aleppo, 'Bravo Center');
        $hr = $this->makeUser($aleppo, 'HR-SYR', ['attendance.manage']);
        $device = AttendanceDevice::query()->create(['serial' => 'DEV0000001', 'name' => 'Old', 'center_id' => $first->id]);

        Livewire::actingAs($hr)->test(Devices::class)
            ->call('toggleBlock', $device->id)
            ->call('edit', $device->id)
            ->set('editName', 'New name')
            ->set('editCenter', (string) $second->id)
            ->call('saveDevice')
            ->assertHasNoErrors();

        $device->refresh();
        $this->assertSame([AttendanceDevice::BLOCKED, 'New name', $second->id], [$device->status, $device->name, $device->center_id]);

        Livewire::actingAs($hr)->test(Devices::class)->call('removeDevice', $device->id);
        $this->assertSame(0, AttendanceDevice::query()->count());
    }

    public function test_a_city_hr_reaches_only_the_centers_of_its_own_city(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $homs = $this->makeCity('HMS', '002');
        $alp = $this->center($aleppo, 'Aleppo Center');
        $hms = $this->center($homs, 'Homs Center');
        $homsHr = $this->makeUser($homs, 'HR-HMS', ['attendance.manage']);
        $inAleppo = AttendanceDevice::query()->create(['serial' => 'ALEPPODEV1', 'name' => 'Aleppo clock', 'center_id' => $alp->id]);
        AttendanceDeviceSighting::query()->create(['serial' => 'NEWONE0001', 'last_seen_at' => now()]);

        $page = Livewire::actingAs($homsHr)->test(Devices::class)
            ->assertDontSee('Aleppo clock')
            ->assertDontSee('Aleppo Center')
            ->assertSee('Homs Center');

        // A center of another city cannot be chosen, and another city's device cannot be touched.
        $page->set('newCenter.NEWONE0001', (string) $alp->id)->call('register', 'NEWONE0001')->assertHasErrors('newCenter.NEWONE0001');
        $page->call('toggleBlock', $inAleppo->id)->assertForbidden();
        $this->assertSame(0, AttendanceDevice::query()->where('serial', 'NEWONE0001')->count());

        // (A refused call ends that page; a fresh one for the center of its own city.)
        Livewire::actingAs($homsHr)->test(Devices::class)
            ->set('newCenter.NEWONE0001', (string) $hms->id)->call('register', 'NEWONE0001')->assertHasNoErrors();
        $this->assertSame($hms->id, AttendanceDevice::query()->where('serial', 'NEWONE0001')->value('center_id'));
    }

    private function center(City $city, string $name): Center
    {
        return Center::query()->create([
            'name_en' => $name, 'name_ar' => $name, 'city_id' => $city->id, 'is_active' => true,
            'weekends' => '5,6', 'open_at' => '08:00:00', 'close_at' => '16:00:00',
        ]);
    }
}
