<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Devices;
use App\Models\AttendanceDevice;
use App\Models\AttendanceImport;
use App\Models\AttendanceMonth;
use App\Models\AttendancePunch;
use App\Models\Center;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The attendance agent reads a device on its own network and sends the punches here with the device's sync
 * key. The key is the device: it is shown once, only its hash is kept, and a batch is taken in by the same
 * routine as every other way in.
 */
class AttendanceAgentTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_without_a_valid_key_nothing_is_taken(): void
    {
        [$device] = $this->device();

        $this->getJson('/api/attendance/agent/state')->assertUnauthorized();
        $this->getJson('/api/attendance/agent/state', ['Authorization' => 'Bearer nonsense'])->assertUnauthorized();
        $this->getJson('/api/attendance/agent/state', ['Authorization' => 'Bearer zk_'.str_repeat('a', 40)])->assertUnauthorized();
        $this->postJson('/api/attendance/agent/punches', ['punches' => []])->assertUnauthorized();

        $this->assertSame(0, AttendancePunch::query()->count());
        // A blocked device is refused even with its own key.
        $device->update(['status' => AttendanceDevice::BLOCKED]);
        $this->getJson('/api/attendance/agent/state', ['Authorization' => 'Bearer '.$device->issueAgentKey()])->assertForbidden();
    }

    public function test_the_key_is_kept_only_as_a_hash_and_a_new_one_replaces_the_old(): void
    {
        [$device, $key] = $this->device();

        $this->assertStringStartsWith('zk_', $key);
        $device->refresh();
        $this->assertSame(hash('sha256', $key), $device->agent_key_hash);
        $this->assertSame(substr($key, -4), $device->agent_key_hint);
        $this->assertStringNotContainsString($key, json_encode($device->getAttributes()));

        $this->getJson('/api/attendance/agent/state', ['Authorization' => 'Bearer '.$key])->assertOk();

        $new = $device->issueAgentKey();
        $this->getJson('/api/attendance/agent/state', ['Authorization' => 'Bearer '.$key])->assertUnauthorized();
        $this->getJson('/api/attendance/agent/state', ['Authorization' => 'Bearer '.$new])->assertOk();

        $device->revokeAgentKey();
        $this->getJson('/api/attendance/agent/state', ['Authorization' => 'Bearer '.$new])->assertUnauthorized();
        $this->assertFalse($device->fresh()->hasAgentKey());
    }

    public function test_punches_are_taken_in_once_and_the_state_says_where_they_reach(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($aleppo, 'rami');
        [$device, $key] = $this->device();

        $this->getJson('/api/attendance/agent/state', ['Authorization' => 'Bearer '.$key])
            ->assertOk()->assertJson(['serial' => 'SFAA254800375', 'last_punch_at' => null]);

        $punches = [
            ['pin' => (string) $employee->id, 'at' => '2026-09-14 07:58:10'],
            ['pin' => (string) $employee->id, 'at' => '2026-09-14 16:03:44'],
            ['pin' => '99999', 'at' => '2026-09-14 08:00:00'],
        ];

        $this->send($key, $punches, ['serial' => 'SFAA254800375'])
            ->assertOk()->assertJson(['received' => 3, 'new' => 3, 'locked' => 0, 'rejected' => 0, 'last_punch_at' => '2026-09-14 16:03:44']);

        $mine = AttendancePunch::query()->where('device_no', (string) $employee->id)->get();
        $this->assertSame([2, $employee->id, $device->id], [$mine->count(), $mine->first()->employee_id, $mine->first()->device_id]);
        $this->assertNull(AttendancePunch::query()->where('device_no', '99999')->value('employee_id'));

        // The same batch again (a retry, or the next run reading the same log): nothing doubled.
        $this->send($key, $punches)->assertOk()->assertJson(['received' => 3, 'new' => 0]);
        $this->assertSame(3, AttendancePunch::query()->count());

        $this->assertSame(1, AttendanceImport::query()->where('source', 'agent')->count());
        $this->assertNotNull($device->fresh()->agent_last_sync_at);

        $this->getJson('/api/attendance/agent/state', ['Authorization' => 'Bearer '.$key])->assertJson(['last_punch_at' => '2026-09-14 16:03:44']);
    }

    public function test_a_locked_month_a_wrong_clock_and_a_bad_batch_are_handled(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($aleppo, 'rami');
        [, $key] = $this->device();
        AttendanceMonth::query()->forceCreate(['employee_id' => $employee->id, 'month' => '2026-09-01', 'locked_at' => now()]);

        $this->send($key, [
            ['pin' => (string) $employee->id, 'at' => '2026-09-14 07:58:10'],   // a locked month
            ['pin' => (string) $employee->id, 'at' => '2026-10-02 08:01:00'],   // fine
            ['pin' => (string) $employee->id, 'at' => '2000-02-08 01:34:15'],   // a clock that was never set
            ['pin' => (string) $employee->id, 'at' => now()->addDays(40)->format('Y-m-d H:i:s')], // far ahead
        ])->assertOk()->assertJson(['new' => 1, 'locked' => 1, 'rejected' => 2]);

        $this->assertSame(['2026-10-02 08:01:00'], AttendancePunch::query()->pluck('punched_at')->map->format('Y-m-d H:i:s')->all());

        $this->send($key, [['pin' => '1', 'at' => 'yesterday']])->assertStatus(422)->assertJson(['error' => 'invalid_request']);
        $this->send($key, array_fill(0, 2001, ['pin' => '1', 'at' => '2026-10-02 08:01:00']))->assertStatus(422);
        $this->send($key, [], ['serial' => 'SOMEONEELSE1'])->assertStatus(422)->assertJson(['error' => 'serial_mismatch']);
    }

    public function test_hr_makes_a_key_shown_once_and_a_city_hr_reaches_only_its_own_centers(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $homs = $this->makeCity('HMS', '002');
        $alp = Center::query()->create(['name_en' => 'Aleppo Center', 'name_ar' => 'x', 'city_id' => $aleppo->id, 'is_active' => true, 'weekends' => '5,6', 'open_at' => '08:00:00', 'close_at' => '16:00:00']);
        Center::query()->create(['name_en' => 'Homs Center', 'name_ar' => 'x', 'city_id' => $homs->id, 'is_active' => true, 'weekends' => '5,6', 'open_at' => '08:00:00', 'close_at' => '16:00:00']);
        $device = AttendanceDevice::query()->create(['serial' => 'SFAA254800375', 'name' => 'Science Zone', 'center_id' => $alp->id]);
        $hr = $this->makeUser($aleppo, 'HR-SYR', ['attendance.manage']);
        $homsHr = $this->makeUser($homs, 'HR-HMS', ['attendance.manage']);

        $page = Livewire::actingAs($hr)->test(Devices::class)->call('createKey', $device->id);
        $key = $page->get('newKey');

        $this->assertStringStartsWith('zk_', $key);
        $page->assertSee($key)->assertSeeHtml('data-testid="new-key"');
        $this->assertTrue(AttendanceDevice::forAgentKey($key)?->is($device));

        // Shown once: after "Done" the page no longer has it, and the database never did.
        $page->call('hideKey')->assertDontSee($key);
        Livewire::actingAs($hr)->test(Devices::class)->assertDontSee($key)->assertSee('zk_••••'.substr($key, -4));

        // A city HR of another city cannot make or revoke a key for this device.
        Livewire::actingAs($homsHr)->test(Devices::class)->call('createKey', $device->id)->assertForbidden();
        Livewire::actingAs($homsHr)->test(Devices::class)->call('revokeKey', $device->id)->assertForbidden();
        $this->assertTrue($device->fresh()->hasAgentKey());

        Livewire::actingAs($hr)->test(Devices::class)->call('revokeKey', $device->id);
        $this->assertFalse($device->fresh()->hasAgentKey());
    }

    private function device(array $attributes = []): array
    {
        $device = AttendanceDevice::query()->create($attributes + ['serial' => 'SFAA254800375', 'name' => 'Science Zone']);

        return [$device, $device->issueAgentKey()];
    }

    private function send(string $key, array $punches, array $extra = [])
    {
        return $this->postJson('/api/attendance/agent/punches', ['punches' => $punches] + $extra, ['Authorization' => 'Bearer '.$key]);
    }
}
