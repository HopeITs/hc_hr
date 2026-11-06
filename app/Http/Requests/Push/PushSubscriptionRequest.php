<?php

namespace App\Http\Requests\Push;

use App\Models\PushSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * A browser's PushSubscription.toJSON() ({endpoint, expirationTime, keys})
 * plus, on store, what the page could tell about the device. Used by store
 * and sync (docs/notifications/architecture.md, HTTP).
 *
 * The keys are checked for shape only (base64url, plausible length): the push
 * service is the one that rejects a wrong key, and WebPushSender drops the row
 * when it does.
 */
class PushSubscriptionRequest extends FormRequest
{
    /** base64url, with or without padding (browsers differ). */
    public const BASE64URL = '/^[A-Za-z0-9_-]+={0,2}$/';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public static function subscriptionRules(string $prefix = ''): array
    {
        return [
            $prefix.'endpoint' => ['required', 'string', 'max:2048', 'starts_with:https://', 'url:https'],
            $prefix.'expirationTime' => ['nullable', 'integer', 'min:0'],
            $prefix.'keys' => ['required', 'array'],
            // An uncompressed P-256 point is 65 bytes (87 characters), the auth secret 16 (22).
            $prefix.'keys.p256dh' => ['required', 'string', 'min:80', 'max:100', 'regex:'.self::BASE64URL],
            $prefix.'keys.auth' => ['required', 'string', 'min:16', 'max:32', 'regex:'.self::BASE64URL],
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::subscriptionRules() + [
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
            // Free text from the browser: capped and cleaned in device(), not refused.
            'device' => ['nullable', 'array'],
            'device.name' => ['nullable', 'string', 'max:500'],
            'device.type' => ['nullable', 'string', 'max:40'],
            'device.browser' => ['nullable', 'string', 'max:200'],
            'device.platform' => ['nullable', 'string', 'max:200'],
            'device.standalone' => ['nullable', 'boolean'],
        ];
    }

    /** The columns the subscription itself sets (endpoint, keys, expiry). @return array<string, mixed> */
    public function subscription(): array
    {
        return self::subscriptionAttributes($this->validated(), $this->validated('content_encoding'));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function subscriptionAttributes(array $data, ?string $encoding = null): array
    {
        $expires = $data['expirationTime'] ?? null;

        return [
            'endpoint' => $data['endpoint'],
            'endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint']),
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'content_encoding' => $encoding ?: 'aes128gcm',
            'expires_at' => $expires ? CarbonImmutable::createFromTimestampMs((int) $expires) : null,
        ];
    }

    /** What the page told about the device, cut to the column sizes. @return array<string, mixed> */
    public function device(): array
    {
        $device = (array) $this->validated('device', []);
        $type = $device['type'] ?? null;

        return [
            'device_name' => self::clean($device['name'] ?? null, 80) ?? 'Browser',
            'device_type' => in_array($type, PushSubscription::TYPES, true) ? $type : 'desktop',
            'browser' => self::clean($device['browser'] ?? null, 40),
            'platform' => self::clean($device['platform'] ?? null, 40),
            'standalone' => (bool) ($device['standalone'] ?? false),
        ];
    }

    /** Plain text only: no tags, no control characters, single spaces. */
    private static function clean(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\p{C}<>]+/u', ' ', strip_tags($value)) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : Str::limit($value, $max, '');
    }
}
