<?php

namespace App\Livewire\Concerns;

use App\Livewire\Archives\ArchivedFilesModule;
use DOMDocument;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use ZipArchive;

/**
 * The checks every file put into the Folders module goes through (size,
 * type, name, embedded PHP, real structure of images, PDFs and Office files,
 * SVG sanitising). Shared by the Folders page and the Policies page, so a
 * policy uploaded from either is held to the same rules.
 */
trait ValidatesArchiveUploads
{
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    private const MAX_IMAGE_PIXELS = 40_000_000;

    private const BLOCKED_NAME_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml',
        'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'dll',
        'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'wsh', 'sh', 'bash', 'jar',
        'docm', 'xlsm', 'pptm',
    ];

    /** The form field the rejection message is attached to. */
    protected function uploadErrorKey(): string
    {
        return 'filesToUpload';
    }

    private function validateArchiveUpload(TemporaryUploadedFile $file): array
    {
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();
        $size = $file->getSize();

        if (! $path || ! is_file($path) || $size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            $this->rejectUpload($originalName, 'The file is empty or exceeds the 10 MB limit.');
        }

        if (! in_array($extension, ArchivedFilesModule::ALLOWED_UPLOAD_EXTENSIONS, true)) {
            $this->rejectUpload($originalName, 'Only JPG, JPEG, PNG, SVG, PDF, DOCX, XLSX, PPTX, and TXT files are allowed.');
        }

        $this->rejectSuspiciousFileName($originalName);
        $this->rejectEmbeddedPhp($path, $originalName);

        match ($extension) {
            'jpg', 'jpeg' => $this->validateJpeg($path, $originalName),
            'png' => $this->validatePng($path, $originalName),
            'svg' => $svg = $this->sanitizeSvg($path, $originalName),
            'pdf' => $this->validatePdf($path, $originalName),
            'txt' => $this->validatePlainText($path, $originalName),
            'docx', 'xlsx', 'pptx' => $this->validateOfficeOpenXml($path, $extension, $originalName),
            default => $this->rejectUpload($originalName, 'This file type is not allowed.'),
        };

        $displayName = $this->safeDisplayFileName($originalName, $extension);

        return [
            'extension' => $extension,
            'displayName' => $displayName,
            'storedName' => (string) Str::uuid().'.'.$extension,
            'contents' => $svg ?? null,
        ];
    }

    private function rejectSuspiciousFileName(string $originalName): void
    {
        $lowerName = strtolower($originalName);

        foreach (self::BLOCKED_NAME_EXTENSIONS as $blockedExtension) {
            if (preg_match('/\.'.preg_quote($blockedExtension, '/').'(\.|$)/i', $lowerName)) {
                $this->rejectUpload($originalName, 'The file name contains a blocked executable or macro extension.');
            }
        }

        if (str_contains($originalName, "\0") || str_contains($originalName, '/') || str_contains($originalName, '\\')) {
            $this->rejectUpload($originalName, 'The file name contains unsafe characters.');
        }
    }

    private function rejectEmbeddedPhp(string $path, string $originalName): void
    {
        $handle = fopen($path, 'rb');

        if (! $handle) {
            $this->rejectUpload($originalName, 'The file could not be inspected safely.');
        }

        // Scan the full file in 1 MB chunks; keep an 8-byte tail so a tag
        // split across two chunks is still caught.
        $tail = '';
        while (! feof($handle)) {
            $chunk = fread($handle, 1024 * 1024) ?: '';
            $window = $tail.$chunk;
            if (stripos($window, '<?php') !== false || stripos($window, '<?=') !== false) {
                fclose($handle);
                $this->rejectUpload($originalName, 'The file contains executable PHP code.');
            }
            $tail = substr($chunk, -8);
        }
        fclose($handle);
    }

    private function validateJpeg(string $path, string $originalName): void
    {
        $mime = File::mimeType($path);

        if ($mime !== 'image/jpeg' || ! str_starts_with((string) file_get_contents($path, false, null, 0, 3), "\xFF\xD8\xFF")) {
            $this->rejectUpload($originalName, 'The file is not a valid JPEG image.');
        }

        $this->validateImageDimensions($path, $originalName);
    }

    private function validatePng(string $path, string $originalName): void
    {
        $mime = File::mimeType($path);
        $signature = file_get_contents($path, false, null, 0, 8);

        if ($mime !== 'image/png' || $signature !== "\x89PNG\r\n\x1A\n") {
            $this->rejectUpload($originalName, 'The file is not a valid PNG image.');
        }

        $this->validateImageDimensions($path, $originalName);
    }

    private function validateImageDimensions(string $path, string $originalName): void
    {
        $image = @getimagesize($path);

        if (! $image || empty($image[0]) || empty($image[1])) {
            $this->rejectUpload($originalName, 'The image dimensions could not be verified.');
        }

        if (($image[0] * $image[1]) > self::MAX_IMAGE_PIXELS) {
            $this->rejectUpload($originalName, 'The image dimensions are too large.');
        }
    }

    private function sanitizeSvg(string $path, string $originalName): string
    {
        $contents = file_get_contents($path);

        if (! is_string($contents) || trim($contents) === '') {
            $this->rejectUpload($originalName, 'The SVG file is empty.');
        }

        $lower = strtolower($contents);
        $blockedPatterns = [
            '<script', '<foreignobject', '<iframe', '<object', '<embed', '<link',
            'javascript:', 'vbscript:', 'data:text/html', 'data:application',
            '<?php', '<![cdata[', 'onload=', 'onclick=', 'onerror=', 'onmouseover=',
        ];

        foreach ($blockedPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                $this->rejectUpload($originalName, 'The SVG contains active or external content.');
            }
        }

        if (preg_match('/\son[a-z0-9_-]+\s*=/i', $contents)) {
            $this->rejectUpload($originalName, 'The SVG contains event handler attributes.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || strtolower($document->documentElement?->localName ?? '') !== 'svg') {
            $this->rejectUpload($originalName, 'The file is not a valid SVG image.');
        }

        return $document->saveXML($document->documentElement) ?: $contents;
    }

    private function validatePdf(string $path, string $originalName): void
    {
        $mime = File::mimeType($path);
        $signature = file_get_contents($path, false, null, 0, 5);

        if (! in_array($mime, ['application/pdf', 'application/octet-stream'], true) || $signature !== '%PDF-') {
            $this->rejectUpload($originalName, 'The file is not a valid PDF document.');
        }
    }

    /** A text file is text: no NUL bytes (binary), and valid UTF-8 (BOM allowed). */
    private function validatePlainText(string $path, string $originalName): void
    {
        $contents = (string) file_get_contents($path);

        if (str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            $this->rejectUpload($originalName, 'The file is not a valid UTF-8 text document.');
        }
    }

    /**
     * The name a user types when renaming: without the extension, which the file
     * keeps. A typed copy of it ("Travel.pdf") is not added a second time.
     */
    private function renamedDisplayFileName(string $typed, string $extension): string
    {
        $typed = trim($typed);
        $suffix = '.'.strtolower($extension);

        while ($extension !== '' && str_ends_with(mb_strtolower($typed), $suffix)) {
            $typed = rtrim(mb_substr($typed, 0, mb_strlen($typed) - mb_strlen($suffix)));
        }

        return $this->safeDisplayFileName($typed.'.'.$extension, $extension);
    }

    private function validateOfficeOpenXml(string $path, string $extension, string $originalName): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->rejectUpload($originalName, 'Office file validation is not available on this server.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            $this->rejectUpload($originalName, 'The Office file is not a valid zipped Office document.');
        }

        $maxEntries = (int) config('uploads.office.max_entries', 1000);
        $maxTotalBytes = (int) config('uploads.office.max_total_uncompressed_bytes', 100 * 1024 * 1024);
        $maxEntryBytes = (int) config('uploads.office.max_entry_uncompressed_bytes', 20 * 1024 * 1024);
        $maxRatio = (float) config('uploads.office.max_compression_ratio', 50);

        if ($zip->numFiles < 1 || $zip->numFiles > $maxEntries) {
            $zip->close();
            $this->rejectUpload($originalName, 'The Office archive contains too many entries.');
        }

        $requiredEntries = match ($extension) {
            'docx' => ['[Content_Types].xml', 'word/document.xml'],
            'xlsx' => ['[Content_Types].xml', 'xl/workbook.xml'],
            'pptx' => ['[Content_Types].xml', 'ppt/presentation.xml'],
        };

        foreach ($requiredEntries as $entry) {
            if ($zip->locateName($entry) === false) {
                $zip->close();
                $this->rejectUpload($originalName, 'The Office file structure is invalid.');
            }
        }

        $totalUncompressedBytes = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if (! is_array($stat)) {
                $zip->close();
                $this->rejectUpload($originalName, 'The Office archive could not be inspected safely.');
            }

            $uncompressedBytes = (int) ($stat['size'] ?? 0);
            $compressedBytes = (int) ($stat['comp_size'] ?? 0);
            $totalUncompressedBytes += $uncompressedBytes;

            if ($uncompressedBytes < 0
                || $compressedBytes < 0
                || $uncompressedBytes > $maxEntryBytes
                || $totalUncompressedBytes > $maxTotalBytes
                || ($uncompressedBytes > 0 && $compressedBytes === 0)
                || ($compressedBytes > 0 && ($uncompressedBytes / $compressedBytes) > $maxRatio)
                || (int) ($stat['encryption_method'] ?? 0) !== 0) {
                $zip->close();
                $this->rejectUpload($originalName, 'The Office archive exceeds safe expansion limits or is encrypted.');
            }

            $entryName = $zip->getNameIndex($index) ?: '';
            $lowerEntry = strtolower($entryName);

            if (
                str_contains($entryName, "\0")
                || str_starts_with($entryName, '/')
                || str_contains($entryName, '../')
                || str_contains($entryName, '..\\')
            ) {
                $zip->close();
                $this->rejectUpload($originalName, 'The Office file contains unsafe archive paths.');
            }

            if (
                str_contains($lowerEntry, 'vbaproject.bin')
                || str_contains($lowerEntry, '/activex/')
                || str_contains($lowerEntry, '/embeddings/')
                || preg_match('/\.(php|phtml|phar|js|html?|exe|bat|cmd|ps1|vbs|jar)$/i', $lowerEntry)
            ) {
                $zip->close();
                $this->rejectUpload($originalName, 'The Office file contains macros, embedded objects, or unsafe payloads.');
            }
        }

        $relationships = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index) ?: '';
            if (str_ends_with(strtolower($entryName), '.rels')) {
                $relationships[] = $zip->getFromName($entryName) ?: '';
            }
        }

        $zip->close();

        foreach ($relationships as $relationshipXml) {
            if (preg_match('/TargetMode\s*=\s*["\']External["\']/i', $relationshipXml)) {
                $this->rejectUpload($originalName, 'The Office file contains external links or remote content.');
            }
        }
    }

    private function safeDisplayFileName(string $originalName, string $extension): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $safeBase = Str::of($baseName)
            ->replaceMatches('/[^A-Za-z0-9\-_ .\p{Arabic}]/u', '')
            ->squish()
            ->trim()
            ->limit(120, '');

        return ($safeBase->isNotEmpty() ? (string) $safeBase : 'archive-file').'.'.$extension;
    }

    private function rejectUpload(string $originalName, string $reason): never
    {
        throw ValidationException::withMessages([
            $this->uploadErrorKey() => $originalName.': '.$reason,
        ]);
    }
}
