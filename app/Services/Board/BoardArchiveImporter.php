<?php

namespace App\Services\Board;

use App\Models\BoardMeeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

    /**
     * @param  array{number: int, date: string, from?: ?string, to?: ?string, location?: ?string, room?: ?string, led_by?: ?string, purpose?: ?string, text?: ?string}  $data
     */
    public function import(UploadedFile $file, array $data, User $by): BoardMeeting
    {
        $number = (int) $data['number'];
        $date = CarbonImmutable::parse($data['date']);
        $startsAt = $date->setTimeFromTimeString($data['from'] ?: '00:00');

        if ($number < 1) {
            throw ValidationException::withMessages(['number' => __('board.archive.number-required')]);
        }

        if ($startsAt->isFuture()) {
            throw ValidationException::withMessages(['date' => __('board.archive.future')]);
        }

        if (BoardMeeting::query()->where('number', $number)->where('source', 'archive')->exists()) {
            throw ValidationException::withMessages(['number' => __('board.archive.duplicate', ['number' => $number])]);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'pdf');
        $path = $file->storeAs(self::DIR, Str::uuid().'.'.$extension, self::DISK);

        try {
            return DB::transaction(function () use ($number, $startsAt, $date, $data, $file, $path, $by) {
                // A meeting the system opened under this number moves on: the archive holds the real one.
                $this->makeRoomFor($number);

                $meeting = BoardMeeting::create([
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

                $this->renumberSystemMeetings();

                return $meeting;
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

        $this->renumberSystemMeetings();
    }

    /**
     * The system's own meetings are numbered on from the last meeting held before them: after the latest archived
     * number, in date order. Archived numbers are the real ones and never change.
     */
    public function renumberSystemMeetings(): void
    {
        $lastArchive = BoardMeeting::query()->where('source', 'archive')->orderByDesc('starts_at')->first();
        $base = (int) BoardMeeting::query()->where('source', 'archive')->max('number');

        $system = BoardMeeting::query()->where('source', 'system')
            ->when($lastArchive, fn ($q) => $q->where(fn ($w) => $w->where('starts_at', '>', $lastArchive->starts_at)->orWhere('number', '>=', 1_000_000)))
            ->orderBy('starts_at')->orderBy('id')->get();

        if ($system->isEmpty()) {
            return;
        }

        $wanted = $system->values()->mapWithKeys(fn (BoardMeeting $m, int $i) => [$m->id => $base + $i + 1]);

        if ($system->every(fn (BoardMeeting $m) => $m->number === $wanted[$m->id])) {
            return;
        }

        // Two passes so the unique number never collides half way.
        foreach ($system as $m) {
            BoardMeeting::query()->whereKey($m->id)->update(['number' => 1_000_000 + $m->id]);
        }

        foreach ($system as $m) {
            BoardMeeting::query()->whereKey($m->id)->update(['number' => $wanted[$m->id]]);
        }
    }

    private function makeRoomFor(int $number): void
    {
        $taken = BoardMeeting::query()->where('number', $number)->where('source', 'system')->first();

        $taken?->update(['number' => 1_000_000 + $taken->id]);
    }
}
