<?php

namespace App\Services;

use App\Enums\RecruitmentFileScanStatus;
use App\Exceptions\UnsafeImageException;
use App\Exceptions\UnsafeRecruitmentFileException;
use App\Jobs\ScanRecruitmentApplicantFile;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentApplication;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use ZipArchive;

class RecruitmentApplicantFileService
{
    public function __construct(private readonly SecureImageUploadService $images) {}

    /**
     * Inspect and quarantine an applicant file. A pending file is deliberately
     * unavailable for download until ScanRecruitmentApplicantFile (ClamAV)
     * clears it through markClean().
     *
     * @throws UnsafeRecruitmentFileException
     */
    public function store(
        RecruitmentApplication $application,
        UploadedFile $upload,
        string $fileType,
        ?array $fieldRules = null,
    ): RecruitmentApplicantFile {
        $rules = $this->rulesFor($fileType, $fieldRules);
        $inspection = $this->inspect($upload, $rules);
        $processedImage = null;
        $media = null;

        try {
            if ($inspection['is_image']) {
                try {
                    $processedImage = $this->images->process($upload, 'recruitment_document');
                } catch (UnsafeImageException) {
                    throw UnsafeRecruitmentFileException::make('content_mismatch');
                }

                $sourcePath = $processedImage->path;
                $storedExtension = pathinfo($processedImage->filename, PATHINFO_EXTENSION);
                $detectedMime = $processedImage->mime;
            } else {
                $sourcePath = $upload->getRealPath();
                $storedExtension = $inspection['extension'];
                $detectedMime = $inspection['mime'];
            }

            if (! is_string($sourcePath) || ! is_file($sourcePath)) {
                throw UnsafeRecruitmentFileException::make('unreadable');
            }

            $sha256 = hash_file('sha256', $sourcePath);
            if (! is_string($sha256)) {
                throw UnsafeRecruitmentFileException::make('unreadable');
            }

            $originalName = $this->displayName($upload->getClientOriginalName(), $storedExtension);

            $storedName = Str::uuid()->toString().'.'.$storedExtension;
            $record = DB::transaction(function () use (
                $application,
                $fileType,
                $rules,
                $sourcePath,
                $storedName,
                $detectedMime,
                $sha256,
                $originalName,
                &$media,
            ): RecruitmentApplicantFile {
                RecruitmentApplication::query()
                    ->whereKey($application->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = RecruitmentApplicantFile::query()
                    ->where('application_id', $application->getKey())
                    ->where('file_type', $fileType)
                    ->count();

                if ($existing >= $rules['max_files']) {
                    throw UnsafeRecruitmentFileException::make('too_many');
                }

                $file = RecruitmentApplicantFile::query()->create([
                    'applicant_id' => $application->applicant_id,
                    'application_id' => $application->getKey(),
                    'file_type' => $fileType,
                    'scan_status' => RecruitmentFileScanStatus::Pending,
                    'uploaded_at' => now(),
                ]);

                // The media row is the file's metadata: name holds the sanitized
                // original name (display only), file_name the generated storage
                // name, plus size and the MIME type detected from the bytes.
                $media = $file->addMedia($sourcePath)
                    ->usingName($originalName)
                    ->usingFileName($storedName)
                    ->withCustomProperties([
                        'detected_mime' => $detectedMime,
                        'sha256' => $sha256,
                        'scan_status' => RecruitmentFileScanStatus::Pending,
                    ])
                    ->toMediaCollection(
                        'document',
                        config('recruitment-uploads.quarantine_disk'),
                    );

                return $file->fresh();
            }, 3);

            // Queued once the submission's outer transaction commits (and
            // dropped if it rolls back). A queue that is down must not fail
            // the applicant's submission: the scheduled sweep scans anything
            // left pending.
            $fileId = $record->getKey();
            DB::afterCommit(fn () => rescue(
                fn () => Bus::dispatch(new ScanRecruitmentApplicantFile($fileId)),
                report: true,
            ));

            return $record;
        } catch (Throwable $exception) {
            if ($media instanceof Media) {
                $this->discardMediaFile($media);
            }

            throw $exception;
        } finally {
            $processedImage?->discard();
        }
    }

    /**
     * Promote a file after an external scanner reports it clean.
     *
     * @param  array<string, scalar|null>  $scanMetadata
     */
    public function markClean(RecruitmentApplicantFile $file, array $scanMetadata = []): RecruitmentApplicantFile
    {
        if ($file->scan_status !== RecruitmentFileScanStatus::Pending) {
            throw new RuntimeException('Only pending recruitment files can be marked clean.');
        }

        $media = $file->getFirstMedia('document');
        if (! $media instanceof Media) {
            throw new RuntimeException('The quarantined recruitment file is missing.');
        }

        $sourceDisk = (string) config('recruitment-uploads.quarantine_disk');
        $cleanDisk = (string) config('recruitment-uploads.clean_disk');
        if ($media->disk !== $sourceDisk) {
            throw new RuntimeException('The recruitment file is not on the quarantine disk.');
        }

        $path = $media->getPathRelativeToRoot();
        $source = Storage::disk($sourceDisk)->readStream($path);
        if (! is_resource($source)) {
            throw new RuntimeException('The quarantined recruitment file could not be read.');
        }

        try {
            $written = Storage::disk($cleanDisk)->put($path, $source);
        } finally {
            fclose($source);
        }

        if (! $written || $this->diskHash($sourceDisk, $path) !== $this->diskHash($cleanDisk, $path)) {
            Storage::disk($cleanDisk)->delete($path);
            throw new RuntimeException('The clean recruitment file could not be verified.');
        }

        try {
            DB::transaction(function () use ($file, $media, $cleanDisk, $scanMetadata): void {
                $media->setCustomProperty('scan_status', RecruitmentFileScanStatus::Clean);
                $media->setCustomProperty('scan_metadata', $scanMetadata);
                $media->forceFill([
                    'disk' => $cleanDisk,
                    'conversions_disk' => $cleanDisk,
                ])->save();

                $file->forceFill(['scan_status' => RecruitmentFileScanStatus::Clean])->save();
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk($cleanDisk)->delete($path);
            throw $exception;
        }

        Storage::disk($sourceDisk)->delete($path);

        return $file->fresh();
    }

    public function markQuarantined(RecruitmentApplicantFile $file, array $scanMetadata = []): RecruitmentApplicantFile
    {
        return $this->setFailedScanState($file, RecruitmentFileScanStatus::Quarantined, $scanMetadata);
    }

    public function markScanFailed(RecruitmentApplicantFile $file, array $scanMetadata = []): RecruitmentApplicantFile
    {
        return $this->setFailedScanState($file, RecruitmentFileScanStatus::ScanFailed, $scanMetadata);
    }

    /** @param array<string, mixed> $rules */
    private function inspect(UploadedFile $upload, array $rules): array
    {
        if (! $upload->isValid()) {
            throw UnsafeRecruitmentFileException::make('unreadable');
        }

        $path = $upload->getRealPath();
        if (! is_string($path) || ! is_file($path)) {
            throw UnsafeRecruitmentFileException::make('unreadable');
        }

        $size = filesize($path);
        if (! is_int($size) || $size < 1) {
            throw UnsafeRecruitmentFileException::make('unreadable');
        }
        if ($size > (int) config('recruitment-uploads.max_bytes')) {
            throw UnsafeRecruitmentFileException::make('too_large');
        }

        $originalName = Str::lower($upload->getClientOriginalName());
        $nameParts = preg_split('/\.+/', $originalName) ?: [];
        $blocked = config('recruitment-uploads.blocked_extensions', []);
        if (array_intersect($nameParts, $blocked) !== []) {
            throw UnsafeRecruitmentFileException::make('executable');
        }

        $extension = Str::lower($upload->getClientOriginalExtension());
        if (! in_array($extension, $rules['extensions'], true)) {
            throw UnsafeRecruitmentFileException::make('unsupported');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime)) {
            throw UnsafeRecruitmentFileException::make('unreadable');
        }

        $head = file_get_contents(
            $path,
            false,
            null,
            0,
            (int) config('recruitment-uploads.inspection_bytes'),
        );
        if (! is_string($head)) {
            throw UnsafeRecruitmentFileException::make('unreadable');
        }

        if (preg_match('/<\?(?:php|=)|<script\b|^#!\s*\//i', $head) === 1) {
            throw UnsafeRecruitmentFileException::make('executable');
        }

        $detected = $this->detectType($path, $head, $mime);
        if (! in_array($detected['extension'], $rules['extensions'], true)) {
            throw UnsafeRecruitmentFileException::make('content_mismatch');
        }

        $this->assertNoActiveContent($path, $detected['extension']);

        if ($detected['extension'] === 'jpg' && $extension === 'jpeg') {
            $extension = 'jpg';
        }
        if ($detected['extension'] !== $extension) {
            throw UnsafeRecruitmentFileException::make('content_mismatch');
        }

        return $detected;
    }

    /** @return array{extension: string, mime: string, is_image: bool} */
    private function detectType(string $path, string $head, string $mime): array
    {
        if (str_starts_with($head, '%PDF-') && in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
            return ['extension' => 'pdf', 'mime' => 'application/pdf', 'is_image' => false];
        }

        if (str_starts_with($head, "\xFF\xD8\xFF") && $mime === 'image/jpeg') {
            return ['extension' => 'jpg', 'mime' => 'image/jpeg', 'is_image' => true];
        }

        if (str_starts_with($head, "\x89PNG\r\n\x1A\n") && $mime === 'image/png') {
            return ['extension' => 'png', 'mime' => 'image/png', 'is_image' => true];
        }

        if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")
            && in_array($mime, [
                'application/msword',
                'application/vnd.ms-office',
                'application/x-ole-storage',
                'application/octet-stream',
            ], true)) {
            return ['extension' => 'doc', 'mime' => 'application/msword', 'is_image' => false];
        }

        if (str_starts_with($head, "PK\x03\x04")
            && in_array($mime, [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
                'application/x-zip',
                'application/x-zip-compressed',
                'application/octet-stream',
            ], true)
            && $this->isWordDocument($path)) {
            return [
                'extension' => 'docx',
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'is_image' => false,
            ];
        }

        throw UnsafeRecruitmentFileException::make('content_mismatch');
    }

    /**
     * The signature proves what a file is, not that it is inert. PDFs can carry
     * scripts, launch actions and embedded files; legacy .doc files can carry
     * VBA macros. None of these belong in a CV, so they are refused outright.
     * (.docx macros are refused in isWordDocument(); images are re-encoded.)
     */
    private function assertNoActiveContent(string $path, string $extension): void
    {
        if (! in_array($extension, ['pdf', 'doc'], true)) {
            return;
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw UnsafeRecruitmentFileException::make('unreadable');
        }

        if ($extension === 'doc') {
            // Every VBA project stores a stream with this UTF-16LE name.
            if (str_contains($contents, mb_convert_encoding('_VBA_PROJECT', 'UTF-16LE', 'ASCII'))) {
                throw UnsafeRecruitmentFileException::make('executable');
            }

            return;
        }

        // Object streams can hide dictionaries inside compressed data, so the
        // inflated streams are searched too — capped, so a zip bomb costs nothing.
        $budget = (int) config('recruitment-uploads.pdf_max_inflated_bytes');
        $haystacks = [$contents];
        if (preg_match_all('/stream\r?\n(.*?)endstream/s', $contents, $streams) > 0) {
            foreach ($streams[1] as $stream) {
                if ($budget <= 0) {
                    throw UnsafeRecruitmentFileException::make('too_large');
                }
                // Images and other non-Flate streams are skipped; a Flate stream
                // that cannot be read within the budget is refused, not skipped.
                if (preg_match('/^\x78[\x01\x5E\x9C\xDA]/', $stream) !== 1) {
                    continue;
                }
                $inflated = $this->inflatePdfStream($stream, $budget);
                $budget -= strlen($inflated);
                $haystacks[] = $inflated;
            }
        }

        foreach ($haystacks as $haystack) {
            // PDF names may hex-escape any character (/J#61vaScript), so decode first.
            $decoded = preg_replace_callback(
                '/#([0-9A-Fa-f]{2})/',
                fn (array $match): string => chr((int) hexdec($match[1])),
                $haystack,
            ) ?? $haystack;

            // Every script action names /S /JavaScript, so the bare /JS key (too
            // short to search binary data for) never needs matching on its own.
            if (preg_match('#/(?:JavaScript|Launch|EmbeddedFiles?|RichMedia|XFA|SubmitForm|ImportData)(?![A-Za-z0-9])#', $decoded) === 1) {
                throw UnsafeRecruitmentFileException::make('executable');
            }
        }
    }

    /**
     * Incremental rather than zlib_decode(): writers commonly end PDF streams
     * with a sync flush and no final block, which readers accept but
     * zlib_decode() rejects. Feeding small chunks bounds each step's output,
     * so the budget is enforced before a bomb can expand in memory.
     */
    private function inflatePdfStream(string $stream, int $budget): string
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw UnsafeRecruitmentFileException::make('unreadable');
        }

        $output = '';
        foreach (str_split($stream, 1024) as $chunk) {
            $piece = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
            if (! is_string($piece)) {
                throw UnsafeRecruitmentFileException::make('content_mismatch');
            }

            $output .= $piece;
            if (strlen($output) > $budget) {
                throw UnsafeRecruitmentFileException::make('too_large');
            }

            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                break;
            }
        }

        return $output;
    }

    /** A safe display name: no path, no control characters, and the detected extension. */
    private function displayName(string $clientName, string $extension): string
    {
        $name = str_replace(['\\', '/'], '_', $clientName);
        $name = preg_replace('/[\x00-\x1F\x7F"<>|:*?]+/u', '', $name) ?? '';
        $stem = trim(pathinfo($name, PATHINFO_FILENAME), " .\t");
        $stem = Str::limit($stem === '' ? 'file' : $stem, 200, '');

        return "{$stem}.{$extension}";
    }

    private function isWordDocument(string $path): bool
    {
        if (! class_exists(ZipArchive::class)) {
            return false;
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            $rules = config('recruitment-uploads.docx');
            if (! is_array($rules) || $zip->numFiles > $rules['max_entries']) {
                return false;
            }

            $totalUncompressed = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if (! is_array($entry)) {
                    return false;
                }

                $name = str_replace('\\', '/', (string) ($entry['name'] ?? ''));
                $size = (int) ($entry['size'] ?? 0);
                $compressed = (int) ($entry['comp_size'] ?? 0);
                $totalUncompressed += $size;

                if ($name === ''
                    || str_contains("/{$name}", '/../')
                    || str_starts_with($name, '/')
                    || str_contains(strtolower($name), 'vbaproject.bin')
                    || $size > $rules['max_entry_uncompressed_bytes']
                    || $totalUncompressed > $rules['max_total_uncompressed_bytes']
                    || ($compressed > 0 && $size / $compressed > $rules['max_compression_ratio'])) {
                    return false;
                }
            }

            return $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName('word/document.xml') !== false;
        } finally {
            $zip->close();
        }
    }

    /** @return array{extensions: array<int, string>, max_files: int} */
    private function rulesFor(string $fileType, ?array $fieldRules = null): array
    {
        $rules = $fieldRules ?? config("recruitment-uploads.types.{$fileType}");
        if (! is_array($rules)) {
            throw UnsafeRecruitmentFileException::make('unsupported');
        }

        $allowedExtensions = collect(config('recruitment-uploads.types', []))
            ->flatMap(fn (array $configured): array => $configured['extensions'] ?? [])
            ->map(fn (string $extension): string => Str::lower($extension))
            ->unique()
            ->all();
        $extensions = collect($rules['extensions'] ?? [])
            ->map(fn ($extension): string => Str::lower(trim((string) $extension)))
            ->filter(fn (string $extension): bool => in_array($extension, $allowedExtensions, true))
            ->unique()
            ->values()
            ->all();
        $maxFiles = min(5, max(1, (int) ($rules['max_files'] ?? 1)));

        if ($extensions === []) {
            throw UnsafeRecruitmentFileException::make('unsupported');
        }

        return ['extensions' => $extensions, 'max_files' => $maxFiles];
    }

    private function setFailedScanState(
        RecruitmentApplicantFile $file,
        string $status,
        array $scanMetadata,
    ): RecruitmentApplicantFile {
        if ($file->scan_status !== RecruitmentFileScanStatus::Pending) {
            throw new RuntimeException('Only pending recruitment files can receive a scan result.');
        }

        $media = $file->getFirstMedia('document');
        $media?->setCustomProperty('scan_status', $status);
        $media?->setCustomProperty('scan_metadata', $scanMetadata);
        $media?->save();

        $file->forceFill(['scan_status' => $status])->save();

        return $file->fresh();
    }

    private function diskHash(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException('The recruitment file could not be verified.');
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    private function discardMediaFile(Media $media): void
    {
        try {
            $disk = Storage::disk($media->disk);
            $disk->delete($media->getPathRelativeToRoot());
        } catch (Throwable) {
            // Preserve the original exception. Private orphan cleanup can be
            // retried operationally and must never hide why the upload failed.
        }
    }
}
