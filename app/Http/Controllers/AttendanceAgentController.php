<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Services\Attendance\DevicePush;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Where the attendance agent (tools/attendance-agent) sends the punches it read from a device on its own local
 * network. No login or session: the agent holds a sync key made for one device on the Devices page, and the key
 * IS the device here. Only a hash of it is stored.
 *
 *  - GET  /api/attendance/agent/state    where this device's punches reach, so the agent sends only what is newer
 *  - POST /api/attendance/agent/punches  a batch of punches, taken in by the same routine as every other way in
 *    (once per device number + minute; a locked month is skipped; an approved month loses its approval)
 */
class AttendanceAgentController extends Controller
{
    /** The most one request may carry. */
    public const MAX_BATCH = 2000;

    public function state(Request $request): JsonResponse
    {
        $device = $this->device($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();

        return response()->json([
            'serial' => $device->serial,
            'name' => $device->name,
            'last_punch_at' => $device->last_punch_at?->format('Y-m-d H:i:s'),
            'server_time' => now()->format('Y-m-d H:i:s'),
            'max_batch' => self::MAX_BATCH,
        ]);
    }

    public function punches(Request $request, DevicePush $push): JsonResponse
    {
        $device = $this->device($request);

        if ($device instanceof JsonResponse) {
            return $device;
        }

        $validator = Validator::make($request->all(), [
            'serial' => ['nullable', 'string', 'max:64'],
            'punches' => ['present', 'array', 'max:'.self::MAX_BATCH],
            'punches.*.pin' => ['required', 'string', 'max:32'],
            'punches.*.at' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'invalid_request', 'details' => $validator->errors()->toArray()], 422);
        }

        // The key belongs to one device: a batch that says it is from another is a mistake worth stopping.
        $serial = $request->input('serial');

        if (is_string($serial) && $serial !== '' && $serial !== $device->serial) {
            return response()->json(['error' => 'serial_mismatch', 'expected' => $device->serial], 422);
        }

        $punches = [];
        $rejected = 0;
        $tooLate = now()->addDay();

        foreach ($request->input('punches', []) as $row) {
            $at = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $row['at']);
            $pin = trim($row['pin']);

            // A clock that was never set, or set far ahead, is not a punch to keep.
            if (! $at instanceof CarbonImmutable || $pin === '' || $at->year < 2015 || $at->gt($tooLate)) {
                $rejected++;

                continue;
            }

            $punches[] = ['device_no' => $pin, 'at' => $at];
        }

        $result = $push->ingestPunches($device, $punches, null, 'agent');

        $device->forceFill(['agent_last_sync_at' => now(), 'last_seen_at' => now(), 'last_ip' => $request->ip()])->save();

        return response()->json([
            'received' => count($punches) + $rejected,
            'new' => $result['new'],
            'locked' => $result['locked'],
            'rejected' => $rejected,
            'last_punch_at' => $device->fresh()->last_punch_at?->format('Y-m-d H:i:s'),
        ]);
    }

    /** The device the presented key belongs to, or the refusal to send back. */
    private function device(Request $request): AttendanceDevice|JsonResponse
    {
        $device = AttendanceDevice::forAgentKey($request->bearerToken());

        if ($device === null) {
            return response()->json(['error' => 'invalid_key'], 401);
        }

        if ($device->isBlocked()) {
            return response()->json(['error' => 'device_blocked'], 403);
        }

        return $device;
    }
}
