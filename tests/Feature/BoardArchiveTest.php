<?php

namespace Tests\Feature;

use App\Livewire\Board\Meetings\ArchiveImport;
use App\Livewire\Board\Meetings\Index;
use App\Livewire\Board\Meetings\Show;
use App\Livewire\Team\Calendar;
use App\Models\BoardMeeting;
use App\Models\User;
use App\Services\Board\BoardArchiveImporter;
use App\Services\Board\BoardArchiveReader;
use App\Services\Board\BoardMeetingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Minutes of meetings held before the system: uploaded, read for their number and date, kept as their original
 * file, shown as held, and the system's own meetings numbered on after the last of them.
 */
class BoardArchiveTest extends TestCase
{
    use CreatesHrFixtures, \Illuminate\Foundation\Testing\RefreshDatabase;

    /** The header of minutes no. 046 as pdftotext gives it back (right-to-left, digits split, marks around words). */
    private const HEADER_046 = "\u{202B}ملخص حضور اجتماع\u{202C}\n"
        ."   \u{202B}التاري خ\u{202A}2026 /11/16:\u{202C}\u{202C}        \u{202B}رقم الاجتماع\u{202A}04 6:\u{202C}\u{202C}\n"
        ."   \u{202B}المكان\u{202A} :\u{202C} المكتب الرئيسي\u{202C}\n"
        ."   \u{202B}المسؤول عن الاجتماع\u{202A} :\u{202C} راما دعبول\u{202C}\n"
        ."   \u{202B}حتى الساعة\u{202A}12.30 :\u{202C}\u{202C}        \u{202B}الهدف من الاجتماع:\u{202C}\n"
        ."   \u{202B}التوقيت\u{202A} :\u{202C} من الساعة\u{202A}11.00 :\u{202C}\u{202C}\n"
        ."   \u{202B}أولاً: عرض الأعمال وإعلان الاستقالة\u{202C}\n"
        ."   حساب Google for Nonprofits لم يُفعّل منذ ثلاثة أشهر.\n";

    private User $secretary;

    private User $member;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(BoardArchiveImporter::DISK);
        $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00'));

        $city = $this->makeCity('SYR', '000');
        $this->secretary = $this->makeUser($city, 'board-secretary-role', ['board-meetings.write-minutes'],
            $this->makeEmployee($city, 'fadi', ['is_board_member' => true]));
        $this->member = $this->makeUser($city, 'employee', [], $this->makeEmployee($city, 'member', ['is_board_member' => true]));
        $this->outsider = $this->makeUser($city, 'employee', []);
    }

    public function test_the_reader_finds_number_date_times_and_who_led_it(): void
    {
        $found = app(BoardArchiveReader::class)->parse(self::HEADER_046);

        $this->assertSame(46, $found['number']);
        $this->assertSame('2026-11-16', $found['date']);
        $this->assertSame('11:00', $found['from']);
        $this->assertSame('12:30', $found['to']);
        $this->assertSame('راما دعبول', $found['led_by']);
        $this->assertSame('المكتب الرئيسي', $found['location']);
    }

    public function test_the_reader_falls_back_on_the_file_name_and_takes_day_first_dates(): void
    {
        $reader = app(BoardArchiveReader::class);

        $this->assertSame(45, $reader->parse('نص بلا رقم', 'محضر اجتماع مجلس إدارة -045.pdf')['number']);
        $this->assertSame('2026-11-09', $reader->parse('التاريخ: 9/11/2026')['date']);
        $this->assertNull($reader->parse('التاريخ: 45/13/2026')['date']);
    }

    public function test_the_secretary_uploads_old_minutes_which_become_a_held_meeting_with_its_file_and_nobody_is_told(): void
    {
        Notification::fake();

        $page = Livewire::actingAs($this->secretary)->test(ArchiveImport::class)
            ->set('open', true)
            ->set('files', [$this->docx('محضر اجتماع مجلس إدارة -046.docx', self::HEADER_046)])
            ->assertSet('rows.0.number', '46')
            ->assertSet('rows.0.date', '2026-11-16')
            ->assertSet('rows.0.from', '11:00')
            ->assertSet('rows.0.to', '12:30')
            ->set('rows.0.location', 'المكتب الرئيسي')
            ->call('saveRow', 0)
            ->assertHasNoErrors();

        $meeting = BoardMeeting::query()->firstOrFail();
        $this->assertSame(46, $meeting->number);
        $this->assertTrue($meeting->isArchive());
        $this->assertSame('2026-11-16 11:00', $meeting->starts_at->format('Y-m-d H:i'));
        $this->assertSame('12:30', $meeting->ends_at->format('H:i'));
        $this->assertNotNull($meeting->published_at);
        $this->assertStringContainsString('Google for Nonprofits', (string) $meeting->archive_text);
        Storage::disk(BoardArchiveImporter::DISK)->assertExists($meeting->archive_path);
        $this->assertSame(0, $meeting->attendees()->count(), 'the system does not guess who attended');
        Notification::assertNothingSent();
        $page->assertSet('rows', []);
    }

    public function test_the_system_numbers_its_meetings_on_after_the_last_held_one(): void
    {
        $service = app(BoardMeetingService::class);
        // Opened by the scheduler before the archive was brought in: number 1, next week.
        $opened = $service->schedule(['starts_at' => CarbonImmutable::parse('2026-12-03 12:00')], notify: false);
        $this->assertSame(1, $opened->number);

        $this->import(45, '2026-11-09');
        $this->import(46, '2026-11-16');

        $this->assertSame(47, $opened->fresh()->number);
        $this->assertSame(48, $service->schedule(['starts_at' => CarbonImmutable::parse('2026-12-10 12:00')], notify: false)->number);
    }

    public function test_a_number_already_in_the_archive_or_a_future_date_is_refused(): void
    {
        $this->import(46, '2026-11-16');

        Livewire::actingAs($this->secretary)->test(ArchiveImport::class)
            ->set('files', [$this->docx('a.docx', 'رقم الاجتماع: 46 التاريخ: 16/11/2026')])
            ->call('saveRow', 0)->assertHasErrors('rows.0.number');

        Livewire::actingAs($this->secretary)->test(ArchiveImport::class)
            ->set('files', [$this->docx('b.docx', 'رقم الاجتماع: 47 التاريخ: 16/12/2026')])
            ->call('saveRow', 0)->assertHasErrors('rows.0.date');

        $this->assertSame(1, BoardMeeting::query()->count());
    }

    public function test_only_the_secretary_uploads(): void
    {
        Livewire::actingAs($this->member)->test(ArchiveImport::class)->assertForbidden();

        Livewire::actingAs($this->secretary)->test(Index::class)->assertSeeLivewire(ArchiveImport::class);
        Livewire::actingAs($this->member)->test(Index::class)->assertDontSeeLivewire(ArchiveImport::class);
    }

    public function test_the_board_reads_the_original_file_and_nobody_else_does(): void
    {
        $meeting = $this->import(46, '2026-11-16', 'pdf');

        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])
            ->assertSee('data-testid="board-archive"', false)
            ->assertSee(route('board.meetings.archive', $meeting), false)
            ->assertDontSee('data-testid="board-reopen"', false);

        $this->actingAs($this->member)->get(route('board.meetings.archive', $meeting))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->member)->get(route('board.meetings.pdf', $meeting))
            ->assertRedirect(route('board.meetings.archive', ['meeting' => $meeting, 'download' => 1]));
        $this->actingAs($this->outsider)->get(route('board.meetings.archive', $meeting))->assertForbidden();
    }

    public function test_it_shows_as_held_on_the_calendar_in_the_list_and_in_the_search(): void
    {
        $meeting = $this->import(46, '2026-11-16');

        Livewire::actingAs($this->member)->test(Calendar::class)->set('date', '2026-11-16')->set('view', 'agenda')
            ->assertSee(__('board.meeting-number', ['number' => 46]).' · '.__('board.status.held'));

        $list = Livewire::actingAs($this->member)->test(Index::class)->assertSee(__('board.archive.badge'));
        $this->assertSame([$meeting->id], $list->set('search', 'Nonprofits')->viewData('found')->pluck('id')->all());
    }

    public function test_a_wrong_upload_is_removed_with_its_file_by_the_secretary_only(): void
    {
        $meeting = $this->import(46, '2026-11-16');
        $path = $meeting->archive_path;

        Livewire::actingAs($this->member)->test(Show::class, ['meeting' => $meeting])->call('removeArchive')->assertForbidden();

        Livewire::actingAs($this->secretary)->test(Show::class, ['meeting' => $meeting])->call('removeArchive')
            ->assertRedirect(route('board.meetings.index'));

        $this->assertNull(BoardMeeting::query()->find($meeting->id));
        Storage::disk(BoardArchiveImporter::DISK)->assertMissing($path);
    }

    private function import(int $number, string $date, string $extension = 'docx'): BoardMeeting
    {
        $file = $extension === 'pdf'
            ? UploadedFile::fake()->createWithContent("m-$number.pdf", "%PDF-1.4\n%fake\n")
            : $this->docx("m-$number.docx", self::HEADER_046);

        return app(BoardArchiveImporter::class)->import($file, [
            'number' => $number, 'date' => $date, 'from' => '11:00', 'to' => '12:30', 'text' => self::HEADER_046,
        ], $this->secretary);
    }

    /** A real Word file holding the text, so it is read the way an uploaded one is. */
    private function docx(string $name, string $text): \Illuminate\Http\Testing\File
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $paragraphs = collect(preg_split('/\R/u', $text))
            ->map(fn ($line) => '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($line, ENT_XML1).'</w:t></w:r></w:p>')->implode('');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$paragraphs.'</w:body></w:document>');
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }
}
