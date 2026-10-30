<?php

namespace Tests\Feature\Assistant;

use App\Models\PolicyDocument;
use App\Services\Assistant\DrivePolicySync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;
use ZipArchive;

/**
 * policies:sync against a faked Drive folder and a faked Gemini. The rule
 * that matters most: a row is deleted only when a complete listing no
 * longer has its file, and panel rows are never touched.
 */
class PolicySyncTest extends TestCase
{
    use RefreshDatabase;

    private const FOLDER = 'root-folder';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'assistant.policies.drive_folder_id' => self::FOLDER,
            'services.google.drive_api_key' => 'drive-key',
            'assistant.gemini.key' => 'gemini-key',
        ]);

        Sleep::fake();
    }

    public function test_a_pdf_is_transcribed_and_stored(): void
    {
        $this->fakeDrive([self::FOLDER => [$this->file('pdf1', 'سياسة الإجازات.pdf', 'application/pdf')]]);

        $this->artisan('policies:sync')->assertSuccessful();

        $document = PolicyDocument::sole();
        $this->assertSame('pdf1', $document->external_id);
        $this->assertSame('سياسة الإجازات', $document->title);
        $this->assertSame(PolicyDocument::SOURCE_DRIVE, $document->source);
        $this->assertSame('نص pdf1', $document->content);
        $this->assertSame('https://drive.google.com/file/d/pdf1/view', $document->source_url);
        $this->assertNotNull($document->synced_at);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage.googleapis.com')
            && $r['contents'][0]['parts'][0]['inlineData']['data'] === base64_encode('%PDF bytes of pdf1'));
    }

    public function test_a_google_doc_is_exported_as_text_and_a_subfolder_is_read(): void
    {
        $this->fakeDrive([
            self::FOLDER => [$this->file('sub', 'HR', DrivePolicySync::FOLDER)],
            'sub' => [$this->file('doc1', 'Code of conduct v1.2', DrivePolicySync::GOOGLE_DOC)],
        ]);

        $summary = app(DrivePolicySync::class)->run(self::FOLDER);

        $this->assertSame(1, $summary['added']);
        $document = PolicyDocument::sole();
        $this->assertSame('Code of conduct v1.2', $document->title);
        $this->assertSame('exported doc1', $document->content);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
    }

    public function test_a_docx_is_read_one_paragraph_per_line(): void
    {
        $this->fakeDrive([self::FOLDER => [$this->file('docx1', 'Travel.docx', DrivePolicySync::DOCX)]]);

        app(DrivePolicySync::class)->run(self::FOLDER);

        $this->assertSame("First & one\nSecond", PolicyDocument::sole()->content);
    }

    public function test_an_unchanged_file_is_not_read_again_unless_forced(): void
    {
        $this->fakeDrive([self::FOLDER => [$this->file('pdf1', 'Leave.pdf', 'application/pdf')]]);
        app(DrivePolicySync::class)->run(self::FOLDER);
        PolicyDocument::query()->update(['content' => 'old text']);

        // A second run over the same modifiedTime: the stored time must read
        // back equal, whatever the app timezone is.
        $summary = app(DrivePolicySync::class)->run(self::FOLDER);

        $this->assertSame(1, $summary['unchanged']);
        $this->assertSame('old text', PolicyDocument::sole()->content);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage')));

        $summary = app(DrivePolicySync::class)->run(self::FOLDER, force: true);

        $this->assertSame(1, $summary['updated']);
        $this->assertSame('نص pdf1', PolicyDocument::sole()->content);
    }

    public function test_a_removed_file_is_deleted_but_panel_rows_are_kept(): void
    {
        $this->fakeDrive([self::FOLDER => [$this->file('txt1', 'Notes.txt', 'text/plain')]]);
        PolicyDocument::create(['source' => PolicyDocument::SOURCE_DRIVE, 'external_id' => 'gone', 'title' => 'Gone', 'content' => 'x']);
        PolicyDocument::create(['source' => PolicyDocument::SOURCE_PANEL, 'title' => 'Written in the panel', 'content' => 'y']);

        $summary = app(DrivePolicySync::class)->run(self::FOLDER);

        $this->assertSame(1, $summary['removed']);
        $this->assertSame(['Notes', 'Written in the panel'], PolicyDocument::orderBy('title')->pluck('title')->all());
    }

    public function test_a_failed_listing_deletes_nothing(): void
    {
        $this->fakeDrive([
            self::FOLDER => [$this->file('sub', 'HR', DrivePolicySync::FOLDER)],
            'sub' => 500,
        ]);
        PolicyDocument::create(['source' => PolicyDocument::SOURCE_DRIVE, 'external_id' => 'kept', 'title' => 'Kept', 'content' => 'x']);

        $this->artisan('policies:sync')->assertFailed();

        $this->assertTrue(PolicyDocument::where('external_id', 'kept')->exists());
    }

    public function test_one_failing_file_does_not_stop_the_others(): void
    {
        $this->fakeDrive([self::FOLDER => [
            $this->file('broken', 'Broken.pdf', 'application/pdf'),
            $this->file('huge', 'Huge.pdf', 'application/pdf', size: 30 * 1024 * 1024),
            $this->file('img', 'Logo.png', 'image/png'),
            $this->file('pdf2', 'Pay.pdf', 'application/pdf'),
        ]]);

        $summary = app(DrivePolicySync::class)->run(self::FOLDER);

        $this->assertSame(1, $summary['added']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(2, $summary['failed']);
        $this->assertEqualsCanonicalizing(['Broken.pdf', 'Huge.pdf'], array_column($summary['failures'], 'title'));
        $this->assertSame('نص pdf2', PolicyDocument::sole()->content);
    }

    public function test_a_rate_limited_transcription_waits_and_retries_once(): void
    {
        $this->fakeDrive([self::FOLDER => [$this->file('pdf1', 'Leave.pdf', 'application/pdf')]], rateLimitOnce: true);

        $summary = app(DrivePolicySync::class)->run(self::FOLDER);

        $this->assertSame(1, $summary['added']);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds == 30);
    }

    public function test_missing_configuration_is_reported(): void
    {
        config(['assistant.gemini.key' => null]);
        Http::fake();

        $this->artisan('policies:sync')
            ->expectsOutputToContain('GEMINI_API_KEY')
            ->assertFailed();

        Http::assertNothingSent();
    }

    private function file(string $id, string $name, string $mime, ?int $size = 1000): array
    {
        return array_filter([
            'id' => $id,
            'name' => $name,
            'mimeType' => $mime,
            'modifiedTime' => '2026-10-01T10:00:00.000Z',
            'size' => $mime === DrivePolicySync::GOOGLE_DOC || $mime === DrivePolicySync::FOLDER ? null : (string) $size,
            'webViewLink' => "https://drive.google.com/file/d/{$id}/view",
        ]);
    }

    /**
     * Drive answers from $folders (folder id => files, or an HTTP status to
     * fail that listing); a file whose id starts with "broken" fails to
     * download. Gemini answers "نص <id>" for whatever PDF it is sent.
     *
     * @param  array<string, list<array<string, mixed>>|int>  $folders
     */
    private function fakeDrive(array $folders, bool $rateLimitOnce = false): void
    {
        $limited = false;

        Http::fake(function (Request $request) use ($folders, $rateLimitOnce, &$limited) {
            $url = $request->url();

            if (str_contains($url, 'generativelanguage.googleapis.com')) {
                if ($rateLimitOnce && ! $limited) {
                    $limited = true;

                    return Http::response(['error' => ['message' => 'quota']], 429);
                }

                $bytes = base64_decode($request['contents'][0]['parts'][0]['inlineData']['data']);
                $id = str_replace('%PDF bytes of ', '', $bytes);

                return Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => "نص {$id}"]]]]]]);
            }

            $path = parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ($path === '/drive/v3/files') {
                preg_match("/'([^']+)' in parents/", $query['q'], $m);
                $files = $folders[$m[1]] ?? [];

                return is_int($files)
                    ? Http::response(['error' => ['message' => 'boom']], $files)
                    : Http::response(['files' => $files]);
            }

            if (preg_match('#^/drive/v3/files/([^/]+)/export$#', $path, $m)) {
                return Http::response("\u{FEFF}exported {$m[1]}\r\n");
            }

            if (preg_match('#^/drive/v3/files/([^/]+)$#', $path, $m)) {
                if (str_starts_with($m[1], 'broken')) {
                    return Http::response(['error' => ['message' => 'File not found']], 404);
                }

                return Http::response(match (true) {
                    str_starts_with($m[1], 'docx') => $this->docx(),
                    str_starts_with($m[1], 'txt') => "plain {$m[1]}",
                    default => "%PDF bytes of {$m[1]}",
                });
            }

            return Http::response('unexpected '.$url, 500);
        });
    }

    private function docx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="x"><w:body>'
            .'<w:p><w:r><w:t>First &amp; </w:t></w:r><w:r><w:t>one</w:t></w:r></w:p>'
            .'<w:p w:rsidR="1"><w:r><w:t>Second</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();

        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
