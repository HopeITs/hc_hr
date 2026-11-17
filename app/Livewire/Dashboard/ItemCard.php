<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Archives\ArchivedFilesModule;
use App\Livewire\Concerns\HandlesLoadFailure;
use App\Models\Center;
use App\Models\Folder;
use App\Models\Program;
use App\Models\RecruitmentInterview;
use App\Models\User;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One item of a page's card grid on the dashboard (DashboardLayout::ITEMS, key
 * "item.<type>.<id>"): a folder with what is in it, one file, a program or a
 * centre. DashboardLayout::allowed (the page's own rule) is asked again on
 * every render (an interview: RecruitmentInterview::visibleTo and its
 * policy; feedback: feedback.read); a folder lists only the folders shared with the viewer and
 * the file types its page shows.
 */
class ItemCard extends Component
{
    use HandlesLoadFailure;

    /** At most this many folders and files in a folder card. */
    public const LIMIT = 8;

    #[Locked]
    public string $type = '';

    #[Locked]
    public int $itemId = 0;

    public function placeholder(array $params = []): View
    {
        $key = DashboardLayout::itemKey((string) ($params['type'] ?? ''), (int) ($params['itemId'] ?? 0));

        return view('livewire.dashboard.card-placeholder', ['title' => app(DashboardLayout::class)->label($key)]);
    }

    public function render()
    {
        $user = Auth::user();
        $tiles = app(DashboardLayout::class);
        $key = DashboardLayout::itemKey($this->type, $this->itemId);
        $allowed = $user && $tiles->allowed($user, $key);
        $model = $allowed ? $tiles->itemModel($key) : null;

        return view('livewire.dashboard.item-card', [
            'model' => $model,
            'label' => $allowed ? $tiles->label($key) : '',
            'page' => $allowed ? $tiles->itemPage($user, $key) : null,
            'data' => $model ? $this->guardedLoad(fn () => $this->load($user, $model, $tiles), fallback: []) : [],
        ]);
    }

    /** @return array<string, mixed> */
    private function load(User $user, mixed $model, DashboardLayout $tiles): array
    {
        return match ($this->type) {
            'folder' => $this->folder($user, $model, $tiles),
            'file' => [
                'image' => in_array(strtolower((string) $model->extension), ['jpg', 'jpeg', 'png'], true),
                'canDownload' => $user->hasActivePermission('archives.download'),
            ],
            'program' => $this->program($model),
            'center' => $this->center($model),
            'interview' => $this->interview($model),
            'feedback' => [],
            default => [],
        };
    }

    /** Its folders the viewer may open (ArchivedFilesModule's rule) and its files of the types that page shows. */
    private function folder(User $user, Folder $folder, DashboardLayout $tiles): array
    {
        $children = $folder->children()
            ->when(! $user->isActiveAdministrator(), fn ($q) => $q->whereHas('users', fn ($q) => $q->whereKey($user->getKey())))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'parent_id', 'user_id', 'is_shared']);

        $files = $folder->getMedia('archived_files')
            ->filter(fn (Media $media): bool => in_array(strtolower((string) $media->extension), ArchivedFilesModule::ALLOWED_UPLOAD_EXTENSIONS, true))
            ->values();

        return [
            'folders' => $children->map(fn (Folder $child): array => [
                'name' => $child->name,
                'href' => $tiles->itemPage($user, DashboardLayout::itemKey('folder', $child->id)),
            ]),
            'files' => $files->take(self::LIMIT),
            'fileCount' => $files->count(),
        ];
    }

    /** The programs page's card: manager, cities, employees and dates (Program::withCardCounts). */
    private function program(Program $program): array
    {
        $counts = Program::query()->whereKey($program->id)->withCardCounts()->first();

        return [
            'manager' => $program->manager()?->name,
            'cities' => (int) ($counts?->cities_count ?? 0),
            'employees' => (int) ($counts?->contracts_count ?? 0),
            'start' => $program->start_date,
            'end' => $program->end_date,
        ];
    }

    /**
     * What the interview board's card shows: when (in the interview's own time
     * zone), who and for what, the round, its state, where (never the meeting
     * link: that is only on the interview page, behind its own permission)
     * and the panel.
     */
    private function interview(RecruitmentInterview $interview): array
    {
        $interview->loadMissing(['application.applicant', 'application.vacancy.position', 'currentParticipants.user.profile']);
        $zone = $interview->timezone ?: config('app.timezone');

        return [
            'start' => $interview->scheduled_at?->copy()->setTimezone($zone),
            'end' => $interview->ends_at?->copy()->setTimezone($zone),
            'candidate' => $interview->application?->applicant?->full_name,
            'position' => $interview->application?->vacancy?->position?->{transColumn('title')},
            'panel' => $interview->currentParticipants
                ->map(fn ($participant) => $participant->user?->profile?->name ?: $participant->user?->email)
                ->filter()
                ->values(),
        ];
    }

    /** A centre's card: its city, its manager and the people working there now. */
    private function center(Center $center): array
    {
        return [
            'city' => $center->city?->name,
            'manager' => $center->managerNames() ?: null,
            'employees' => $center->activeEmployeesQuery()->count(),
        ];
    }
}
