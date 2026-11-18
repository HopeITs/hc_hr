<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceSighting;
use App\Models\Center;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The time-clock devices and the centers they hang in. A device is added once, by its serial number,
 * and from then on pushes its own punches (ZKTeco "Cloud Server", ADMS): no report to download, no visit
 * to the center. The page shows which devices are talking, which have gone quiet and which centers have
 * none, so HR knows where to look before a timesheet is found empty. A city HR sees its own centers.
 */
class Devices extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    /** Name and center typed beside each device that called but is not registered yet. @var array<string, string> */
    public array $newName = [];

    /** @var array<string, string> */
    public array $newCenter = [];

    public ?int $editingId = null;

    public string $editName = '';

    public string $editCenter = '';

    /** A sync key just made, shown once for that device and then gone (only its hash is kept). */
    #[Locked]
    public ?string $newKey = null;

    #[Locked]
    public ?int $keyForId = null;

    public function mount(): void
    {
        $this->authorizeActivePermission('attendance.manage');
    }

    /** Register a device that called, with the center it hangs in. */
    public function register(string $serial): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $sighting = AttendanceDeviceSighting::query()->where('serial', $serial)->firstOrFail();
        $centerId = $this->validCenter($this->newCenter[$serial] ?? '');

        if ($centerId === null) {
            $this->addError("newCenter.$serial", __('attendance.devices.choose-center'));

            return;
        }

        $name = trim($this->newName[$serial] ?? '');

        AttendanceDevice::query()->firstOrCreate(['serial' => $sighting->serial], [
            'name' => $name !== '' ? mb_substr($name, 0, 120) : Center::query()->find($centerId)?->name,
            'center_id' => $centerId,
            'created_by' => auth()->id(),
        ]);
        $sighting->delete();

        unset($this->newName[$serial], $this->newCenter[$serial]);
        $this->resetErrorBag();
        $this->toast(__('attendance.devices.added'));
    }

    public function forgetSighting(string $serial): void
    {
        $this->authorizeActivePermission('attendance.manage');

        AttendanceDeviceSighting::query()->where('serial', $serial)->delete();
    }

    public function edit(int $id): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $device = $this->deviceInScope($id);

        $this->editingId = $device->id;
        $this->editName = (string) $device->name;
        $this->editCenter = (string) ($device->center_id ?? '');
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->resetErrorBag();
    }

    public function saveDevice(): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $device = $this->deviceInScope((int) $this->editingId);
        $centerId = $this->validCenter($this->editCenter);

        if ($centerId === null) {
            $this->addError('editCenter', __('attendance.devices.choose-center'));

            return;
        }

        $device->update(['name' => mb_substr(trim($this->editName), 0, 120) ?: null, 'center_id' => $centerId]);

        $this->cancelEdit();
        $this->toast(__('attendance.devices.saved'));
    }

    /** A blocked device is refused until it is let in again; what it already sent stays. */
    public function toggleBlock(int $id): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $device = $this->deviceInScope($id);
        $device->update(['status' => $device->isBlocked() ? AttendanceDevice::ACTIVE : AttendanceDevice::BLOCKED]);
    }

    /** Make (or replace) the key the agent on a computer next to this device sends its punches with. */
    public function createKey(int $id): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $device = $this->deviceInScope($id);
        $this->newKey = $device->issueAgentKey();
        $this->keyForId = $device->id;
    }

    public function revokeKey(int $id): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $this->deviceInScope($id)->revokeAgentKey();

        if ($this->keyForId === $id) {
            $this->hideKey();
        }

        $this->toast(__('attendance.devices.key-revoked'));
    }

    public function hideKey(): void
    {
        $this->newKey = null;
        $this->keyForId = null;
    }

    /** Take a device off the list. Its punches stay; it can be added again. */
    public function removeDevice(int $id): void
    {
        $this->authorizeActivePermission('attendance.manage');

        $this->deviceInScope($id)->delete();
        $this->toast(__('attendance.devices.removed'));
    }

    public function render()
    {
        $centers = $this->centers();
        $devices = AttendanceDevice::query()
            ->with('center.city')
            ->where(fn ($query) => $query->whereNull('center_id')->orWhereIn('center_id', $centers->modelKeys()))
            ->get()
            ->sortBy(fn (AttendanceDevice $d) => [['silent' => 0, 'never' => 1, 'late' => 2, 'online' => 3][$d->health()] + ($d->isBlocked() ? 4 : 0), mb_strtolower((string) $d->name)])
            ->values();

        $registered = $devices->pluck('center_id')->filter()->unique();

        return view('livewire.attendance.devices', [
            'devices' => $devices,
            'sightings' => AttendanceDeviceSighting::query()->orderByDesc('last_seen_at')->limit(20)->get(),
            'centers' => $centers,
            // Centers with no device yet: where to set one up.
            'withoutDevice' => $centers->reject(fn (Center $c) => $registered->contains($c->id))->values(),
            'host' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
        ]);
    }

    /** @return Collection<int, Center> the centers this user may attach a device to (a city HR: its own cities') */
    private function centers(): Collection
    {
        $cities = auth()->user()?->activeCityIds();

        return Center::query()
            ->with('city')
            ->where('is_active', true)
            ->when($cities !== null, fn ($query) => $query->whereIn('city_id', $cities ?: [0]))
            ->orderBy(transColumn('name'))
            ->get();
    }

    private function validCenter(string $value): ?int
    {
        return ctype_digit($value) && $this->centers()->contains('id', (int) $value) ? (int) $value : null;
    }

    private function deviceInScope(int $id): AttendanceDevice
    {
        $device = AttendanceDevice::query()->findOrFail($id);

        // A device already tied to a center of another city is out of reach of a city HR.
        abort_unless(
            $device->center_id === null || $this->centers()->contains('id', $device->center_id),
            403,
        );

        return $device;
    }
}
