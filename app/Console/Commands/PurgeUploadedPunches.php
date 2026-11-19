<?php

namespace App\Console\Commands;

use App\Models\AttendanceImport;
use App\Models\AttendanceMonth;
use App\Models\AttendancePunch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes every punch that came from an uploaded report file (no device behind it) and the records of those
 * uploads, so that what a device or the LAN agent sends afterwards is stored under that device. A punch is
 * kept once per device number + minute, so while the file rows stay, the device's copy of the same punch
 * would be ignored and keep looking like an upload.
 *
 * PERMANENT. Locked months are included and the device will not refill them (a locked month takes no new
 * punches), so run --dry-run first. Approved months lose their approval, since their punches change.
 */
class PurgeUploadedPunches extends Command
{
    protected $signature = 'attendance:purge-uploaded {--dry-run : Only count, delete nothing} {--force : Do not ask}';

    protected $description = 'Delete every punch that came from an uploaded report file (permanent)';

    public function handle(): int
    {
        $punches = AttendancePunch::query()->whereNull('device_id');
        $imports = AttendanceImport::query()->where('source', 'file');

        $months = AttendancePunch::query()->whereNull('device_id')
            ->selectRaw('count(*) as n, min(punched_at) as first, max(punched_at) as last')->first();

        $locked = AttendanceMonth::query()->whereNotNull('locked_at')->count();
        $approved = AttendanceMonth::query()->whereNotNull('approved_at')->whereNull('locked_at')->count();

        $this->line(sprintf('Uploaded punches: %d (%s → %s)', $months->n, $months->first ?? '—', $months->last ?? '—'));
        $this->line(sprintf('Upload records:   %d', $imports->count()));
        $this->line(sprintf('Punches kept (from devices): %d', AttendancePunch::query()->whereNotNull('device_id')->count()));
        $this->line(sprintf('Months locked: %d (the device will NOT refill them) · approved, not locked: %d (lose approval)', $locked, $approved));

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete these permanently?')) {
            $this->warn('Cancelled.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($punches, $imports) {
            $affected = $punches->clone()->whereNotNull('employee_id')->distinct()->pluck('employee_id');

            $punches->delete();
            $imports->delete();

            // Their approval rested on punches that are gone.
            AttendanceMonth::query()->whereIn('employee_id', $affected)->whereNotNull('approved_at')->whereNull('locked_at')
                ->update(['approved_at' => null, 'approved_by' => null]);
        });

        $this->info('Deleted. Run the agent (node agent.cjs --full) or let the device push to load its history.');

        return self::SUCCESS;
    }
}
