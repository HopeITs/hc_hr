<?php

namespace App\Livewire\Board\Meetings;

use App\Livewire\Concerns\WithFeedback;
use App\Services\Board\BoardAccess;
use App\Services\Board\BoardArchiveImporter;
use App\Services\Board\BoardArchiveReader;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Old minutes, from before the system: the secretary drops one or more files, each is read on the server for its
 * number, date and times, and he confirms (or corrects) them before it is saved as a meeting that was held.
 */
class ArchiveImport extends Component
{
    use WithFeedback;
    use WithFileUploads;

    public bool $open = false;

    /** @var array<int, TemporaryUploadedFile> */
    public array $files = [];

    /**
     * One row per file read, the fields as found (and as corrected).
     *
     * @var array<int, array{name: string, number: ?string, date: ?string, from: ?string, to: ?string, location: ?string, room: ?string, led_by: ?string, purpose: ?string}>
     */
    public array $rows = [];

    /** What was read from each file (kept for search), by row. @var array<int, string> */
    #[Locked]
    public array $texts = [];

    public function mount(): void
    {
        abort_unless($this->allowed(), 403);
    }

    public function updatedFiles(): void
    {
        abort_unless($this->allowed(), 403);

        $this->validate(['files' => ['required', 'array', 'max:20'], 'files.*' => ['file', 'mimes:pdf,docx,doc', 'max:20480']], [], [
            'files.*' => __('board.archive.file'),
        ]);

        $reader = app(BoardArchiveReader::class);
        $this->rows = [];
        $this->texts = [];

        foreach ($this->files as $i => $file) {
            $found = $reader->read($file->getRealPath(), $file->getClientOriginalExtension(), $file->getClientOriginalName());

            $this->texts[$i] = $found['text'];
            $this->rows[$i] = [
                'name' => $file->getClientOriginalName(),
                'number' => $found['number'] !== null ? (string) $found['number'] : '',
                'date' => (string) $found['date'],
                'from' => (string) $found['from'],
                'to' => (string) $found['to'],
                'location' => (string) $found['location'],
                'room' => '',
                'led_by' => (string) $found['led_by'],
                'purpose' => '',
            ];
        }
    }

    /** Saves one row as a held meeting with its original file. */
    public function saveRow(int $i): void
    {
        abort_unless($this->allowed(), 403);

        if (! isset($this->rows[$i], $this->files[$i])) {
            return;
        }

        $this->validate([
            "rows.$i.number" => ['required', 'integer', 'min:1', 'max:99999'],
            "rows.$i.date" => ['required', 'date', 'before_or_equal:today'],
            "rows.$i.from" => ['nullable', 'date_format:H:i'],
            "rows.$i.to" => ['nullable', 'date_format:H:i'],
            "rows.$i.location" => ['nullable', 'string', 'max:255'],
            "rows.$i.room" => ['nullable', 'string', 'max:60'],
            "rows.$i.led_by" => ['nullable', 'string', 'max:255'],
            "rows.$i.purpose" => ['nullable', 'string', 'max:2000'],
        ], [], [
            "rows.$i.number" => __('board.archive.number'),
            "rows.$i.date" => __('board.archive.date'),
        ]);

        $row = $this->rows[$i];

        try {
            app(BoardArchiveImporter::class)->import($this->files[$i], [
                'number' => (int) $row['number'],
                'date' => $row['date'],
                'from' => $row['from'] ?: null,
                'to' => $row['to'] ?: null,
                'location' => $row['location'] ?: null,
                'room' => $row['room'] ?: null,
                'led_by' => $row['led_by'] ?: null,
                'purpose' => $row['purpose'] ?: null,
                'text' => $this->texts[$i] ?? null,
            ], auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError("rows.$i.$field", $messages[0]);
            }

            return;
        }

        unset($this->rows[$i], $this->files[$i], $this->texts[$i]);
        $this->toast(__('board.archive.saved', ['number' => $row['number']]));
        $this->dispatch('board-archive-imported');

        if ($this->rows === []) {
            $this->reset(['files', 'rows', 'texts', 'open']);
        }
    }

    /** Saves every row that is ready; a row with a problem stays, with its message. */
    public function saveAll(): void
    {
        abort_unless($this->allowed(), 403);

        foreach (array_keys($this->rows) as $i) {
            try {
                $this->saveRow($i);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $this->addError($field, $messages[0]);
                }
            }
        }
    }

    public function dropRow(int $i): void
    {
        abort_unless($this->allowed(), 403);

        unset($this->rows[$i], $this->files[$i], $this->texts[$i]);
    }

    public function render()
    {
        return view('livewire.board.meetings.archive-import');
    }

    /** The secretary keeps the minutes, old ones included. */
    private function allowed(): bool
    {
        return app(BoardAccess::class)->canWrite(auth()->user());
    }
}
