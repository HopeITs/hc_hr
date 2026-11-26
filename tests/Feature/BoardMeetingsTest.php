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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
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

    public function test_board_meetings_show_on_the_team_calendar_for_the_board_only(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->startOfMonth()->addDays(9)->setTime(12, 0)], notify: false);
        $cancelled = app(BoardMeetingService::class)->schedule(['starts_at' => now()->startOfMonth()->addDays(16)->setTime(12, 0)], notify: false);
        $cancelled->update(['status' => BoardMeeting::CANCELLED, 'cancel_reason' => 'x']);

        $title = __('board.meeting-number', ['number' => $meeting->number]);

        Livewire::actingAs($this->member)->test(\App\Livewire\Team\Calendar::class)->set('view', 'agenda')
            ->assertSee($title)->assertDontSee(__('board.meeting-number', ['number' => $cancelled->number]));
        Livewire::actingAs($this->outsider)->test(\App\Livewire\Team\Calendar::class)->set('view', 'agenda')
            ->assertDontSee($title);
    }

    public function test_the_weekly_announcement_is_scheduled_on_the_meeting_day(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'board:ensure-next-meeting'));

        // Thursday (day 4) at 14:00, plus the daily safety run at 07:30.
        $this->assertContains('0 14 * * 4', $events->map->expression->all());
        $this->assertContains('30 7 * * *', $events->map->expression->all());
    }

    public function test_the_board_meetings_are_pinned_under_the_dashboard_for_the_board_only(): void
    {
        $routes = fn (User $user) => collect(\App\Support\Navigation::for($user)['areas'])->firstWhere('id', 'home')['items'];

        foreach ([$this->member, $this->chair, $this->secretary] as $user) {
            $this->assertSame(['dashboard', 'board.meetings.index'], collect($routes($user->fresh()))->pluck('route')->all());
        }

        $this->assertSame(['dashboard'], collect($routes($this->outsider->fresh()))->pluck('route')->all());
    }

    public function test_published_minutes_read_as_the_printed_sheet_and_only_the_writers_can_switch_to_editing(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->subDay(), 'location' => 'Main office', 'room' => '1'], notify: false);
        $meeting->attendees()->where('employee_id', $this->member->employee_id)->update(['status' => 'present']);
        $meeting->sections()->create(['title' => 'عرض الأعمال', 'body' => 'نص المحضر', 'sort' => 1]);
        $meeting->followUps()->create(['topic' => 'ملف المتابعة', 'party' => 'قسم التكنولوجيا', 'sort' => 1]);

        // Draft: the secretary starts on the working page; the member sees no sheet at all.
        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])
            ->assertSet('mode', 'edit')->assertDontSee('data-testid="board-sheet"', false)
            ->call('setMode', 'view')->assertSee('data-testid="board-sheet"', false)->assertSee('مسودة');
        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])->assertDontSee('data-testid="board-sheet"', false);

        $meeting->update(['published_at' => now(), 'prepared_by' => 'فادي دباغ']);

        // Published: the member reads the sheet (header table, ordinal, follow-up table, who prepared it), not the fields.
        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])
            ->assertSee('data-testid="board-sheet"', false)
            ->assertSee('أولاً: عرض الأعمال')->assertSee('نص المحضر')->assertSee('قسم التكنولوجيا')->assertSee('أعدّ هذا المحضر: فادي دباغ')
            ->assertDontSee('wire:submit.prevent="saveSection"', false)
            ->call('setMode', 'edit')->assertSet('mode', 'view');

        // The secretary opens on the sheet, and published minutes cannot be taken back to editing.
        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])
            ->assertSet('mode', 'view')->call('setMode', 'edit')->assertSet('mode', 'view')
            ->assertDontSee('wire:submit.prevent="saveSection"', false);
    }

    public function test_once_published_nothing_about_the_meeting_changes_for_anyone(): void
    {
        Notification::fake();
        $service = app(BoardMeetingService::class);
        $meeting = $service->schedule(['starts_at' => now()->subDay(), 'location' => 'Main office'], notify: false);
        $row = $meeting->attendees()->firstOrFail();
        $section = $meeting->sections()->create(['title' => 'عرض', 'body' => 'نص', 'sort' => 1]);
        $follow = $meeting->followUps()->create(['topic' => 'ملف', 'sort' => 1]);
        $meeting->update(['published_at' => now()]);

        $secretary = fn () => Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting]);
        $chair = fn () => Livewire::actingAs($this->chair)->test(Show::class, ['meeting' => $meeting]);

        $secretary()->set('sectionTitle', 'جديد')->call('saveSection')->assertForbidden();
        $secretary()->call('editSection', $section->id)->assertForbidden();
        $secretary()->call('removeSection', $section->id)->assertForbidden();
        $secretary()->set('followUpTopic', 'جديد')->call('saveFollowUp')->assertForbidden();
        $secretary()->call('removeFollowUp', $follow->id)->assertForbidden();
        $secretary()->call('setAttendance', $row->id, 'absent')->assertForbidden();
        $secretary()->set('preparedBy', 'x')->call('saveMinutesHeader')->assertForbidden();
        $secretary()->call('publish')->assertForbidden();
        $chair()->set('location', 'Elsewhere')->call('saveDetails')->assertForbidden();
        $chair()->set('cancelReason', 'x')->call('cancelMeeting')->assertForbidden();

        $this->assertSame('عرض', $section->fresh()->title);
        $this->assertSame('ملف', $follow->fresh()->topic);
        $this->assertSame('Main office', $meeting->fresh()->location);

        // The service refuses too, not only the page.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->setAttendance($row->fresh(), 'present');
    }

    public function test_the_chair_starts_on_the_working_page_and_publishing_turns_it_into_the_sheet(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->subHour()], notify: false);

        // A draft: the chair sees his working page (absence requests and all), labelled for him, not "edit the minutes".
        Livewire::actingAs($this->chair)->test(Show::class, ['meeting' => $meeting])
            ->assertSet('mode', 'edit')
            ->call('setMode', 'view')->assertSee(__('board.manage-mode'))->assertDontSee(__('board.minutes.edit-mode'));

        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])
            ->assertSet('mode', 'edit')
            ->call('publish')
            ->assertSet('mode', 'view')
            ->assertSee('data-testid="board-sheet"', false);
    }

    public function test_the_secretary_is_recorded_as_preparing_the_minutes_without_typing_it(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->subHour()], notify: false);
        $name = $this->secretary->profile->name;

        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])
            ->assertSet('preparedBy', $name)
            ->set('sectionTitle', 'عرض')->call('saveSection');

        $this->assertSame($name, $meeting->fresh()->prepared_by);

        // The chair opening the page is not taken for the one who writes.
        $other = app(BoardMeetingService::class)->schedule(['starts_at' => now()->subHour()], notify: false);
        Livewire::actingAs($this->chair)->test(Show::class, ['meeting' => $other])->assertSet('preparedBy', '');
    }

    public function test_a_new_meeting_is_led_by_the_chair_and_the_form_comes_filled_in(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-07 09:00'));
        $service = app(BoardMeetingService::class);
        $service->schedule(['starts_at' => '2026-11-26 12:00', 'location' => 'المكتب الرئيسي', 'room' => '1'], notify: false);

        $this->assertSame($this->chair->profile->name, BoardMeeting::query()->first()->led_by);

        Livewire::actingAs($this->chair)->test(Index::class)
            ->call('openAdd')
            ->assertSet('adding', true)
            ->assertSet('startsAt', '2026-12-10T12:00')
            ->assertSet('endsAt', '2026-12-10T13:30')
            ->assertSet('location', 'المكتب الرئيسي')
            ->assertSet('room', '1');

        Livewire::actingAs($this->member)->test(Index::class)->call('openAdd')->assertForbidden();
    }

    public function test_whoever_acts_is_not_notified_of_their_own_act(): void
    {
        Notification::fake();

        Livewire::actingAs($this->chair)->test(Index::class)->set('startsAt', '2026-12-17T12:00')->call('saveMeeting');

        Notification::assertNotSentTo($this->chair, BoardMeetingNotice::class);
        Notification::assertSentTo($this->member, BoardMeetingNotice::class, fn ($n) => $n->kind === BoardMeetingNotice::SCHEDULED);
    }

    public function test_no_absence_request_once_the_minutes_are_published_or_the_request_was_approved(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->addDays(3)], notify: false);
        $row = $meeting->attendees()->where('employee_id', $this->member->employee_id)->firstOrFail();
        $row->update(['absence_request' => 'approved', 'status' => 'absent']);

        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])
            ->assertDontSee('data-testid="board-absence-form"', false);

        $meeting->update(['published_at' => now()]);

        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])
            ->set('absenceReason', 'x')->call('requestAbsence')->assertForbidden();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(BoardMeetingService::class)->requestAbsence($meeting, $this->member->profile, 'x');
    }

    public function test_a_failed_pdf_goes_back_to_the_meeting_with_a_message(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->subDay()], notify: false);
        $meeting->update(['published_at' => now()]);

        $this->mock(\App\Services\ContractPdfRenderer::class)
            ->shouldReceive('renderHtml')->andThrow(new RuntimeException('Chrome did not write a PDF.'));

        $this->actingAs($this->member)->get(route('board.meetings.pdf', $meeting))
            ->assertRedirect(route('board.meetings.show', $meeting))
            ->assertSessionHas('toasts');
    }

    public function test_the_sheet_is_paper_in_dark_mode_too(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->subDay()], notify: false);
        $meeting->update(['published_at' => now()]);

        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])
            ->assertSee('background:#FFFFFF;color:#414042', false);
    }

    public function test_only_the_chair_reopens_published_minutes_with_a_reason_and_members_lose_them_until_republished(): void
    {
        Notification::fake();
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->subDay()], notify: false);
        $meeting->update(['published_at' => now()]);

        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])
            ->set('reopenReason', 'x')->call('reopenMinutes')->assertForbidden();

        Livewire::actingAs($this->chair)->test(Show::class, ['meeting' => $meeting])
            ->assertSee('data-testid="board-reopen"', false)
            ->call('reopenMinutes')->assertHasErrors('reopenReason')
            ->set('reopenReason', 'خطأ في اسم')->call('reopenMinutes')->assertHasNoErrors()
            ->assertSet('mode', 'edit');

        $meeting->refresh();
        $this->assertNull($meeting->published_at);
        $this->assertSame('خطأ في اسم', $meeting->reopen_reason);
        $this->assertSame($this->chair->id, $meeting->reopened_by);
        Notification::assertSentTo($this->member, BoardMeetingNotice::class, fn ($n) => $n->kind === BoardMeetingNotice::REOPENED);

        // A draft again: the member reads nothing, the secretary can write again.
        $this->actingAs($this->member)->get(route('board.meetings.pdf', $meeting))->assertForbidden();
        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])
            ->set('sectionTitle', 'تصحيح')->call('saveSection')->assertHasNoErrors();
    }

    public function test_follow_ups_link_a_picked_person_who_is_told_on_publishing_and_may_mark_it_done(): void
    {
        Notification::fake();
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->subDay()], notify: false);
        $owner = $this->outsider; // anyone on the staff, not only the board
        Employee::query()->whereKey($owner->employee_id)->update(['first_name_en' => 'Fares', 'first_name_ar' => 'فارس', 'last_name_en' => 'Badawi', 'last_name_ar' => 'بدوي']);
        $ownerName = $owner->fresh()->profile->name;

        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])
            ->set('followUpTopic', 'إدارة LinkedIn')->set('followUpParty', $ownerName)->call('saveFollowUp')
            ->set('followUpTopic', 'مدة الأرشفة')->set('followUpParty', 'قسم العلاقات العامة')->call('saveFollowUp');

        $linked = $meeting->followUps()->where('topic', 'إدارة LinkedIn')->firstOrFail();
        $this->assertSame($owner->employee_id, $linked->employee_id);
        $this->assertNull($meeting->followUps()->where('topic', 'مدة الأرشفة')->value('employee_id'));

        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])->call('publish');
        Notification::assertSentTo($owner, BoardMeetingNotice::class, fn ($n) => $n->kind === BoardMeetingNotice::FOLLOW_UP);
        $this->assertNotNull($linked->fresh()->notified_at);

        // The owner (not on the board) marks his own row done; a member who is not the owner may not.
        $this->app['auth']->forgetGuards();
        app(BoardMeetingService::class)->setFollowUpDone($linked, $owner, true);
        $this->assertSame('done', $linked->fresh()->status);

        $other = $meeting->followUps()->where('topic', 'مدة الأرشفة')->firstOrFail();
        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])->call('markFollowUp', $other->id, true)->assertForbidden();
        Livewire::actingAs($this->chair)->test(Show::class, ['meeting' => $meeting])->call('markFollowUp', $other->id, true)->assertHasNoErrors();
        $this->assertSame('done', $other->fresh()->status);
    }

    public function test_open_rows_from_earlier_minutes_are_offered_and_carried_into_the_next_meeting(): void
    {
        $service = app(BoardMeetingService::class);
        $earlier = $service->schedule(['starts_at' => now()->subWeek()], notify: false);
        $row = $earlier->followUps()->create(['topic' => 'حساب Google', 'party' => 'قسم التكنولوجيا', 'sort' => 1]);
        $earlier->update(['published_at' => now()->subDays(6)]);
        $now = $service->schedule(['starts_at' => now()->subHour()], notify: false);

        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $now])
            ->assertSee('data-testid="board-earlier-follow-ups"', false)->assertSee('حساب Google')
            ->call('carryFollowUp', $row->id);

        $this->assertSame('carried', $row->fresh()->status);
        $copy = $now->followUps()->firstOrFail();
        $this->assertSame('حساب Google', $copy->topic);
        $this->assertSame($row->id, $copy->carried_from_id);

        // Carried: no longer offered.
        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $now])
            ->assertDontSee('data-testid="board-earlier-follow-ups"', false);
    }

    public function test_a_started_meeting_keeps_its_date_cannot_be_cancelled_and_attendance_waits_for_its_time(): void
    {
        $service = app(BoardMeetingService::class);
        $started = $service->schedule(['starts_at' => now()->subHour()], notify: false);
        $ahead = $service->schedule(['starts_at' => now()->addDays(2)], notify: false);

        Livewire::actingAs($this->chair)->test(Show::class, ['meeting' => $started])
            ->set('startsAt', now()->addDay()->format('Y-m-d\TH:i'))->call('saveDetails')->assertHasErrors('startsAt')
            ->set('startsAt', $started->starts_at->format('Y-m-d\TH:i'))->set('location', 'قاعة')->call('saveDetails')->assertHasNoErrors()
            ->set('cancelReason', 'x')->call('cancelMeeting')->assertHasErrors('cancelReason');

        $this->assertSame('قاعة', $started->fresh()->location);
        $this->assertFalse($started->fresh()->isCancelled());

        $row = $ahead->attendees()->firstOrFail();
        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $ahead])
            ->call('setAttendance', $row->id, 'present')->assertHasErrors('status');
        $this->assertSame('expected', $row->fresh()->status);
    }

    public function test_a_member_takes_back_a_pending_absence_request(): void
    {
        Notification::fake();
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->addDays(3)], notify: false);

        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])
            ->set('absenceReason', 'سفر')->call('requestAbsence')
            ->assertSee('data-testid="board-absence-pending"', false)
            ->call('withdrawAbsence')
            ->assertSee('data-testid="board-absence-form"', false);

        $row = $meeting->attendees()->where('employee_id', $this->member->employee_id)->firstOrFail();
        $this->assertNull($row->absence_request);
        $this->assertNull($row->absence_reason);
    }

    public function test_the_chair_sees_a_count_of_absence_requests_waiting_on_him(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->addDays(3)], notify: false);
        $meeting->attendees()->where('employee_id', $this->member->employee_id)->update(['absence_request' => 'pending', 'absence_reason' => 'x']);

        Livewire::actingAs($this->chair)->test(\App\Livewire\Board\AbsenceCounter::class)->assertSeeHtml('title="'.__('board.absence.counter', ['count' => 1]).'"');
        Livewire::actingAs($this->secretary)->test(\App\Livewire\Board\AbsenceCounter::class)->assertDontSeeHtml('rounded-full');

        $item = collect(\App\Support\Navigation::for($this->chair->fresh())['areas'])->firstWhere('id', 'home')['items'][1];
        $this->assertSame('board.absence-counter', $item['counter']);
    }

    public function test_the_dashboard_tile_is_for_the_board_and_shows_the_next_meeting_and_my_follow_ups(): void
    {
        $layout = app(\App\Support\Dashboard\DashboardLayout::class);
        $this->assertTrue($layout->allowed($this->member, 'board-meeting'));
        $this->assertFalse($layout->allowed($this->outsider, 'board-meeting'));

        $service = app(BoardMeetingService::class);
        $past = $service->schedule(['starts_at' => now()->subWeek()], notify: false);
        $past->followUps()->create(['topic' => 'تقرير الربع', 'employee_id' => $this->member->employee_id, 'sort' => 1]);
        $past->update(['published_at' => now()->subDays(6)]);
        $next = $service->schedule(['starts_at' => now()->addDays(3)->setTime(12, 0)], notify: false);

        Livewire::actingAs($this->member)->test(\App\Livewire\Dashboard\BoardMeetingCard::class)
            ->assertSee(__('board.meeting-number', ['number' => $next->number]))
            ->assertSee('تقرير الربع');
    }

    public function test_the_meeting_comes_as_a_calendar_invitation_and_the_reminder_email_carries_it(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-12-09 08:00'));
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => CarbonImmutable::parse('2026-12-10 12:00'), 'location' => 'المكتب الرئيسي'], notify: false);

        $ics = $this->actingAs($this->member)->get(route('board.meetings.ics', $meeting))->assertOk()->getContent();
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('UID:board-meeting-'.$meeting->id.'@', $ics);
        $this->assertStringContainsString('LOCATION:المكتب الرئيسي', $ics);
        $this->actingAs($this->outsider)->get(route('board.meetings.ics', $meeting))->assertForbidden();

        $this->app['auth']->forgetGuards();
        Artisan::call(SendBoardMeetingReminders::class);

        Mail::assertSent(\App\Mail\BoardMeetingReminderMail::class, fn ($mail) => $mail->hasTo($this->member->email));
        Mail::assertSent(\App\Mail\BoardMeetingReminderMail::class, 3);
    }

    public function test_the_secretary_reorders_sections_uses_a_usual_title_and_an_edited_section_saves_as_he_types(): void
    {
        $meeting = app(BoardMeetingService::class)->schedule(['starts_at' => now()->subHour()], notify: false);
        $a = $meeting->sections()->create(['title' => 'أ', 'sort' => 1]);
        $b = $meeting->sections()->create(['title' => 'ب', 'sort' => 2]);

        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])
            ->call('moveSection', $b->id, -1)
            ->call('useTemplate', 0)->assertSet('sectionTitle', config('board.section_templates')[0])
            ->call('editSection', $a->id)->set('sectionBody', 'نص يُحفظ وحده');

        $this->assertSame(['ب', 'أ'], $meeting->sections()->pluck('title')->all());
        $this->assertSame('نص يُحفظ وحده', $a->fresh()->body);
    }

    public function test_members_search_published_minutes_and_the_writers_search_drafts_too(): void
    {
        $service = app(BoardMeetingService::class);
        $published = $service->schedule(['starts_at' => now()->subWeek()], notify: false);
        $published->sections()->create(['title' => 'الملفات التقنية', 'body' => 'حساب Google for Nonprofits', 'sort' => 1]);
        $published->update(['published_at' => now()]);
        $draft = $service->schedule(['starts_at' => now()->subDay()], notify: false);
        $draft->followUps()->create(['topic' => 'Google Drive', 'sort' => 1]);

        $member = Livewire::actingAs($this->member)->test(Index::class)->set('search', 'Google');
        $this->assertSame([$published->id], $member->viewData('found')->pluck('id')->all());

        $secretary = Livewire::actingAs($this->secretary)->test(Index::class)->set('search', 'Google');
        $this->assertEqualsCanonicalizing([$published->id, $draft->id], $secretary->viewData('found')->pluck('id')->all());
    }

    public function test_the_commands_are_registered(): void
    {
        $this->assertSame(0, Artisan::call(EnsureNextBoardMeeting::class));
        $this->assertSame(0, Artisan::call(SendBoardMeetingReminders::class));
    }
}
