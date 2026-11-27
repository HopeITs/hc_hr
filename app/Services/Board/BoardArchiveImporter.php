<?php

namespace App\Services\Board;

use App\Models\BoardMeeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Brings in the minutes of a meeting held before the system: the signed file is kept as it is (private disk), the
 * meeting takes its real number and date, counts as held and published (members read the file), and nobody is
 * notified (it is history). The meetings the system numbered itself follow on after the last one held.
 */
class BoardArchiveImporter
{
    public const DISK = 'local';

    public const DIR = 'board-archive';

    public function isOpen(): bool
    {
        return ! BoardMeeting::query()->where('source', 'system')->exists();
    }

    public function ensureOpen(): void
    {
        if (! $this->isOpen()) {
            throw ValidationException::withMessages(['archive' => __('board.archive.closed')]);
        }
    }

    /**
     * @param  array{number: int, date: string, from?: ?string, to?: ?string, location?: ?string, room?: ?string, led_by?: ?string, purpose?: ?string, text?: ?string}  $data
     */
    public function import(UploadedFile $file, array $data, User $by): BoardMeeting
    {
        $this->ensureOpen();

        $number = (int) $data['number'];
        $date = CarbonImmutable::parse($data['date']);
        $startsAt = $date->setTimeFromTimeString(($data['from'] ?? null) ?: '00:00');

        if ($number < 1) {
            throw ValidationException::withMessages(['number' => __('board.archive.number-required')]);
        }

        if ($startsAt->isFuture()) {
            throw ValidationException::withMessages(['date' => __('board.archive.future')]);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'pdf');
        $path = $file->storeAs(self::DIR, Str::uuid().'.'.$extension, self::DISK);

        try {
            return Cache::lock(BoardMeeting::NUMBER_LOCK, 60)->block(10, function () use ($number, $startsAt, $date, $data, $file, $path, $by): BoardMeeting {
                $this->ensureOpen();

                if (BoardMeeting::query()->where('number', $number)->where('source', 'archive')->exists()) {
                    throw ValidationException::withMessages(['number' => __('board.archive.duplicate', ['number' => $number])]);
                }

                return BoardMeeting::create([
                    'number' => $number,
                    'starts_at' => $startsAt,
                    'ends_at' => ! empty($data['to']) ? $date->setTimeFromTimeString($data['to']) : null,
                    'location' => $data['location'] ?? null,
                    'room' => $data['room'] ?? null,
                    'led_by' => $data['led_by'] ?? null,
                    'purpose' => $data['purpose'] ?? null,
                    'status' => BoardMeeting::SCHEDULED,
                    'source' => 'archive',
                    'archive_path' => $path,
                    'archive_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'archive_mime' => $file->getMimeType(),
                    'archive_text' => $data['text'] ?? null,
                    // Held and its minutes final: nothing to remind about, readable by the board at once.
                    'reminded_at' => now(),
                    'published_at' => now(),
                    'created_by' => $by->id,
                ]);
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }
    }

    /** Removes an imported meeting and its file (to correct a wrong upload). The system's own meetings are never touched. */
    public function remove(BoardMeeting $meeting): void
    {
        abort_unless($meeting->source === 'archive', 403);

        $path = $meeting->archive_path;
        $meeting->delete();

        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
