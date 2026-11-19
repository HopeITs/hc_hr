<?php

namespace App\Livewire\Entitlements;

use App\Livewire\Concerns\WithFeedback;
use App\Models\EmployeeEntitlement;
use App\Models\Entitlement;
use App\Models\JobLevel;
use App\Models\MailList;
use App\Models\Position;
use App\Models\PositionEntitlement;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Permissions Library (meeting 19/11, track 5): the items and the
 * positions (or job levels) that need them by default. HR roles and the
 * administrators, with entitlements.manage-library (route and every action).
 * A city HR links and unlinks only positions of its own cities
 * (Position::inCity); a job-level default applies everywhere, so only the
 * administrators set or remove one.
 */
class Library extends Component
{
    use WithFeedback;

    /** @var array{id: ?int, name_en: string, name_ar: string, category: string, reference: string, mail_list_id: string|int|null, description: string, is_active: bool} */
    public array $item = [];

    public bool $editing = false;

    #[Locked]
    public ?int $openId = null;

    public string $positionSearch = '';

    public string $jobLevelId = '';

    public function mount(Entitlements $entitlements): void
    {
        abort_unless($entitlements->canManageLibrary(Auth::user()), 403);

        $this->resetItem();
    }

    public function newItem(): void
    {
        $this->authorizeLibrary();
        $this->resetItem();
        $this->resetValidation();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeLibrary();
        $model = Entitlement::query()->findOrFail($id);

        $this->item = [
            'id' => $model->id,
            'name_en' => (string) $model->name_en,
            'name_ar' => (string) $model->name_ar,
            'category' => (string) $model->category,
            'reference' => (string) $model->reference,
            'mail_list_id' => $model->mail_list_id,
            'description' => (string) $model->description,
            'is_active' => (bool) $model->is_active,
        ];
        $this->resetValidation();
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->resetItem();
        $this->resetValidation();
        $this->editing = false;
    }

    public function save(): void
    {
        $this->authorizeLibrary();

        $data = $this->validate([
            'item.name_en' => ['required', 'string', 'max:255'],
            'item.name_ar' => ['required', 'string', 'max:255'],
            'item.category' => ['required', Rule::in(Entitlement::CATEGORIES)],
            'item.reference' => ['nullable', 'string', 'max:255'],
            'item.mail_list_id' => ['nullable', 'integer', 'exists:mail_lists,id'],
            'item.description' => ['nullable', 'string', 'max:2000'],
            'item.is_active' => ['boolean'],
        ])['item'];

        $data['mail_list_id'] = $data['category'] === 'mail_list' && filled($data['mail_list_id'] ?? null) ? (int) $data['mail_list_id'] : null;
        $data['reference'] = filled($data['reference'] ?? null) ? $data['reference'] : null;
        $data['description'] = filled($data['description'] ?? null) ? $data['description'] : null;

        $model = filled($this->item['id']) ? Entitlement::query()->findOrFail($this->item['id']) : new Entitlement;
        $model->fill($data)->save();

        $this->openId = $model->id;
        $this->cancelEdit();
        $this->toast(__('entitlements.saved'));
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeLibrary();
        $model = Entitlement::query()->findOrFail($id);
        $model->update(['is_active' => ! $model->is_active]);

        $this->toast($model->is_active ? __('entitlements.activated') : __('entitlements.deactivated'));
    }

    /** Only an item nobody has ever held: anything else is switched off, so the history stays. */
    public function deleteItem(int $id): void
    {
        $this->authorizeLibrary();
        $model = Entitlement::query()->findOrFail($id);

        if (EmployeeEntitlement::query()->where('entitlement_id', $model->id)->exists()) {
            $this->toast(__('entitlements.cannot-delete'), 'error');

            return;
        }

        // Its defaults go with it; a city HR may not remove another city's links this way.
        $foreign = $model->defaults()->get()->contains(fn (PositionEntitlement $link) => ! $this->mayTouchLink($link));
        if ($foreign) {
            $this->toast(__('entitlements.cannot-delete-linked'), 'error');

            return;
        }

        $model->delete();
        $this->openId = $this->openId === $id ? null : $this->openId;
        $this->toast(__('entitlements.deleted'));
    }

    public function open(int $id): void
    {
        $this->authorizeLibrary();
        $this->openId = Entitlement::query()->findOrFail($id)->id;
        $this->positionSearch = '';
        $this->jobLevelId = '';
    }

    public function close(): void
    {
        $this->openId = null;
    }

    public function addPosition(int $positionId): void
    {
        $this->authorizeLibrary();
        $entitlement = $this->openEntitlement();

        $position = $this->positionsInScope()->whereKey($positionId)->firstOrFail();

        PositionEntitlement::query()->firstOrCreate(['entitlement_id' => $entitlement->id, 'position_id' => $position->id]);
        $this->positionSearch = '';
        $this->toast(__('entitlements.linked'));
    }

    public function addJobLevel(Entitlements $entitlements): void
    {
        $this->authorizeLibrary();
        abort_unless($entitlements->canLinkJobLevels(Auth::user()), 403);
        $entitlement = $this->openEntitlement();

        $level = JobLevel::query()->findOrFail((int) $this->jobLevelId);

        PositionEntitlement::query()->firstOrCreate(['entitlement_id' => $entitlement->id, 'job_level_id' => $level->id]);
        $this->jobLevelId = '';
        $this->toast(__('entitlements.linked'));
    }

    public function removeLink(int $linkId): void
    {
        $this->authorizeLibrary();
        $entitlement = $this->openEntitlement();

        $link = PositionEntitlement::query()->where('entitlement_id', $entitlement->id)->findOrFail($linkId);
        abort_unless($this->mayTouchLink($link), 403);

        $link->delete();
        $this->toast(__('entitlements.unlinked'));
    }

    public function render(Entitlements $entitlements)
    {
        $user = Auth::user();
        $items = Entitlement::query()
            ->withCount(['defaults', 'assignments as open_count' => fn (Builder $q) => $q->open()])
            ->with('mailList')
            ->orderByDesc('is_active')
            ->orderBy(transColumn('name'))
            ->get();

        $open = $this->openId ? $items->firstWhere('id', $this->openId) : null;
        $links = $open
            ? PositionEntitlement::query()->where('entitlement_id', $open->id)->with(['position.center', 'position.city', 'jobLevel'])->get()
            : collect();

        $term = trim($this->positionSearch);
        $matches = $open && mb_strlen($term) >= 2
            ? $this->positionsInScope()
                ->whereLikeAny(['title_en', 'title_ar'], $term)
                ->whereNotIn('id', $links->pluck('position_id')->filter()->all())
                ->with(['center', 'city'])
                ->orderBy(transColumn('title'))
                ->limit(15)
                ->get()
            : collect();

        return view('livewire.entitlements.library', [
            'items' => $items,
            'open' => $open,
            'links' => $links,
            'matches' => $matches,
            'mailLists' => MailList::query()->orderBy('name')->get(['id', 'name', 'address']),
            'jobLevels' => JobLevel::query()->orderBy('grade')->get(),
            'canLinkJobLevels' => $entitlements->canLinkJobLevels($user),
            'mayTouch' => fn (PositionEntitlement $link): bool => $this->mayTouchLink($link),
        ]);
    }

    // ── internals ─────────────────────────────────────────────────────

    private function authorizeLibrary(): void
    {
        abort_unless(app(Entitlements::class)->canManageLibrary(Auth::user()), 403);
    }

    private function openEntitlement(): Entitlement
    {
        abort_if($this->openId === null, 404);

        return Entitlement::query()->findOrFail($this->openId);
    }

    /** @return Builder<Position> */
    private function positionsInScope(): Builder
    {
        $cityIds = $this->user()->activeCityIds();

        return Position::query()->when($cityIds !== null, fn (Builder $q) => $q->inCity($cityIds));
    }

    private function mayTouchLink(PositionEntitlement $link): bool
    {
        if ($link->job_level_id !== null) {
            return app(Entitlements::class)->canLinkJobLevels($this->user());
        }

        return $this->positionsInScope()->whereKey($link->position_id)->exists();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function resetItem(): void
    {
        $this->item = [
            'id' => null, 'name_en' => '', 'name_ar' => '', 'category' => 'other',
            'reference' => '', 'mail_list_id' => null, 'description' => '', 'is_active' => true,
        ];
    }
}
