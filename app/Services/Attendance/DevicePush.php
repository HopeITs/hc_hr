<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceSighting;
use App\Models\AttendanceImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The receiving end of a ZKTeco device's "Cloud Server" (ADMS push). The device calls the system by
 * itself: it says hello with its serial number, is told what to send, and posts its attendance log.
 * A device is only believed when HR registered its serial; one that is not is remembered as a
 * "sighting" so HR can add it with one click, and is told to try again later, so nothing it holds is
 * lost (a device keeps its own log and only moves on when the server says OK).
 *
 * The punches go through AttendanceImporter::store(), the same routine an uploaded report uses, so a
 * locked month, an approved month and a repeated punch behave exactly as they do there.
 */
class DevicePush
{
    public function __construct(private readonly AttendanceImporter $importer) {}

    /**
     * The registered, unblocked device for this serial, marked as just heard from; otherwise null.
     * An unknown serial is recorded so HR can add it.
     */
    public function resolve(?string $serial, ?string $ip, ?string $pushVersion = null): ?AttendanceDevice
    {
        $serial = trim((string) $serial);

        // A serial is letters, digits and dashes. Anything else is not a device.
        if ($serial === '' || strlen($serial) > 64 || ! preg_match('/^[A-Za-z0-9_\-]+$/', $serial)) {
            return null;
        }

        $device = AttendanceDevice::query()->where('serial', $serial)->first();

        if ($device === null) {
            $sighting = AttendanceDeviceSighting::query()->firstOrNew(['serial' => $serial]);
            $sighting->forceFill(['last_ip' => $ip, 'last_seen_at' => now(), 'attempts' => (int) $sighting->attempts + 1])->save();

            return null;
        }

        if ($device->isBlocked()) {
            return null;
        }

        // It calls about every 30 seconds: no need to write more often than that.
        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subSeconds(20)) || ($pushVersion && $pushVersion !== $device->push_version)) {
            $device->forceFill(['last_seen_at' => now(), 'last_ip' => $ip, 'push_version' => $pushVersion ?: $device->push_version])->save();
        }

        return $device;
    }

    /** What the device is told when it says hello: send the attendance log from where it left off. */
    public function options(AttendanceDevice $device): string
    {
        return implode("\n", [
            'GET OPTION FROM: '.$device->serial,
            // "None" the first time: send everything it holds. After that, the position it reported.
            'ATTLOGStamp='.($device->attlog_stamp ?: 'None'),
            'OPERLOGStamp=9999',
            'ATTPHOTOStamp=None',
            'ErrorDelay=60',
            'Delay=30',
            'TransTimes=00:00;14:05',
            'TransInterval=1',
            'TransFlag=TransData AttLog',
            'Realtime=1',
            'Encrypt=None',
        ])."\n";
    }

    /**
     * Take in a posted attendance log: one punch per line, "PIN<tab>YYYY-MM-DD HH:MM:SS<tab>status…".
     * Returns how many lines it held, which is what the device wants to hear back ("OK:n").
     */
    public function ingestAttlog(AttendanceDevice $device, string $body, ?string $stamp): int
    {
        $lines = 0;
        $punches = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $lines++;

            if (! preg_match('/^(?<pin>[^\s]+)[\t ]+(?<time>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $m)) {
                continue;
            }

            $at = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', str_replace('T', ' ', $m['time']));

            if ($at instanceof CarbonImmutable) {
                $punches[] = ['device_no' => mb_substr($m['pin'], 0, 32), 'at' => $at];
            }
        }

        $this->ingestPunches($device, $punches, $stamp);

        return $lines;
    }

    /**
     * Take in punches that are already read: from the device's own push (above) or from an agent that read the
     * device on its local network. Returns how many were new and how many fell in a locked month.
     *
     * @param  list<array{device_no: string, at: CarbonImmutable}>  $punches
     * @return array{new: int, locked: int}
     */
    public function ingestPunches(AttendanceDevice $device, array $punches, ?string $stamp = null, string $source = 'device'): array
    {
        $result = ['new' => 0, 'locked' => 0];

        DB::transaction(function () use ($device, $punches, $stamp, $source, &$result) {
            if ($punches !== []) {
                $import = $this->dayImport($device, $source);
                $result = $this->importer->store($punches, $import, $device->id);
                $times = array_column($punches, 'at');
                $first = min($times)->toDateString();
                $last = max($times)->toDateString();

                $import->update([
                    'punches_total' => $import->punches_total + count($punches),
                    'punches_new' => $import->punches_new + $result['new'],
                    'punches_locked' => $import->punches_locked + $result['locked'],
                    'device_numbers' => max($import->device_numbers, count(array_unique(array_column($punches, 'device_no')))),
                    'period_start' => $import->period_start === null || $import->period_start->toDateString() > $first ? $first : $import->period_start,
                    'period_end' => $import->period_end === null || $import->period_end->toDateString() < $last ? $last : $import->period_end,
                ]);
            }

            $latest = $punches === [] ? null : max(array_column($punches, 'at'));

            $device->forceFill([
                'last_punch_at' => $latest !== null && ($device->last_punch_at === null || $latest->gt($device->last_punch_at)) ? $latest : $device->last_punch_at,
                'attlog_stamp' => $stamp !== null && $stamp !== '' ? mb_substr($stamp, 0, 32) : $device->attlog_stamp,
            ])->save();
        });

        return $result;
    }

    /** One import row per device per day, so the history reads "Hope Center · 2026-11-17" and not a row per push. */
    private function dayImport(AttendanceDevice $device, string $source = 'device'): AttendanceImport
    {
        return AttendanceImport::query()->firstOrCreate([
            'source' => $source,
            'device_id' => $device->id,
            'file_name' => ($device->name ?: $device->serial).' · '.now()->toDateString(),
        ]);
    }
}
