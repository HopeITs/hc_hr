# Notification preferences & Web Push — architecture

Status: in implementation (2026-11-03). This file is the contract the pieces
are built against; keep it true when you change one of them.

## What already existed (kept as is)

- Every notification in `app/Notifications` sends through the `database`
  channel (two contract summaries also through `mail`). The payload is
  `{title: {en, ar}, body: {en, ar}, url?, subject?}` (title/body may also be
  plain strings in a few classes — always resolve defensively).
- The bell (`Livewire\Notifications\Counter`) polls every 10s and dispatches
  the browser event `notif-arrived` when the unread count grows.
- `public/sw.js` (D2-PWA) caches only static assets. It is registered from
  `partials/head.blade.php` **only in a secure context** (https or localhost).
- Queue: `database`. Scheduler: `routes/console.php`.
- `users.locale` holds the language mail/push should use (`preferredLocale()`).

None of the notification classes are edited: preferences are applied around
them by a listener on Laravel's `NotificationSending` event.

## Data (migration `2026_11_03_000001_create_notification_preferences_tables`)

| Table | Model | Purpose |
|---|---|---|
| `notification_preferences` | `App\Models\NotificationPreference` | one row per user; no row = defaults (`NotificationPreference::for($user)`) |
| `push_subscriptions` | `App\Models\PushSubscription` | one row per browser/installed app; its own `enabled`, `urgent_only`, name, type |
| `notification_outbox` | `App\Models\NotificationOutboxEntry` | push/email still to send and the earliest time (`deliver_after`) |

Enums: `App\Enums\NotificationChannel` (`in_app`, `desktop`, `mobile`,
`email`) and `App\Enums\NotificationCategory` (class → category map,
`label()`, `description()`, `icon()`, `urgent()`, `defaults()`).

Categories exist only for things the app sends: leave_actions, leave_updates,
contracts, employees, checklists, files, organization, security, system.
There is **no attendance or payroll module**, so no category for them.

## Delivery rules (one place: `App\Services\Notifications\NotificationPreferenceGate`)

`allows(User, NotificationCategory, NotificationChannel): bool`
= master `enabled` AND the channel's global switch AND the category's cell.

- `in_app` false → the `database` channel is cancelled (listener returns false).
- `email` → for notifications that already have `mail` in `via()`, the mail
  channel is cancelled when not allowed; for the rest an outbox email row is
  written when allowed (never both).
- `desktop`/`mobile` → one outbox `push` row per notification when either is
  allowed (and only when push is configured and the person has a deliverable
  device, so the outbox does not fill up for people without one); at send
  time each device is filtered by its own channel
  (`PushSubscription::channel()`), its `enabled`, and `urgent_only`.
- The global `email` switch defaults to **on**: the category defaults decide
  (email only for contracts and security), so the contract summaries that
  mailed before preferences existed keep mailing.

`deliverAt(User, NotificationCategory, ?CarbonInterface $now): CarbonInterface`
in the user's time zone (`timezoneName()`):
1. urgent category + `urgent_bypass` → now (ignores quiet hours, working days and the digest).
2. outside `workingDays()` or inside quiet hours (`quiet_start`→`quiet_end`,
   may wrap midnight) → the next moment that is a working day and outside quiet hours.
3. frequency `hourly` → the next top of the hour; `daily` → next `digest_time`
   (then pushed past quiet hours / non-working days by rule 2).
Returned in UTC/app tz as a Carbon instance. An empty working-days list is
treated as "every day" (otherwise nothing would ever go out).

`windowOpensAt(User, NotificationCategory, ?CarbonInterface $now)` = rules 1–2
only. The dispatcher re-checks it at send time; re-applying the digest to a
row already waiting for it would postpone it forever.

## Pipeline

1. `App\Listeners\ApplyNotificationPreferences::apply()` on `NotificationSending`
   (registered in `AppServiceProvider`; not `handle()`, so event discovery
   does not register it a second time). Only `User` notifiables. Notifications
   implementing `App\Notifications\Contracts\BypassesPreferences` are left alone.
   - channel `database`: fan out (outbox rows), then return `false` when in-app is not allowed.
   - channel `mail`: return gate result for `email`.
2. Outbox rows carry `notification_id` = the notification's uuid; unique
   `(notification_id, channel)` prevents duplicates on retries.
3. Due-now rows: `App\Jobs\DeliverNotificationOutbox` dispatched after commit.
   Deferred rows: `php artisan notifications:dispatch-due` (every minute) sends
   what is due, grouping several rows of one user+channel into one summary.
4. Before sending, a row whose database notification was already **read** in the
   app is skipped (no late push for something already seen).
5. `App\Services\Notifications\WebPushSender` (minishlink/web-push, VAPID):
   404/410 → subscription deleted; other failures → `failure_count++`, dropped
   at `webpush.max_failures`; success → `last_success_at`, count reset.
6. `php artisan push:prune` (daily) removes expired / stale subscriptions and
   old sent outbox rows (30 days).
7. Sign-out (`Illuminate\Auth\Events\Logout`) deletes this device's
   subscriptions for that user, found by the `hopehr_device` cookie.

### Push payload (JSON the service worker receives)

```json
{ "title": "…", "body": "…", "url": "https://…", "tag": "hopehr-<id>",
  "icon": "/favicons/android-chrome-192x192.png", "badge": "/favicons/favicon-32x32.png",
  "silent": false, "urgent": false, "category": "leave_updates", "test": false }
```
Previews off → title = app name, body = generic text (no names, no HR data).
Sound off → `silent: true`. Text in the user's `preferredLocale()`.

### Test notification

`App\Services\Notifications\NotificationTester::send(User $user, string $target, ?int $subscriptionId): array{ok: bool, message: string}`
targets: `device` (one subscription of this user), `devices` (all), `in_app`, `email`.
Real sends only; the message reports what the push service / mailer answered.
`in_app` and `email` respect the master and channel switches (and fail with a
clear message when off) but not the category matrix; `in_app` sends
`PreferenceTestNotification` (database only, no push/email fan-out). Device
tests go out whatever the switches say: they check the connection.

The push client sits behind `App\Services\Notifications\PushClient` (real:
`MinishlinkPushClient`); tests bind a fake. On Windows PHP, OpenSSL needs
`OPENSSL_CONF` (PHP's `extras/ssl/openssl.cnf`) set before PHP starts, or EC
keys cannot be created: `webpush:vapid` and sending (payload encryption) both
fail without it. Linux servers are not affected.

## HTTP (routes/notifications.php inside auth + locale; routes/push-public.php outside)

| Method | URI | Name | |
|---|---|---|---|
| POST | `push/subscriptions` | `push.subscriptions.store` | upsert by endpoint hash; sets `hopehr_device` cookie |
| POST | `push/subscriptions/sync` | `push.subscriptions.sync` | touch `last_seen_at` if this endpoint belongs to the user; never creates |
| DELETE | `push/subscriptions/current` | `push.subscriptions.destroy-current` | body `{endpoint}`; deletes this user's row |
| POST | `/push/renew` | `push.renew` | SW `pushsubscriptionchange`; proves ownership with the old endpoint + old auth secret; CSRF-exempt, throttled |

Device management (rename, enable, urgent-only, remove, test) is done by the
Livewire settings page, always through `auth()->user()->pushSubscriptions()`.

## Browser side

- `resources/js/push-notifications.js` — Alpine component `pushDevice`
  (permission state, subscribe/unsubscribe after a click only) + page-load
  sync + in-app sound on `notif-arrived`.
- Head meta: `hc-vapid-key`, `hc-push-routes` (JSON of the URLs above),
  `hc-notif-sound` ("1"/"0").
- `public/sw.js`: `push`, `notificationclick`, `pushsubscriptionchange`.

## Settings page

`settings/notifications` → `App\Livewire\Settings\NotificationSettings`,
view `livewire/settings/notifications.blade.php`, strings in
`lang/{en,ar}/notification-settings.php`; delivery-side strings in
`lang/{en,ar}/notifications-delivery.php`.

## Environment

```
VAPID_SUBJECT=mailto:it@example.org   # or the site URL
VAPID_PUBLIC_KEY=…                    # php artisan webpush:vapid
VAPID_PRIVATE_KEY=…
```
Requires HTTPS in production (push and service workers need a secure context),
a running queue worker and the scheduler (`schedule:run` every minute).
