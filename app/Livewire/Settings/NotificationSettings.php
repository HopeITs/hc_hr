<?php

namespace App\Livewire\Settings;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\NotificationTester;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Notification settings (settings.notifications, docs/notifications/architecture.md).
 *
 * Every control saves on its own as soon as it changes: the value is
 * validated, written to the user's NotificationPreference and acknowledged in
 * a polite live region. An invalid value is put back to what was saved, so the
 * page never shows a setting that is not in force.
 *
 * The device list and every device action go through
 * auth()->user()->pushSubscriptions(), never PushSubscription::find(), so an
 * id from the browser can only ever reach the signed-in user's own devices.
 *
 * What only the browser knows (permission, this browser's subscription) is
 * the Alpine component `pushDevice` (resources/js/push-notifications.js).
 */
class NotificationSettings extends Component
{
    /** Plain switches and fields of notification_preferences, by column. */
    public const BOOLEANS = ['enabled', 'in_app', 'desktop', 'mobile', 'email', 'sound', 'previews', 'quiet_hours', 'urgent_bypass'];

    public const FIELDS = [...self::BOOLEANS, 'frequency', 'digest_time', 'quiet_start', 'quiet_end', 'timezone'];

    public const TEST_TARGETS = ['device', 'devices', 'in_app', 'email'];

    /** Test sends per user per minute: each one is a real push or e-mail. */
    private const TEST_LIMIT = 5;

    /** @var array<string, mixed> the columns in FIELDS */
    public array $prefs = [];

    /** @var array<int, int> Carbon dayOfWeek, 0 = Sunday */
    public array $workingDays = [];

    /** @var array<string, array<string, bool>> category => channel => on */
    public array $matrix = [];

    public string $testTarget = 'devices';

    /** @var array{ok: bool, message: string}|null */
    public ?array $testResult = null;

    /** '' | 'saved' | 'error': what the live region last announced. */
    public string $saveState = '';

    /** Bumped on every outcome, so the live region re-announces a repeated "Saved". */
    public int $saveTick = 0;

    public function mount(): void
    {
        $this->load(NotificationPreference::for($this->user()));
    }

    // ---------------------------------------------------------------- general

    public function updatedPrefs(mixed $value, string $key): void
    {
        if (! in_array($key, self::FIELDS, true)) {
            $this->revert();

            return;
        }

        $validator = Validator::make(['value' => $value], ['value' => $this->ruleFor($key)], [], ['value' => __('notification-settings.fields.'.$key)]);

        if ($validator->fails()) {
            $this->fail('prefs.'.$key, $validator->errors()->first('value'));

            return;
        }

        $value = match (true) {
            in_array($key, self::BOOLEANS, true) => (bool) $value,
            $key === 'timezone' => $value === '' || $value === null ? null : (string) $value,
            default => (string) $value,
        };

        $this->persist(fn (NotificationPreference $p) => $p->{$key} = $value);

        // The in-app chime follows at once (resources/js/push-notifications.js).
        if ($key === 'sound') {
            $this->dispatch('notif-sound-changed', on: $value);
        }
    }

    public function updatedWorkingDays(): void
    {
        $days = $this->workingDays;

        $validator = Validator::make(['days' => $days], [
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:0,6', 'distinct'],
        ], [
            'days.required' => __('notification-settings.schedule.days-required'),
            'days.min' => __('notification-settings.schedule.days-required'),
        ], ['days' => __('notification-settings.fields.working_days'), 'days.*' => __('notification-settings.fields.working_days')]);

        if ($validator->fails()) {
            $this->fail('workingDays', $validator->errors()->first());

            return;
        }

        $days = array_map('intval', $days);
        sort($days);

        // Every day = no restriction: stored as null, like a fresh row.
        $this->persist(fn (NotificationPreference $p) => $p->working_days = count($days) === 7 ? null : $days);
    }

    public function updatedMatrix(mixed $value, string $key): void
    {
        [$category, $channel] = array_pad(explode('.', $key, 2), 2, null);

        $category = NotificationCategory::tryFrom((string) $category);
        $channel = NotificationChannel::tryFrom((string) $channel);

        if (! $category || ! $channel || ! is_bool(filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE))) {
            $this->fail('matrix', __('notification-settings.status.invalid'));

            return;
        }

        $this->persist(fn (NotificationPreference $p) => $p->setCategoryChannel($category, $channel, filter_var($value, FILTER_VALIDATE_BOOLEAN)));
    }

    // ---------------------------------------------------------------- devices

    /** @return Collection<int, PushSubscription> */
    #[Computed]
    public function devices(): Collection
    {
        return $this->user()->pushSubscriptions()->latest('last_seen_at')->latest('id')->get();
    }

    /** @return bool whether the name was saved (the inline editor closes on true) */
    public function renameDevice(int $id, string $name): bool
    {
        $device = $this->device($id);
        $name = trim($name);

        $validator = Validator::make(['name' => $name], ['name' => ['nullable', 'string', 'max:80']], [], ['name' => __('notification-settings.devices.name')]);

        if ($validator->fails()) {
            $this->resetErrorBag();
            $this->addError('device.'.$id, $validator->errors()->first('name'));
            $this->announce('error');

            return false;
        }

        $device->update(['device_name' => $name === '' ? null : $name]);
        $this->deviceSaved();

        return true;
    }

    public function setDeviceEnabled(int $id, bool $on): void
    {
        $this->device($id)->update(['enabled' => $on]);
        $this->deviceSaved();
    }

    public function setDeviceUrgentOnly(int $id, bool $on): void
    {
        $this->device($id)->update(['urgent_only' => $on]);
        $this->deviceSaved();
    }

    public function removeDevice(int $id): void
    {
        $this->device($id)->delete();
        $this->deviceSaved();

        // pushDevice compares this with its own subscription id: when it was
        // this browser, it can unsubscribe and show "not enabled here".
        $this->dispatch('push-device-removed', id: $id);
    }

    // ------------------------------------------------------------------- test

    /**
     * Send a real test. $subscriptionId is this browser's subscription (from
     * pushDevice) for the "device" target, or the device a row's Test button
     * names; either way it must be one of the user's own.
     */
    public function sendTest(?int $subscriptionId = null, ?string $target = null): void
    {
        $target ??= $this->testTarget;
        $user = $this->user();

        if (! in_array($target, self::TEST_TARGETS, true)) {
            $this->testResult = ['ok' => false, 'message' => __('notification-settings.test.invalid-target')];

            return;
        }

        if ($target === 'device') {
            if ($subscriptionId === null) {
                $this->testResult = ['ok' => false, 'message' => __('notification-settings.test.no-device')];

                return;
            }

            $this->device($subscriptionId);
        } else {
            $subscriptionId = null;
        }

        $key = 'notification-test:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, self::TEST_LIMIT)) {
            $this->testResult = ['ok' => false, 'message' => __('notification-settings.test.throttled', ['seconds' => RateLimiter::availableIn($key)])];

            return;
        }

        RateLimiter::hit($key, 60);

        try {
            $result = app(NotificationTester::class)->send($user, $target, $subscriptionId);
        } catch (Throwable $e) {
            report($e);
            $result = ['ok' => false, 'message' => __('notification-settings.test.failed')];
        }

        $this->testResult = [
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? __('notification-settings.test.failed')),
        ];

        unset($this->devices);
    }

    public function testDevice(int $id): void
    {
        $this->sendTest($id, 'device');
    }

    public function render()
    {
        $locale = app()->getLocale();
        // Arabic calendars start the week on Saturday, English ones on Sunday.
        $weekOrder = $locale === 'ar' ? [6, 0, 1, 2, 3, 4, 5] : [0, 1, 2, 3, 4, 5, 6];

        return view('livewire.settings.notifications', [
            'categories' => NotificationCategory::cases(),
            'channels' => NotificationChannel::cases(),
            'frequencies' => NotificationPreference::FREQUENCIES,
            'timezones' => DateTimeZone::listIdentifiers(),
            'appTimezone' => config('app.timezone'),
            'weekOrder' => $weekOrder,
            'testTargets' => self::TEST_TARGETS,
        ]);
    }

    private function load(NotificationPreference $preference): void
    {
        foreach (self::FIELDS as $field) {
            $this->prefs[$field] = in_array($field, self::BOOLEANS, true)
                ? (bool) $preference->{$field}
                : (string) ($preference->{$field} ?? '');
        }

        $this->workingDays = $preference->workingDays();
        $this->matrix = $preference->matrix();
    }

    /** @return array<int, mixed> */
    private function ruleFor(string $key): array
    {
        return match (true) {
            in_array($key, self::BOOLEANS, true) => ['required', 'boolean'],
            $key === 'frequency' => ['required', 'string', Rule::in(NotificationPreference::FREQUENCIES)],
            $key === 'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            default => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        };
    }

    /** Write one change, then reload every value from what was stored. */
    private function persist(callable $change): void
    {
        $preference = NotificationPreference::for($this->user());
        $change($preference);
        $preference->save();

        $this->resetErrorBag();
        $this->load($preference);
        $this->announce('saved');
    }

    private function fail(string $field, string $message): void
    {
        $this->revert();
        $this->resetErrorBag();
        $this->addError($field, $message);
        $this->announce('error');
    }

    /** Put every control back to what is stored. */
    private function revert(): void
    {
        $this->load(NotificationPreference::for($this->user()));
    }

    private function device(int $id): PushSubscription
    {
        return $this->user()->pushSubscriptions()->findOrFail($id);
    }

    private function deviceSaved(): void
    {
        unset($this->devices);
        $this->resetErrorBag();
        $this->announce('saved');
    }

    private function announce(string $state): void
    {
        $this->saveState = $state;
        $this->saveTick++;
    }

    private function user(): User
    {
        return auth()->user();
    }
}
