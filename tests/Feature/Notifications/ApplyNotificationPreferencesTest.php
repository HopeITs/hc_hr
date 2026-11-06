<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Jobs\DeliverNotificationOutbox;
use App\Models\NotificationOutboxEntry;
use App\Models\NotificationPreference;
use App\Notifications\ContractsAutoRenewedSummary;
use App\Notifications\PreferenceEmail;
use App\Notifications\TestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApplyNotificationPreferencesTest extends TestCase
{
    use NotificationFixtures, RefreshDatabase;

    public function test_it_is_registered_once(): void
    {
        $listeners = Event::getRawListeners()[NotificationSending::class] ?? [];

        $this->assertCount(1, array_filter($listeners, fn ($l) => is_array($l) && str_contains($l[0], 'ApplyNotificationPreferences')));
    }

    public function test_in_app_off_cancels_the_database_row(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, ['in_app' => false]);

        $user->notify(new TestNotification);

        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_category_in_app_cell_off_cancels_the_database_row(): void
    {
        $user = $this->makeNotifiedUser();
        $preference = NotificationPreference::for($user);
        $preference->setCategoryChannel(NotificationCategory::System, NotificationChannel::InApp, false);
        $preference->save();

        $user->notify(new TestNotification);

        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_defaults_keep_the_database_row(): void
    {
        $user = $this->makeNotifiedUser();

        $user->notify(new TestNotification);

        $this->assertSame(1, $user->notifications()->count());
        // No device and no email for this category: nothing to fan out.
        $this->assertSame(0, NotificationOutboxEntry::query()->count());
    }

    public function test_push_and_email_rows_are_written_and_the_job_is_dispatched(): void
    {
        Queue::fake();
        $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $preference = NotificationPreference::for($user);
        $preference->setCategoryChannel(NotificationCategory::System, NotificationChannel::Email, true);
        $preference->save();

        $user->notify(new TestNotification);

        $notificationId = $user->notifications()->value('id');
        $rows = NotificationOutboxEntry::query()->orderBy('channel')->get();

        $this->assertSame(['email', 'push'], $rows->pluck('channel')->all());
        $this->assertSame([$notificationId, $notificationId], $rows->pluck('notification_id')->all());
        $this->assertSame('system', $rows[0]->category);
        $this->assertSame('Test Notification', $rows[0]->payload['title']['en']);
        Queue::assertPushed(DeliverNotificationOutbox::class, fn ($job) => count($job->entryIds) === 2);
    }

    public function test_push_row_is_written_even_when_in_app_is_off(): void
    {
        Queue::fake();
        $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $this->setPreference($user, ['in_app' => false]);

        $user->notify(new TestNotification);

        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(1, NotificationOutboxEntry::query()->where('channel', 'push')->count());
    }

    public function test_quiet_hours_defer_the_row_and_dispatch_nothing(): void
    {
        Queue::fake();
        $this->configurePush();
        $this->travelTo(now()->setTimezone('UTC')->setTime(23, 0));
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $this->setPreference($user, ['quiet_hours' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'timezone' => 'UTC']);

        $user->notify(new TestNotification);

        $row = NotificationOutboxEntry::query()->sole();
        $this->assertTrue($row->deliver_after->isFuture());
        Queue::assertNothingPushed();
    }

    public function test_master_off_writes_nothing_and_cancels_in_app(): void
    {
        Queue::fake();
        $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $this->setPreference($user, ['enabled' => false]);

        $user->notify(new TestNotification);

        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame(0, NotificationOutboxEntry::query()->count());
    }

    public function test_mail_channel_is_cancelled_when_email_is_not_allowed(): void
    {
        $user = $this->makeNotifiedUser();
        $preference = NotificationPreference::for($user);
        $preference->setCategoryChannel(NotificationCategory::Contracts, NotificationChannel::Email, false);
        $preference->save();

        $user->notify(new ContractsAutoRenewedSummary(collect()));

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(0, $this->sentMailCount());
    }

    public function test_notification_that_mails_itself_keeps_mailing_and_gets_no_outbox_email(): void
    {
        $user = $this->makeNotifiedUser();

        $user->notify(new ContractsAutoRenewedSummary(collect()));

        $this->assertSame(1, $this->sentMailCount());
        $this->assertSame(0, NotificationOutboxEntry::query()->where('channel', 'email')->count());
    }

    public function test_the_same_notification_never_writes_two_rows(): void
    {
        Queue::fake();
        $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $notification = new TestNotification;
        $notification->id = '9a1f1e1e-0000-4000-8000-000000000001';

        // A retried queued notification keeps its id.
        event(new NotificationSending($user, $notification, 'database'));
        event(new NotificationSending($user, $notification, 'database'));

        $this->assertSame(1, NotificationOutboxEntry::query()->count());
    }

    public function test_bypassing_notifications_are_left_alone(): void
    {
        $user = $this->makeNotifiedUser();
        $this->setPreference($user, ['enabled' => false]);

        $user->notifyNow(new PreferenceEmail([['title' => 'Hi', 'body' => 'There', 'url' => null]]));

        $this->assertSame(1, $this->sentMailCount());
    }

    private function sentMailCount(): int
    {
        return count($this->app->make('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
    }
}
