<?php

namespace Tests\Feature\Security;

use App\Livewire\Archives\ArchivedFilesModule;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;
use ZipArchive;

class OfficeArchiveSafetyTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_valid_small_office_archive_passes_structural_validation(): void
    {
        $path = $this->docx('<w:document><w:body/></w:document>');

        $this->invokeValidator($path);

        $this->addToAssertionCount(1);
    }

    public function test_office_archive_entry_over_the_expansion_limit_is_rejected(): void
    {
        config(['uploads.office.max_entry_uncompressed_bytes' => 64]);
        $path = $this->docx('<w:document>'.str_repeat('A', 256).'</w:document>');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds safe expansion limits');

        $this->invokeValidator($path);
    }

    public function test_ordinary_word_compression_passes_and_a_zip_bomb_ratio_is_rejected(): void
    {
        // Repetitive Word XML: ~100:1, like a long policy document.
        $this->invokeValidator($this->docx('<w:document>'.str_repeat('<w:p><w:r><w:t>Policy clause '.'</w:t></w:r></w:p>', 2000).str_repeat(bin2hex(random_bytes(150)), 1).'</w:document>'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds safe expansion limits');

        $this->invokeValidator($this->docx('<w:document>'.str_repeat('A', 2 * 1024 * 1024).'</w:document>'));
    }

    public function test_native_office_conversion_is_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('uploads.office.conversion_enabled'));
    }

    private function docx(string $documentXml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'office-safety-');
        $this->temporaryFiles[] = $path;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', $documentXml);
        $zip->close();

        return $path;
    }

    private function invokeValidator(string $path): void
    {
        $method = new ReflectionMethod(ArchivedFilesModule::class, 'validateOfficeOpenXml');
        $method->invoke(new ArchivedFilesModule, $path, 'docx', 'test.docx');
    }
}
