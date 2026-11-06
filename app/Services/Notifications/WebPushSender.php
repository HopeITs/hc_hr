<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the JSON the service worker shows and hands it to the push service,
 * one device at a time, then keeps each subscription's health up to date
 * from the answer (docs/notifications/architecture.md, pipeline step 5).
 *
 * "ok" only ever means the push service accepted the message: whether the
 * device then shows it is between the browser and the OS.
 */
class WebPushSender
{
    public function __construct(private NotificationPreferenceGate $gate) {}

    /** True when this environment has a VAPID pair to sign with. */
    public static function configured(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'))
            && filled(config('webpush.vapid.subject'));
    }

    /**
     * Send a built message (buildMessage()) to one device and record the
     * outcome on it: gone (404/410) deletes the row, any other failure
     * counts towards webpush.max_failures, success resets the count.
     *
     * @param  array<string, mixed>  $message
     * @return array{ok: bool, status: ?int, error: ?string, expired: bool}
     */
    public function sendToSubscription(PushSubscription $subscription, array $message): array
    {
        if (! self::configured()) {
            return ['ok' => false, 'status' => null, 'error' => 'not_configured', 'expired' => false];
        }

        $result = app(PushClient::class)->send(
            $subscription,
            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            [
                'TTL' => (int) config('webpush.ttl'),
                'urgency' => ! empty($message['urgent']) ? 'high' : 'normal',
            ],
        );

        $expired = $result['expired'] || in_array($result['status'], [404, 410], true);

        if ($result['ok']) {
            $subscription->forceFill([
                'last_success_at' => now(),
                'failure_count' => 0,
                'last_error' => null,
            ])->save();
        } elseif ($expired) {
            $subscription->delete();
        } else {
            $failures = $subscription->failure_count + 1;

            if ($failures >= (int) config('webpush.max_failures', 5)) {
                $subscription->delete();
            } else {
                $subscription->forceFill([
                    'failure_count' => $failures,
                    'last_failure_at' => now(),
                    'last_error' => Str::limit((string) ($result['error'] ?? 'push_failed'), 250, ''),
                ])->save();
            }
        }

        return [
            'ok' => (bool) $result['ok'],
            'status' => $result['status'],
            'error' => $result['ok'] ? null : ($result['error'] ?? 'push_failed'),
            'expired' => $expired,
        ];
    }

    /**
     * Send one notification to every device of $user that should get it.
     * Returns how many devices the push service accepted it for.
     *
     * @param  array{title?: mixed, body?: mixed, url?: ?string, tag?: ?string}  $payload
     */
    public function sendToUser(User $user, array $payload, NotificationCategory $category, bool $urgent): int
    {
        return $this->sendToUserForCategories($user, $payload, [$category], $urgent);
    }

    /**
     * Same as sendToUser() for a summary covering several categories: a
     * device gets it when at least one of them is allowed on its channel.
     *
     * @param  array{title?: mixed, body?: mixed, url?: ?string, tag?: ?string}  $payload
     * @param  array<int, NotificationCategory>  $categories
     */
    public function sendToUserForCategories(User $user, array $payload, array $categories, bool $urgent): int
    {
        if (! self::configured() || $categories === []) {
            return 0;
        }

        $category = count(array_unique(array_map(fn ($c) => $c->value, $categories))) === 1
            ? $categories[0]
            : NotificationCategory::System;
        $message = $this->buildMessage($user, $payload, $category, $urgent);
        $accepted = 0;

        foreach ($this->devicesFor($user, $categories, $urgent) as $subscription) {
            if ($this->sendToSubscription($subscription, $message)['ok']) {
                $accepted++;
            }
        }

        return $accepted;
    }

    /**
     * The devices a notification may reach: enabled and not expired, the
     * device's own channel (desktop / mobile) allowed for one of the
     * categories, and "urgent only" devices only for urgent ones.
     *
     * @param  array<int, NotificationCategory>  $categories
     * @return Collection<int, PushSubscription>
     */
    public function devicesFor(User $user, array $categories, bool $urgent): Collection
    {
        return $user->pushSubscriptions()->deliverable()->get()
            ->filter(function (PushSubscription $subscription) use ($user, $categories, $urgent) {
                if ($subscription->urgent_only && ! $urgent) {
                    return false;
                }

                foreach ($categories as $category) {
                    if ($this->gate->allows($user, $category, $subscription->channel())) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * The JSON the service worker receives, in the person's language. With
     * previews off nothing from the notification is shown (no names, no HR
     * data), only the app name and a generic line; with sound off it is silent.
     *
     * @param  array{title?: mixed, body?: mixed, url?: ?string, tag?: ?string}  $payload
     * @return array{title: string, body: string, url: string, tag: string, icon: string, badge: string, silent: bool, urgent: bool, category: string, test: bool}
     */
    public function buildMessage(User $user, array $payload, NotificationCategory $category, bool $urgent, bool $test = false): array
    {
        $preference = $this->gate->preferenceFor($user);
        $locale = self::localeFor($user);

        if ($preference->previews) {
            $title = self::text($payload['title'] ?? null, $locale) ?: __('pwa.app-name', [], $locale);
            $body = self::text($payload['body'] ?? null, $locale);
        } else {
            $title = __('pwa.app-name', [], $locale);
            $body = __('notifications-delivery.push.generic_body', [], $locale);
        }

        return [
            'title' => Str::limit($title, 120),
            // Web Push payloads are capped at ~4 KB once encrypted.
            'body' => Str::limit($body, 400),
            'url' => self::safeUrl($payload['url'] ?? null) ?? route('notifications.index'),
            'tag' => 'hopehr-'.($payload['tag'] ?? Str::random(12)),
            'icon' => '/favicons/android-chrome-192x192.png',
            'badge' => '/favicons/favicon-32x32.png',
            'silent' => ! $preference->sound,
            'urgent' => $urgent,
            'category' => $category->value,
            'test' => $test,
        ];
    }

    public static function localeFor(User $user): string
    {
        return $user->preferredLocale() ?? config('app.locale');
    }

    /**
     * One string out of a payload field that may be {en, ar} or a plain
     * string (a few notification classes still store plain text).
     */
    public static function text(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            $value = $value[$locale] ?? $value[config('app.fallback_locale')] ?? $value['en'] ?? reset($value);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A link a push or an email may open: same-site only (relative, or on
     * the app's own host), the same rule the bell applies before redirecting.
     */
    public static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\')) {
            return url($url);
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        $ownHosts = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            app()->runningInConsole() ? null : request()->getHost(),
        ]);

        return in_array($scheme, ['http', 'https'], true)
            && is_string($host)
            && in_array(strtolower($host), array_map('strtolower', $ownHosts), true)
                ? $url
                : null;
    }
}
