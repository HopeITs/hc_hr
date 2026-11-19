<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\AttendanceImport;
use App\Models\AttendancePunch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeUploadedPunchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_deletes_nothing(): void
    {
        $this->seedRows();
        $this->artisan('attendance:purge-uploaded --dry-run')->assertSuccessful();

        $this->assertSame(2, AttendancePunch::query()->count());
    }

    public function test_it_removes_only_uploaded_punches_and_their_records(): void
    {
        $this->seedRows();
        $this->artisan('attendance:purge-uploaded --force')->assertSuccessful();

        $this->assertSame(1, AttendancePunch::query()->count());
        $this->assertNotNull(AttendancePunch::query()->value('device_id'));
        $this->assertSame(['agent'], AttendanceImport::query()->pluck('source')->all());
    }

    private function seedRows(): void
    {
        $device = AttendanceDevice::query()->create(['serial' => 'SFAA254800375', 'name' => 'X']);
        $file = AttendanceImport::query()->forceCreate(['file_name' => 'a.xls', 'source' => 'file', 'punches_total' => 1, 'punches_new' => 1]);
        $push = AttendanceImport::query()->forceCreate(['file_name' => 'dev', 'source' => 'agent', 'device_id' => $device->id, 'punches_total' => 1, 'punches_new' => 1]);
        AttendancePunch::query()->create(['import_id' => $file->id, 'device_no' => '1', 'punched_at' => '2026-09-14 08:00:00']);
        AttendancePunch::query()->create(['import_id' => $push->id, 'device_id' => $device->id, 'device_no' => '1', 'punched_at' => '2026-09-15 08:00:00']);
    }
}
