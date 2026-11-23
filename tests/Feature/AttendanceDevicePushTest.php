<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceSighting;
use App\Models\AttendanceImport;
use App\Models\AttendanceMonth;
use App\Models\AttendancePunch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * A ZKTeco device whose Cloud Server is set to ADMS calls /iclock/…: it says hello with its serial,
 * is told where to start, and posts its attendance log. It has no login, so it is believed by its
 * registered serial number only; a stranger is refused and remembered so HR can add it.
 */
class AttendanceDevicePushTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Receiving is off by default (config/attendance.php device_push); these tests are about receiving.
        config(['attendance.device_push' => true]);
    }

    public function test_nothing_is_received_from_devices_or_the_agent_while_it_is_turned_off(): void
    {
        config(['attendance.device_push' => false]);

        $this->get('/iclock/cdata?SN=NEWDEVICE01&options=all')->assertNotFound();
        $this->call('POST', '/iclock/cdata?SN=NEWDEVICE01&table=ATTLOG', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '7	2026-09-01 08:00:00')->assertNotFound();
        $this->getJson('/api/attendance/agent/state')->assertNotFound();
        $this->postJson('/api/attendance/agent/punches', [])->assertNotFound();

        $this->assertDatabaseCount('attendance_punches', 0);
        $this->assertDatabaseCount('attendance_devices', 0);
    }

    public function test_a_device_that_is_not_registered_is_refused_and_remembered(): void
    {
        $this->get('/iclock/cdata?SN=NEWDEVICE01&options=all')->assertForbidden()->assertSee('UNREGISTERED');
        $this->get('/iclock/getrequest?SN=NEWDEVICE01')->assertForbidden();

        $sighting = AttendanceDeviceSighting::query()->where('serial', 'NEWDEVICE01')->sole();
        $this->assertSame(2, $sighting->attempts);

        // What it holds is not lost: nothing was taken in, so it will send it all again once added.
        $this->assertSame(0, AttendancePunch::query()->count());

        // Not a serial at all: ignored, not even remembered.
        $this->get('/iclock/cdata?SN='.rawurlencode('x; drop table'))->assertForbidden();
        $this->assertSame(1, AttendanceDeviceSighting::query()->count());
    }

    public function test_hello_from_a_registered_device_says_where_to_start_and_moves_on_with_its_position(): void
    {
        $device = $this->device();

        $this->get('/iclock/cdata?SN=SFAA123456&options=all&pushver=2.4.1')
            ->assertOk()
            ->assertSee('GET OPTION FROM: SFAA123456', false)
            ->assertSee('ATTLOGStamp=None', false);

        $device->refresh();
        $this->assertNotNull($device->last_seen_at);
        $this->assertSame('2.4.1', $device->push_version);

        $this->get('/iclock/getrequest?SN=SFAA123456')->assertOk()->assertSee('OK');

        // After a push it reports where it got to; that is handed back at the next hello.
        $this->push('SFAA123456', '', '777')->assertOk();
        $this->get('/iclock/cdata?SN=SFAA123456')->assertSee('ATTLOGStamp=777', false);
    }

    public function test_the_punches_are_taken_in_once_and_belong_to_the_employee_whose_number_it_is(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($aleppo, 'rami');
        $device = $this->device();
        $body = $employee->id."\t2026-09-14 07:58:10\t0\t1\t0\t0\t0\n"
            .$employee->id."\t2026-09-14 16:03:44\t1\t1\t0\t0\t0\n"
            ."99999\t2026-09-14 08:00:00\t0\t1\t0\t0\t0\n";

        $this->push('SFAA123456', $body, '3')->assertOk()->assertSee('OK:3', false);

        $this->assertSame(3, AttendancePunch::query()->count());
        $mine = AttendancePunch::query()->where('device_no', (string) $employee->id)->get();
        $this->assertSame(2, $mine->count());
        $this->assertSame([$employee->id, $device->id], [$mine->first()->employee_id, $mine->first()->device_id]);
        // A number that is nobody's yet is kept, waiting to be linked.
        $this->assertNull(AttendancePunch::query()->where('device_no', '99999')->value('employee_id'));

        // The same log sent again (a retry, a second hello): still acknowledged, nothing doubled.
        $this->push('SFAA123456', $body, '3')->assertOk()->assertSee('OK:3', false);
        $this->assertSame(3, AttendancePunch::query()->count());

        $device->refresh();
        $this->assertSame('2026-09-14 16:03:44', $device->last_punch_at->format('Y-m-d H:i:s'));
        $this->assertSame('3', $device->attlog_stamp);

        // One import row for the device's day, not one per push.
        $import = AttendanceImport::query()->where('source', 'device')->sole();
        $this->assertSame([$device->id, 6, 3], [$import->device_id, $import->punches_total, $import->punches_new]);
    }

    public function test_a_locked_month_takes_no_new_punches_from_a_device(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($aleppo, 'rami');
        $this->device();
        AttendanceMonth::query()->forceCreate(['employee_id' => $employee->id, 'month' => '2026-09-01', 'locked_at' => now()]);

        $this->push('SFAA123456', $employee->id."\t2026-09-14 07:58:10\t0\t1\n".$employee->id."\t2026-10-02 08:01:00\t0\t1\n")
            ->assertOk()->assertSee('OK:2', false);

        $this->assertSame(['2026-10-02 08:01:00'], AttendancePunch::query()->pluck('punched_at')->map->format('Y-m-d H:i:s')->all());
    }

    public function test_a_blocked_device_is_refused_and_a_device_call_carries_no_session(): void
    {
        $this->device(['status' => AttendanceDevice::BLOCKED]);

        $this->push('SFAA123456', "1\t2026-09-14 07:58:10\t0\t1\n")->assertForbidden();
        $this->assertSame(0, AttendancePunch::query()->count());

        $this->device(['serial' => 'GOODONE001']);
        $response = $this->get('/iclock/getrequest?SN=GOODONE001')->assertOk();

        // No cookie, no session: a device is not a browser.
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_a_device_goes_from_online_to_late_to_silent(): void
    {
        $device = $this->device();
        $this->assertSame('never', $device->health());

        $device->forceFill(['last_seen_at' => now()->subMinutes(2)])->save();
        $this->assertSame('online', $device->fresh()->health());

        $device->forceFill(['last_seen_at' => now()->subHours(3)])->save();
        $this->assertSame('late', $device->fresh()->health());

        $device->forceFill(['last_seen_at' => now()->subDays(4)])->save();
        $this->assertSame('silent', $device->fresh()->health());
    }

    private function device(array $attributes = []): AttendanceDevice
    {
        return AttendanceDevice::query()->create($attributes + ['serial' => 'SFAA123456', 'name' => 'Hope Center']);
    }

    private function push(string $serial, string $body, ?string $stamp = null)
    {
        $uri = '/iclock/cdata?SN='.$serial.'&table=ATTLOG'.($stamp ? '&Stamp='.$stamp : '');

        return $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    }
}
