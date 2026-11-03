<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationOutboxEntry;
use App\Notifications\PreferenceEmail;
use App\Notifications\TestNotification;
use App\Services\Notifications\OutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OutboxDispatcherTest extends TestCase
{
    use NotificationFixtures, RefreshDatabase;

    public function test_an_instant_notification_reaches_the_device_end_to_end(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $device = $this->makeDevice($user);

        $user->notify(new TestNotification);

        $this->assertSame([$device->id], $push->sentTo());
        $this->assertSame('Test Notification', $push->sent[0]['message']['title']);
        $this->assertNotNull(NotificationOutboxEntry::query()->sole()->sent_at);
    }

    public function test_a_row_already_read_in_the_app_is_skipped(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $entry = $this->makeOutboxEntry($user);
        $this->makeDatabaseNotification($user, $entry->notification_id, read: true);

        $this->dispatcher()->dispatchDue();

        $this->assertSame([], $push->sent);
        $entry->refresh();
        $this->assertNotNull($entry->sent_at);
        $this->assertSame('read_in_app', $entry->error);
    }

    public function test_several_rows_of_one_person_become_one_summary_push(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $entries = collect([1, 2, 3])->map(fn () => $this->makeOutboxEntry($user));

        $this->dispatcher()->dispatchDue();

        $this->assertCount(1, $push->sent);
        $this->assertSame('You have 3 new notifications', $push->sent[0]['message']['title']);
        $this->assertSame(route('notifications.index'), $push->sent[0]['message']['url']);
        $this->assertSame('hopehr-summary', $push->sent[0]['message']['tag']);
        $entries->each(fn ($e) => $this->assertNotNull($e->fresh()->sent_at));
    }

    public function test_urgent_rows_are_not_folded_into_the_summary(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $this->makeOutboxEntry($user);
        $this->makeOutboxEntry($user);
        $this->makeOutboxEntry($user, ['category' => 'security', 'urgent' => true]);

        $this->dispatcher()->dispatchDue();

        $this->assertCount(2, $push->sent);
        $this->assertTrue($push->sent[0]['message']['urgent']);
        $this->assertSame('You have 2 new notifications', $push->sent[1]['message']['title']);
    }

    public function test_rows_not_yet_due_are_left_alone(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $entry = $this->makeOutboxEntry($user, ['deliver_after' => now()->addHour()]);

        $this->dispatcher()->dispatchDue();

        $this->assertSame([], $push->sent);
        $this->assertNull($entry->fresh()->sent_at);
    }

    public function test_preferences_turned_off_after_the_row_was_written_are_respected(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $entry = $this->makeOutboxEntry($user);
        $this->setPreference($user, ['enabled' => false]);

        $this->dispatcher()->dispatchDue();

        $this->assertSame([], $push->sent);
        $this->assertSame('preferences_changed', $entry->fresh()->error);
    }

    public function test_quiet_hours_set_after_the_row_was_written_move_it_later(): void
    {
        $push = $this->configurePush();
        $this->travelTo(now()->setTimezone('UTC')->setTime(23, 0));
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $entry = $this->makeOutboxEntry($user);
        $this->setPreference($user, ['quiet_hours' => true, 'quiet_start' => '22:00', 'quiet_end' => '07:00', 'timezone' => 'UTC']);

        $this->dispatcher()->dispatchDue();

        $this->assertSame([], $push->sent);
        $entry->refresh();
        $this->assertNull($entry->sent_at);
        $this->assertTrue($entry->deliver_after->isFuture());
    }

    public function test_email_rows_are_sent_as_one_email_in_the_persons_language(): void
    {
        Notification::fake();
        $user = $this->makeNotifiedUser('ar');
        $this->setPreference($user, ['categories' => ['leave_updates' => ['email' => true]]]);
        $this->makeOutboxEntry($user, ['channel' => NotificationOutboxEntry::EMAIL]);
        $this->makeOutboxEntry($user, ['channel' => NotificationOutboxEntry::EMAIL]);

        $this->dispatcher()->dispatchDue();

        Notification::assertSentToTimes($user, PreferenceEmail::class, 1);
        Notification::assertSentTo($user, PreferenceEmail::class, function (PreferenceEmail $mail) use ($user) {
            $this->assertSame('ar', $mail->locale);
            $message = $mail->toMail($user);

            return count($mail->payloads) === 2 && str_contains((string) $message->subject, '2');
        });
        $this->assertSame(2, NotificationOutboxEntry::query()->whereNotNull('sent_at')->count());
    }

    public function test_the_command_sends_what_is_due(): void
    {
        $push = $this->configurePush();
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $this->makeOutboxEntry($user);

        $this->artisan('notifications:dispatch-due')->assertSuccessful();

        $this->assertCount(1, $push->sent);
    }

    public function test_push_rows_fail_cleanly_when_push_is_not_configured(): void
    {
        $user = $this->makeNotifiedUser();
        $this->makeDevice($user);
        $entry = $this->makeOutboxEntry($user);

        $this->dispatcher()->dispatchDue();

        $entry->refresh();
        $this->assertNotNull($entry->failed_at);
        $this->assertSame('push_not_configured', $entry->error);
    }

    private function dispatcher(): OutboxDispatcher
    {
        return $this->app->make(OutboxDispatcher::class);
    }
}
