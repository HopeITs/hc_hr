<?php

namespace Tests\Feature;

use App\Services\DocumentExtractionException;
use App\Services\DocumentExtractor;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.post_gen' => ['url' => 'https://post-gen.test/', 'key' => 'secret-key', 'timeout' => 30]]);
    }

    public function test_it_sends_the_files_with_the_key_and_reads_the_fields(): void
    {
        // the Worker streams spaces while Gemini reads, before the JSON
        Http::fake(['post-gen.test/extract/id' => Http::response('    '.json_encode([
            'ok' => true, 'kind' => 'id', 'doc_type' => 'بطاقة شخصية',
            'fields' => ['national_number' => '02010345678', 'first_name_ar' => 'سامر', 'gender' => ''],
            'text' => 'الاسم: سامر', 'lite' => true,
        ]))]);

        $doc = app(DocumentExtractor::class)->identity([
            UploadedFile::fake()->image('front.jpg'),
            UploadedFile::fake()->image('back.jpg'),
        ]);

        $this->assertSame('02010345678', $doc->get('national_number'));
        $this->assertSame('سامر', $doc->get('first_name_ar'));
        $this->assertNull($doc->get('gender'));
        $this->assertNull($doc->get('dob'));
        $this->assertTrue($doc->lite);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://post-gen.test/extract/id'
            && $r->hasHeader('Authorization', 'Bearer secret-key')
            && $r->isMultipart()
            && count(array_filter($r->data(), fn ($part) => $part['name'] === 'files')) === 2);
    }

    public function test_a_worker_error_becomes_a_message_for_the_user(): void
    {
        Http::fake(['post-gen.test/extract/id' => Http::response('  {"error":"daily limit reached"}')]);

        $this->expectException(DocumentExtractionException::class);
        $this->expectExceptionMessage('The document could not be read');

        app(DocumentExtractor::class)->identity([UploadedFile::fake()->image('front.jpg')]);
    }

    public function test_nothing_is_sent_without_a_key(): void
    {
        config(['services.post_gen.key' => null]);
        Http::fake();

        $this->assertFalse(app(DocumentExtractor::class)->isConfigured());

        try {
            app(DocumentExtractor::class)->identity([UploadedFile::fake()->image('front.jpg')]);
            $this->fail('expected DocumentExtractionException');
        } catch (DocumentExtractionException) {
        }

        Http::assertNothingSent();
    }

    public function test_too_many_files_are_refused_before_sending(): void
    {
        Http::fake();

        $this->expectException(DocumentExtractionException::class);

        try {
            app(DocumentExtractor::class)->identity(array_fill(0, DocumentExtractor::MAX_FILES + 1, UploadedFile::fake()->image('p.jpg')));
        } finally {
            Http::assertNothingSent();
        }
    }
}
