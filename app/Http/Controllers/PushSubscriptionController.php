<?php

namespace App\Http\Controllers;

use App\Http\Requests\Push\PushSubscriptionRequest;
use App\Http\Requests\Push\RenewPushSubscriptionRequest;
use App\Models\PushSubscription;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * The browser side of a push subscription's life (docs/notifications/architecture.md,
 * HTTP): the page subscribes after a click (store), re-reports it on page loads
 * (sync), unsubscribes (destroyCurrent); the service worker reports a rotated
 * subscription without a session (renew). Renaming, switching and removing other
 * devices is the settings page's job, through the user's own relation.
 *
 * Every lookup is by endpoint hash. The endpoint is the device's secret, so no
 * answer here ever says anything about a row the caller does not own.
 */
class PushSubscriptionController extends Controller
{
    /** This browser's id, so sign-out can drop the device's subscriptions (Logout listener). */
    public const DEVICE_COOKIE = 'hopehr_device';

    private const DEVICE_COOKIE_MINUTES = 60 * 24 * 365 * 5;

    public function store(PushSubscriptionRequest $request): JsonResponse
    {
        if (blank(config('webpush.vapid.public_key')) || blank(config('webpush.vapid.private_key'))) {
            return response()->json(['error' => 'not_configured'], 503);
        }

        $deviceId = $this->deviceId($request);

        try {
            $subscription = $this->upsert($request, $deviceId);
        } catch (UniqueConstraintViolationException) {
            // Two tabs subscribing the same browser at once: the other one won, update its row.
            $subscription = $this->upsert($request, $deviceId);
        }

        return response()->json(['id' => $subscription->id, 'enabled' => $subscription->enabled]);
    }

    public function sync(PushSubscriptionRequest $request): JsonResponse
    {
        $subscription = $request->user()->pushSubscriptions()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($request->validated('endpoint')))
            ->first();

        // Never creates: a subscription the server does not know is enabled only from a click.
        if ($subscription === null) {
            return response()->json(['known' => false, 'id' => null, 'enabled' => false]);
        }

        $deviceId = $this->deviceId($request);

        $subscription->fill($request->subscription())->forceFill([
            'device_id' => $deviceId,
            'last_seen_at' => now(),
        ]);

        if ($request->has('device.standalone')) {
            $subscription->standalone = $request->boolean('device.standalone');
        }

        $subscription->save();

        return response()->json(['known' => true, 'id' => $subscription->id, 'enabled' => $subscription->enabled]);
    }

    public function destroyCurrent(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);

        $deleted = $request->user()->pushSubscriptions()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        return response()->json(['deleted' => $deleted > 0]);
    }

    public function renew(RenewPushSubscriptionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $gone = response()->json(['ok' => false], 404);

        $subscription = PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['old_endpoint']))
            ->first();

        // Not found and wrong secret answer the same, in the same way.
        if ($subscription === null || ! hash_equals((string) $subscription->auth_token, (string) $data['old_auth'])) {
            return $gone;
        }

        $attributes = PushSubscriptionRequest::subscriptionAttributes($data['subscription'], $subscription->content_encoding);

        $taken = PushSubscription::query()
            ->where('endpoint_hash', $attributes['endpoint_hash'])
            ->whereKeyNot($subscription->id)
            ->first();

        if ($taken !== null) {
            // The page already stored the new subscription for the same person: keep that row.
            // Someone else's: never touched from here.
            if ($taken->user_id === $subscription->user_id) {
                $subscription->delete();

                return response()->json(['ok' => true]);
            }

            return $gone;
        }

        $subscription->forceFill($attributes + [
            'failure_count' => 0,
            'last_error' => null,
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['ok' => true]);
    }

    private function upsert(PushSubscriptionRequest $request, string $deviceId): PushSubscription
    {
        $attributes = $request->subscription();
        $subscription = PushSubscription::query()->firstOrNew(['endpoint_hash' => $attributes['endpoint_hash']]);

        // The same browser, now signed in as someone else: it becomes theirs, as a new
        // device, so nothing of the previous person's settings or history carries over.
        if (! $subscription->exists || $subscription->user_id !== $request->user()->id) {
            $subscription->forceFill([
                'user_id' => $request->user()->id,
                'enabled' => true,
                'urgent_only' => false,
                'failure_count' => 0,
                'last_error' => null,
                'last_success_at' => null,
                'last_failure_at' => null,
            ])->fill($request->device());
        } else {
            // Known device: keep the name the person may have given it. Enabling is a
            // click on this device, so it also turns the device back on.
            $subscription->fill(collect($request->device())->except('device_name')->all())
                ->forceFill(['enabled' => true]);
        }

        $subscription->fill($attributes)->forceFill([
            'device_id' => $deviceId,
            'last_seen_at' => now(),
        ])->save();

        return $subscription;
    }

    /** The browser's existing id, or a new one; (re)sent so it lives five years from now. */
    private function deviceId(Request $request): string
    {
        $id = $request->cookie(self::DEVICE_COOKIE);

        if (! is_string($id) || ! Str::isUuid($id)) {
            $id = (string) Str::uuid();
        }

        Cookie::queue(Cookie::make(
            self::DEVICE_COOKIE,
            $id,
            self::DEVICE_COOKIE_MINUTES,
            path: '/',
            secure: null,
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $id;
    }
}
