<?php

namespace Tests\Feature;

use App\Console\Commands\EnsureNextBoardMeeting;
use App\Console\Commands\SendBoardMeetingReminders;
use App\Livewire\Board\Meetings\Index;
use App\Livewire\Board\Meetings\Show;
use App\Models\BoardMeeting;
use App\Models\BoardMeetingAttendee;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\BoardMeetingNotice;
use App\Services\Board\BoardMeetingService;
use App\Services\Board\BoardMinutesView;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The board's meetings: opened by the scheduler at the usual slot, reminded once the day before, moved or
 * cancelled by the chair, attended and minuted by the secretary, with the members reading what is published.
 */
class BoardMeetingsTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private User $chair;

    private User $secretary;

    private User $member;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $city = $this->makeCity('SYR', '000');
        $board = fn (string $name) => $this->makeEmployee($city, $name, ['is_board_member' => true]);

        $this->chair = $this->makeUser($city, 'board-chair-role', ['board-meetings.manage'], $board('freddy'));
        $this->secretary = $this->makeUser($city, 'board-secretary-role', ['board-meetings.write-minutes'], $board('fadi'));
        $this->member = $this->makeUser($city, 'employee', [], $board('member'));
        $this->outsider = $this->makeUser($city, 'employee', []);
    }

    public function test_the_scheduler_opens_the_thursday_noon_meeting_once_and_a_cancelled_week_stays_cancelled(): void
    {
        // A Monday: the coming Thursday at 12:00 is the slot.
        $monday = CarbonImmutable::parse('2026-12-07 07:30');
        $service = app(BoardMeetingService::class);

        $first = $service->ensureNext($monday);

        $this->assertNotNull($first);
        $this->assertSame('2026-12-10 12:00', $first->starts_at->format('Y-m-d H:i'));
        $this->assertSame(1, $first->number);
        // The three members are expected, nobody else.
        $this->assertSame(3, $first->attendees()->count());

        $this->assertNull($service->ensureNext($monday->addDay()), 'a second run in the same week opens nothing');

        $service->cancel($first, 'Holiday');
        $this->assertNull($service->ensureNext($monday->addDays(2)), 'the chair cancelled that week: it is not opened again');
    }

    public function test_every_other_week_cadence_skips_the_week_after_a_meeting(): void
    {
        config(['board.every_weeks' => 2]);
        $service = app(BoardMeetingService::class);

        $this->assertNotNull($service->ensureNext(CarbonImmutable::parse('2026-12-07 07:30')));
        $this->assertNull($service->ensureNext(CarbonImmutable::parse('2026-12-14 07:30')));
        $this->assertNotNull($service->ensureNext(CarbonImmutable::parse('2026-12-21 07:30')));
    }

    public function test_the_reminder_goes_out_the_day_before_and_only_once(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-12-09 08:00'));
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => CarbonImmutable::parse('2026-12-10 12:00')], notify: false);

        Artisan::call(SendBoardMeetingReminders::class);
        Artisan::call(SendBoardMeetingReminders::class);

        Notification::assertSentToTimes($this->member, BoardMeetingNotice::class, 1);
        Notification::assertSentTo($this->chair, BoardMeetingNotice::class, fn ($n) => $n->kind === BoardMeetingNotice::REMINDER);
        Notification::assertNotSentTo($this->outsider, BoardMeetingNotice::class);
        $this->assertNotNull($meeting->fresh()->reminded_at);
    }

    public function test_moving_a_meeting_tells_the_board_and_earns_a_new_reminder(): void
    {
        Notification::fake();
        $service = app(BoardMeetingService::class);
        $meeting = $service->schedule(['starts_at' => CarbonImmutable::parse('2026-12-10 12:00')], notify: false);
        $meeting->update(['reminded_at' => now()]);

        Livewire::actingAs($this->chair)->test(Show::class, ['meeting' => $meeting])
            ->set('startsAt', '2026-12-11T10:00')
            ->call('saveDetails')
            ->assertHasNoErrors();

        Notification::assertSentTo($this->member, BoardMeetingNotice::class, fn ($n) => $n->kind === BoardMeetingNotice::RESCHEDULED);
        $this->assertNull($meeting->fresh()->reminded_at);
    }

    public function test_cancelling_needs_a_reason_and_only_the_chair_may(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->addDays(3)], notify: false);

        Livewire::actingAs($this->chair)->test(Show::class, ['meeting' => $meeting])
            ->call('cancelMeeting')->assertHasErrors('cancelReason')
            ->set('cancelReason', 'No quorum')->call('cancelMeeting')->assertHasNoErrors();

        $this->assertTrue($meeting->fresh()->isCancelled());

        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])->call('restoreMeeting')->assertForbidden();
        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])->call('cancelMeeting')->assertForbidden();
    }

    public function test_an_absence_request_reaches_the_chair_and_approval_lists_the_member_as_absent(): void
    {
        Notification::fake();
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->addDays(3)], notify: false);

        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])
            ->call('requestAbsence')->assertHasErrors('absenceReason')
            ->set('absenceReason', 'Travelling')->call('requestAbsence')->assertHasNoErrors();

        Notification::assertSentTo($this->chair, BoardMeetingNotice::class, fn ($n) => $n->kind === BoardMeetingNotice::ABSENCE_REQUESTED);

        $row = BoardMeetingAttendee::query()->where('employee_id', $this->member->employee_id)->firstOrFail();
        $this->assertSame('pending', $row->absence_request);
        $this->assertSame(BoardMeetingAttendee::EXPECTED, $row->status);

        // The member cannot decide their own request.
        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])->call('decideAbsence', $row->id, true)->assertForbidden();

        Livewire::actingAs($this->chair)->test(Show::class, ['meeting' => $meeting])->call('decideAbsence', $row->id, true);

        $this->assertSame(BoardMeetingAttendee::ABSENT, $row->fresh()->status);
        Notification::assertSentTo($this->member, BoardMeetingNotice::class, fn ($n) => $n->kind === BoardMeetingNotice::ABSENCE_DECIDED);
    }

    public function test_only_a_current_member_can_ask_to_be_absent_and_only_before_the_meeting(): void
    {
        $service = app(BoardMeetingService::class);
        $ahead = $service->schedule(['starts_at' => now()->addDays(3)], notify: false);
        $past = $service->schedule(['starts_at' => now()->subDays(3)], notify: false);

        Livewire::actingAs($this->outsider)->test(Show::class, ['meeting' => $ahead])->assertForbidden();

        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $past])
            ->set('absenceReason', 'x')->call('requestAbsence')->assertForbidden();
    }

    public function test_a_member_who_left_the_board_no_longer_opens_it(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->addDays(3)], notify: false);
        Employee::query()->whereKey($this->member->employee_id)->update(['board_member_end_at' => now()->subDay()]);

        $this->actingAs($this->member->fresh())->get(route('board.meetings.show', $meeting))->assertForbidden();
        $this->actingAs($this->member->fresh())->get(route('board.meetings.index'))->assertForbidden();
    }

    public function test_the_secretary_sets_attendance_and_writes_the_minutes_the_member_reads_after_publishing(): void
    {
        Notification::fake();
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->subDay(), 'location' => 'Main office'], notify: false);
        $row = $meeting->attendees()->where('employee_id', $this->member->employee_id)->firstOrFail();

        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])
            ->call('setAttendance', $row->id, 'online')
            ->set('sectionTitle', 'أولاً: عرض الأعمال')->set('sectionBody', "سرد حر\nعلى سطرين")->call('saveSection')
            ->set('followUpTopic', 'إدارة LinkedIn')->set('followUpParty', 'فارس وكارو')->set('followUpRequired', 'استلام الصفحة')->call('saveFollowUp')
            ->assertHasNoErrors();

        $this->assertSame('online', $row->fresh()->status);
        $this->assertSame(1, $meeting->sections()->count());
        $this->assertSame(1, $meeting->followUps()->count());

        // The draft is the writers' alone.
        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])->assertDontSee('أولاً: عرض الأعمال');
        $this->actingAs($this->member)->get(route('board.meetings.pdf', $meeting))->assertForbidden();

        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])->call('publish')->assertHasNoErrors();

        Notification::assertSentTo($this->member, BoardMeetingNotice::class, fn ($n) => $n->kind === BoardMeetingNotice::MINUTES);
        $this->assertNotNull($meeting->fresh()->published_at);

        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])
            ->assertSee('أولاً: عرض الأعمال')->assertSee('إدارة LinkedIn')->assertSee('فارس وكارو');
    }

    public function test_a_member_cannot_write_minutes_or_set_attendance(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->subDay()], notify: false);
        $row = $meeting->attendees()->firstOrFail();

        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])
            ->set('sectionTitle', 'x')->call('saveSection')->assertForbidden();
        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])
            ->call('setAttendance', $row->id, 'present')->assertForbidden();
        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])->call('publish')->assertForbidden();
    }

    public function test_the_printed_minutes_list_who_attended_and_who_was_absent_in_the_boards_order(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => '2026-11-16 11:00', 'ends_at' => '2026-11-16 12:30', 'room' => '1'], notify: false);
        $meeting->attendees()->where('employee_id', $this->member->employee_id)->update(['status' => 'present']);
        $meeting->attendees()->where('employee_id', $this->chair->employee_id)->update(['status' => 'absent']);
        $meeting->sections()->create(['title' => 'عرض الأعمال', 'body' => 'نص', 'sort' => 1]);
        $meeting->followUps()->create(['topic' => 'ملف', 'party' => 'قسم التكنولوجيا', 'required' => 'نقل النسخ', 'sort' => 1]);
        $meeting->update(['prepared_by' => 'فادي دباغ']);

        $html = view('pdf.board-minutes', BoardMinutesView::data($meeting->fresh()))->render();

        $this->assertStringContainsString('محضر اجتماع مجلس الإدارة', $html);
        $this->assertStringContainsString('أولاً: عرض الأعمال', $html);
        $this->assertStringContainsString('قسم التكنولوجيا', $html);
        $this->assertStringContainsString('أعدّ هذا المحضر: فادي دباغ', $html);
        $this->assertStringContainsString('مسودة', $html);
        $this->assertSame('board-minutes-001-20261116.pdf', BoardMinutesView::fileName($meeting->fresh()));
    }

    public function test_the_chair_adds_an_extra_meeting_from_the_list_and_a_member_cannot(): void
    {
        Livewire::actingAs($this->chair)->test(Index::class)
            ->set('startsAt', '2026-12-17T12:00')->call('saveMeeting')->assertHasNoErrors();

        $this->assertSame(1, BoardMeeting::query()->count());

        Livewire::actingAs($this->member)->test(Index::class)->set('startsAt', '2026-12-18T12:00')->call('saveMeeting')->assertForbidden();
        Livewire::actingAs($this->outsider)->test(Index::class)->assertForbidden();
    }

    public function test_the_migration_gives_the_chair_and_secretary_their_permissions_and_skips_missing_accounts(): void
    {
        $city = $this->makeCity('ALP', '001');
        $chair = $this->makeUser($city, 'general-manager', []);
        $secretary = $this->makeUser($city, 'manager', []);
        config([
            'board.chair_email' => $chair->email,
            'board.secretary_email' => $secretary->email,
        ]);

        (require database_path('migrations/2026_12_02_000003_grant_board_permissions_to_chair_and_secretary.php'))->up();

        $this->assertTrue($chair->fresh()->hasExplicitPermission('board-meetings.manage'));
        $this->assertFalse($chair->fresh()->hasExplicitPermission('board-meetings.write-minutes'));
        $this->assertTrue($secretary->fresh()->hasExplicitPermission('board-meetings.write-minutes'));

        // No such account here (a fresh host): nothing breaks.
        config(['board.chair_email' => 'nobody@example.test', 'board.secretary_email' => null]);
        (require database_path('migrations/2026_12_02_000003_grant_board_permissions_to_chair_and_secretary.php'))->up();
        $this->assertTrue(true);
    }

    public function test_the_commands_are_registered(): void
    {
        $this->assertSame(0, Artisan::call(EnsureNextBoardMeeting::class));
        $this->assertSame(0, Artisan::call(SendBoardMeetingReminders::class));
    }
}
