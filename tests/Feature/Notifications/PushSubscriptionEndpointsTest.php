<?php

namespace Tests\Feature\Notifications;

use App\Http\Controllers\PushSubscriptionController;
use App\Models\City;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The browser side of a push subscription (docs/notifications/architecture.md,
 * HTTP): store upserts by endpoint and hands the device cookie out, sync and
 * destroy only ever touch the caller's own row, and the service worker's renew
 * works without a session but only with the old subscription's auth secret.
 */
class PushSubscriptionEndpointsTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private ?City $city = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.vapid.public_key', 'BPublicKeyForTests');
        config()->set('webpush.vapid.private_key', 'private-key-for-tests');
    }

    public function test_every_endpoint_but_renew_requires_a_session(): void
    {
        $this->postJson(route('push.subscriptions.store'), $this->payload())->assertUnauthorized();
        $this->postJson(route('push.subscriptions.sync'), $this->payload())->assertUnauthorized();
        $this->deleteJson(route('push.subscriptions.destroy-current'), ['endpoint' => $this->endpoint()])->assertUnauthorized();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_store_creates_the_device_then_updates_it_instead_of_adding_a_second(): void
    {
        $user = $this->user();
        $payload = $this->payload();

        $first = $this->actingAs($user)->postJson(route('push.subscriptions.store'), $payload)
            ->assertOk()
            ->assertJsonStructure(['id', 'enabled'])
            ->assertJson(['enabled' => true]);

        $row = PushSubscription::query()->sole();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame(PushSubscription::hashEndpoint($payload['endpoint']), $row->endpoint_hash);
        $this->assertSame('Chrome on Windows', $row->device_name);
        $this->assertSame('desktop', $row->device_type);
        $this->assertSame($first->json('id'), $row->id);

        // Renamed and switched off in the settings, then enabled again from this browser.
        $row->update(['device_name' => 'My office PC', 'enabled' => false]);
        $payload['keys']['auth'] = $this->key(16);

        $this->actingAs($user)->postJson(route('push.subscriptions.store'), $payload)
            ->assertOk()
            ->assertJson(['id' => $row->id, 'enabled' => true]);

        $this->assertDatabaseCount('push_subscriptions', 1);
        $row->refresh();
        $this->assertSame('My office PC', $row->device_name);
        $this->assertSame($payload['keys']['auth'], $row->auth_token);
    }

    public function test_store_hands_the_browser_a_device_cookie_and_reuses_an_existing_one(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->postJson(route('push.subscriptions.store'), $this->payload())
            ->assertCookie(PushSubscriptionController::DEVICE_COOKIE);

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === PushSubscriptionController::DEVICE_COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertGreaterThan(now()->addYears(4)->timestamp, $cookie->getExpiresTime());

        $existing = (string) Str::uuid();
        $second = $this->actingAs($user)->withCredentials()->withCookie(PushSubscriptionController::DEVICE_COOKIE, $existing)
            ->postJson(route('push.subscriptions.store'), $this->payload())
            ->assertCookie(PushSubscriptionController::DEVICE_COOKIE, $existing);

        $this->assertSame($existing, PushSubscription::query()->findOrFail($second->json('id'))->device_id);
    }

    public function test_store_moves_an_endpoint_to_the_person_now_signed_in_with_fresh_settings(): void
    {
        $before = $this->user();
        $now = $this->user();
        $payload = $this->payload();

        $this->actingAs($before)->postJson(route('push.subscriptions.store'), $payload)->assertOk();
        PushSubscription::query()->sole()->forceFill([
            'device_name' => 'Previous person',
            'urgent_only' => true,
            'enabled' => false,
            'failure_count' => 3,
        ])->save();

        $this->actingAs($now)->postJson(route('push.subscriptions.store'), $payload)->assertOk();

        $row = PushSubscription::query()->sole();
        $this->assertSame($now->id, $row->user_id);
        $this->assertSame('Chrome on Windows', $row->device_name);
        $this->assertFalse($row->urgent_only);
        $this->assertTrue($row->enabled);
        $this->assertSame(0, $row->failure_count);
    }

    public function test_store_refuses_anything_but_an_https_endpoint_and_base64url_keys(): void
    {
        $user = $this->user();

        foreach ([
            'endpoint' => ['endpoint' => 'http://push.example.test/abc'],
            'endpoint ' => ['endpoint' => 'javascript:alert(1)'],
            'endpoint  ' => ['endpoint' => 'https://push.example.test/'.str_repeat('a', 2048)],
            'keys.p256dh' => ['keys' => ['p256dh' => str_repeat('+', 87), 'auth' => $this->key(16)]],
            'keys.auth' => ['keys' => ['p256dh' => $this->key(65), 'auth' => 'not/base64url!!']],
        ] as $field => $override) {
            $this->actingAs($user)->postJson(route('push.subscriptions.store'), array_replace($this->payload(), $override))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(trim($field));
        }

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_device_details_are_cleaned_and_capped_not_trusted(): void
    {
        $this->actingAs($this->user())->postJson(route('push.subscriptions.store'), $this->payload([
            'name' => '<b>'.str_repeat('Phone ', 40)."</b>\u{0007}",
            'type' => 'fridge',
            'browser' => '<script>x</script>Chrome',
            'platform' => str_repeat('P', 100),
        ]))->assertOk();

        $row = PushSubscription::query()->sole();
        $this->assertLessThanOrEqual(80, mb_strlen($row->device_name));
        $this->assertStringNotContainsString('<', $row->device_name);
        $this->assertSame('desktop', $row->device_type);
        $this->assertSame('xChrome', $row->browser);
        $this->assertSame(40, mb_strlen($row->platform));
    }

    public function test_store_says_not_configured_without_a_vapid_key_pair(): void
    {
        config()->set('webpush.vapid.public_key', null);

        $this->actingAs($this->user())->postJson(route('push.subscriptions.store'), $this->payload())
            ->assertStatus(503)
            ->assertExactJson(['error' => 'not_configured']);

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_sync_never_creates_and_only_touches_the_callers_own_row(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $payload = $this->payload();

        $this->actingAs($other)->postJson(route('push.subscriptions.sync'), $payload)
            ->assertOk()
            ->assertJson(['known' => false]);
        $this->assertDatabaseCount('push_subscriptions', 0);

        $this->actingAs($owner)->postJson(route('push.subscriptions.store'), $payload)->assertOk();
        $row = PushSubscription::query()->sole();
        $row->forceFill(['last_seen_at' => now()->subMonth()])->save();

        // Someone else reporting the same endpoint learns nothing and changes nothing.
        $this->actingAs($other)->postJson(route('push.subscriptions.sync'), array_replace($payload, ['keys' => ['p256dh' => $this->key(65), 'auth' => $this->key(16)]]))
            ->assertOk()
            ->assertExactJson(['known' => false, 'id' => null, 'enabled' => false]);
        $this->assertSame($owner->id, $row->fresh()->user_id);
        $this->assertSame($payload['keys']['auth'], $row->fresh()->auth_token);

        $newAuth = $this->key(16);
        $this->actingAs($owner)->postJson(route('push.subscriptions.sync'), array_replace($payload, ['keys' => ['p256dh' => $payload['keys']['p256dh'], 'auth' => $newAuth]]))
            ->assertOk()
            ->assertExactJson(['known' => true, 'id' => $row->id, 'enabled' => true]);

        $row->refresh();
        $this->assertSame($newAuth, $row->auth_token);
        $this->assertTrue($row->last_seen_at->isToday());
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_destroy_current_deletes_only_the_callers_own_row(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $payload = $this->payload();

        $this->actingAs($owner)->postJson(route('push.subscriptions.store'), $payload)->assertOk();

        $this->actingAs($other)->deleteJson(route('push.subscriptions.destroy-current'), ['endpoint' => $payload['endpoint']])
            ->assertOk()
            ->assertJson(['deleted' => false]);
        $this->assertDatabaseCount('push_subscriptions', 1);

        $this->actingAs($owner)->deleteJson(route('push.subscriptions.destroy-current'), ['endpoint' => $payload['endpoint']])
            ->assertOk()
            ->assertJson(['deleted' => true]);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_renew_needs_the_old_auth_secret_and_no_session(): void
    {
        $owner = $this->user();
        $old = $this->payload();
        $this->actingAs($owner)->postJson(route('push.subscriptions.store'), $old)->assertOk();
        $row = PushSubscription::query()->sole();

        // No session, no user; the CSRF middleware lets it through without a token.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->assertContains('push/renew', app(PreventRequestForgery::class)->getExcludedPaths());
        $this->assertGuest();

        $new = $this->payload();
        $body = fn (string $auth, string $endpoint) => [
            'old_endpoint' => $endpoint,
            'old_auth' => $auth,
            'subscription' => ['endpoint' => $new['endpoint'], 'expirationTime' => null, 'keys' => $new['keys']],
        ];

        // Wrong secret and unknown endpoint answer the same way.
        $wrong = $this->postJson(route('push.renew'), $body($this->key(16), $old['endpoint']))->assertNotFound();
        $unknown = $this->postJson(route('push.renew'), $body($old['keys']['auth'], 'https://push.example.test/nope'))->assertNotFound();
        $this->assertSame($wrong->json(), $unknown->json());
        $this->assertSame($old['endpoint'], $row->fresh()->endpoint);

        $this->postJson(route('push.renew'), $body($old['keys']['auth'], $old['endpoint']))
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $row->refresh();
        $this->assertSame($new['endpoint'], $row->endpoint);
        $this->assertSame(PushSubscription::hashEndpoint($new['endpoint']), $row->endpoint_hash);
        $this->assertSame($new['keys']['auth'], $row->auth_token);
        $this->assertSame($owner->id, $row->user_id);
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_renew_never_takes_over_an_endpoint_that_belongs_to_someone_else(): void
    {
        $owner = $this->user();
        $victim = $this->user();
        $mine = $this->payload();
        $theirs = $this->payload();

        $this->actingAs($owner)->postJson(route('push.subscriptions.store'), $mine)->assertOk();
        $this->actingAs($victim)->postJson(route('push.subscriptions.store'), $theirs)->assertOk();

        $this->postJson(route('push.renew'), [
            'old_endpoint' => $mine['endpoint'],
            'old_auth' => $mine['keys']['auth'],
            'subscription' => ['endpoint' => $theirs['endpoint'], 'keys' => $this->payload()['keys']],
        ])->assertNotFound();

        $this->assertSame($victim->id, PushSubscription::query()->where('endpoint_hash', PushSubscription::hashEndpoint($theirs['endpoint']))->sole()->user_id);
        $this->assertDatabaseCount('push_subscriptions', 2);
    }

    public function test_the_head_carries_the_push_meta_tags_for_a_signed_in_user_only(): void
    {
        $user = $this->user();
        NotificationPreference::for($user)->fill(['sound' => false])->save();

        $html = $this->actingAs($user)->get(route('settings.app'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<meta name="hc-vapid-key" content="BPublicKeyForTests"#', $html);
        $this->assertMatchesRegularExpression('#<meta name="hc-notif-sound" content="0"#', $html);
        $this->assertMatchesRegularExpression('#<meta name="csrf-token" content="[^"]+"#', $html);

        preg_match('#<meta name="hc-push-routes" content="([^"]+)"#', $html, $match);
        $routes = json_decode(html_entity_decode($match[1] ?? ''), true);
        $this->assertSame([
            'store' => route('push.subscriptions.store'),
            'sync' => route('push.subscriptions.sync'),
            'destroy' => route('push.subscriptions.destroy-current'),
            'renew' => route('push.renew'),
        ], $routes);

        $this->assertMatchesRegularExpression('#<meta name="hc-notif-sound" content="1"#', $this->actingAs($this->user())->get(route('settings.app'))->getContent());

        auth()->logout();
        $this->assertStringNotContainsString('hc-vapid-key', $this->get(route('login'))->getContent());
    }

    private function user(): User
    {
        $this->city ??= $this->makeCity('ALP', '001');

        return $this->makeUser($this->city, 'employee');
    }

    /** Random bytes as base64url without padding, the way browsers send keys. */
    private function key(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function endpoint(): string
    {
        return 'https://fcm.googleapis.com/fcm/send/'.Str::random(40);
    }

    /** @param array<string, mixed> $device */
    private function payload(array $device = []): array
    {
        return [
            'endpoint' => $this->endpoint(),
            'expirationTime' => null,
            'keys' => ['p256dh' => $this->key(65), 'auth' => $this->key(16)],
            'content_encoding' => 'aes128gcm',
            'device' => $device + ['name' => 'Chrome on Windows', 'type' => 'desktop', 'browser' => 'Chrome', 'platform' => 'Windows', 'standalone' => false],
        ];
    }
}
