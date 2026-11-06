<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\PreferenceEmail;
use App\Notifications\PreferenceTestNotification;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Send a test" on the settings page. Every target is a real send, and the
 * message says only what the other end confirmed: a push "accepted by the
 * push service" (the device showing it is up to the browser), an email
 * "handed to the mail server".
 *
 * Targets:
 *   device   one push subscription, which must belong to $user
 *   devices  every enabled device of $user
 *   in_app   a row in the bell
 *   email    an email to $user's address
 *
 * in_app and email honour the master and channel switches (and say so when
 * they are off) but not the category matrix: a test belongs to no category
 * the person chose. Device tests check the connection itself, so they go out
 * whatever the switches say; the result reminds them when push is off.
 */
class NotificationTester
{
    public const TARGETS = ['device', 'devices', 'in_app', 'email'];

    public function __construct(
        private NotificationPreferenceGate $gate,
        private WebPushSender $push,
    ) {}

    /** @return array{ok: bool, message: string} */
    public function send(User $user, string $target, ?int $subscriptionId = null): array
    {
        // A preference saved earlier in this request must count.
        $this->gate->forget($user->getKey());

        return match ($target) {
            'device' => $this->device($user, $subscriptionId),
            'devices' => $this->devices($user),
            'in_app' => $this->inApp($user),
            'email' => $this->email($user),
            default => $this->result(false, 'unknown_target'),
        };
    }

    /** @return array{ok: bool, message: string} */
    private function device(User $user, ?int $subscriptionId): array
    {
        if (! WebPushSender::configured()) {
            return $this->result(false, 'push_not_configured');
        }

        // Only through the user's own relation: an id from the browser can
        // never reach someone else's device.
        $subscription = $subscriptionId === null
            ? null
            : $user->pushSubscriptions()->whereKey($subscriptionId)->first();

        if (! $subscription) {
            return $this->result(false, 'device_not_found');
        }

        $outcome = $this->push->sendToSubscription($subscription, $this->message($user));

        if ($outcome['ok']) {
            return $this->result(true, $this->pushOpen($user, $subscription) ? 'device_accepted' : 'device_accepted_push_off');
        }

        return $outcome['expired']
            ? $this->result(false, 'device_gone')
            : $this->result(false, 'device_failed', ['reason' => $this->reason($outcome)]);
    }

    /** @return array{ok: bool, message: string} */
    private function devices(User $user): array
    {
        if (! WebPushSender::configured()) {
            return $this->result(false, 'push_not_configured');
        }

        $subscriptions = $user->pushSubscriptions()->deliverable()->get();

        if ($subscriptions->isEmpty()) {
            return $this->result(false, 'no_devices');
        }

        $message = $this->message($user);
        $accepted = $subscriptions->filter(fn (PushSubscription $s) => $this->push->sendToSubscription($s, $message)['ok'])->count();

        return $accepted === 0
            ? $this->result(false, 'devices_none_accepted', ['total' => $subscriptions->count()])
            : $this->result(true, 'devices_accepted', ['accepted' => $accepted, 'total' => $subscriptions->count()]);
    }

    /** @return array{ok: bool, message: string} */
    private function inApp(User $user): array
    {
        if (! $this->gate->channelOpen($user, NotificationChannel::InApp)) {
            return $this->result(false, 'in_app_off');
        }

        try {
            $user->notifyNow(new PreferenceTestNotification, ['database']);
        } catch (Throwable $e) {
            report($e);

            return $this->result(false, 'in_app_failed');
        }

        return $this->result(true, 'in_app_sent');
    }

    /** @return array{ok: bool, message: string} */
    private function email(User $user): array
    {
        if (blank($user->email)) {
            return $this->result(false, 'no_email_address');
        }

        if (! $this->gate->channelOpen($user, NotificationChannel::Email)) {
            return $this->result(false, 'email_off');
        }

        $email = (new PreferenceEmail([$this->testPayload()], test: true))->locale(WebPushSender::localeFor($user));

        try {
            $user->notifyNow($email);
        } catch (Throwable $e) {
            report($e);

            return $this->result(false, 'email_failed', ['reason' => Str::limit($e->getMessage(), 120)]);
        }

        return $this->result(true, 'email_sent', ['email' => $user->email]);
    }

    private function pushOpen(User $user, PushSubscription $subscription): bool
    {
        return $this->gate->channelOpen($user, $subscription->channel()) && $subscription->enabled;
    }

    /** @return array<string, mixed> */
    private function message(User $user): array
    {
        return $this->push->buildMessage(
            $user,
            $this->testPayload() + ['tag' => 'test'],
            NotificationCategory::System,
            urgent: false,
            test: true,
        );
    }

    /** @return array{title: array<string, string>, body: array<string, string>, url: string} */
    private function testPayload(): array
    {
        return [
            'title' => [
                'en' => __('notifications-delivery.test.title', [], 'en'),
                'ar' => __('notifications-delivery.test.title', [], 'ar'),
            ],
            'body' => [
                'en' => __('notifications-delivery.test.body', [], 'en'),
                'ar' => __('notifications-delivery.test.body', [], 'ar'),
            ],
            'url' => route('notifications.index'),
        ];
    }

    /** @param  array{status: ?int, error: ?string}  $outcome */
    private function reason(array $outcome): string
    {
        return $outcome['status'] !== null
            ? 'HTTP '.$outcome['status']
            : Str::limit((string) ($outcome['error'] ?? 'unknown'), 120);
    }

    /**
     * @param  array<string, mixed>  $replace
     * @return array{ok: bool, message: string}
     */
    private function result(bool $ok, string $key, array $replace = []): array
    {
        return ['ok' => $ok, 'message' => __('notifications-delivery.tester.'.$key, $replace)];
    }
}
