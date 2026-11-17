<?php

namespace App\Livewire\MailLists;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\MailList;
use App\Models\MailListMember;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The organisation's mailing lists. Every employee reads them and emails one
 * (a mailto: to the list's own address: nothing is sent from here). Editing
 * members is `mail-lists.manage`, HR's; a member added here is 'pending' until
 * the person who holds the mail provider's control panel adds them there and
 * confirms it. That queue and its button are `mail-lists.confirm`, held by one
 * named person (never by a role). Members who left the organisation, and
 * addresses outside it, are not shown to anyone but those who manage the lists.
 */
class Index extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    /** @var array<int, string> typed address per list id */
    public array $newEmail = [];

    /** Finds lists by name or address, and lists by who is on them. */
    public string $search = '';

    /** The group shown: '' for all, or one of MailList::CATEGORIES / 'other'. */
    public string $category = '';

    /** The list whose members are open in the side panel. */
    public ?int $openId = null;

    public function setCategory(string $category): void
    {
        $this->category = in_array($category, [...MailList::CATEGORIES, 'other'], true) ? $category : '';
    }

    public function openList(int $listId): void
    {
        $this->openId = MailList::query()->whereKey($listId)->exists() ? $listId : null;
    }

    public function closeList(): void
    {
        $this->openId = null;
    }

    public function addMember(int $listId): void
    {
        $this->authorizeActivePermission('mail-lists.manage');

        $email = Str::lower(trim((string) ($this->newEmail[$listId] ?? '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError("newEmail.$listId", __('mail_lists.bad_email'));

            return;
        }

        // Added here, so not yet in the group at the control panel.
        MailList::findOrFail($listId)->members()->firstOrCreate(
            ['email' => $email],
            ['status' => MailListMember::PENDING, 'added_by_user_id' => auth()->id()],
        );

        $this->newEmail[$listId] = '';
        $this->resetErrorBag();
    }

    public function removeMember(int $memberId): void
    {
        $this->authorizeActivePermission('mail-lists.manage');

        MailListMember::findOrFail($memberId)->delete();
    }

    public function deleteList(int $listId): void
    {
        $this->authorizeActivePermission('mail-lists.manage');

        MailList::findOrFail($listId)->delete();
        $this->toast(__('mail_lists.deleted'));
    }

    /** "Added in the control panel": the pending member is now in the group. */
    public function confirmMember(int $memberId): void
    {
        $this->authorizeConfirmer();

        $this->markActive(MailListMember::query()->pending()->whereKey($memberId));
    }

    /** Every pending addition of one person, across lists, at once. */
    public function confirmEmail(string $email): void
    {
        $this->authorizeConfirmer();

        $this->markActive(MailListMember::query()->pending()->where('email', Str::lower($email)));
    }

    /** "Removed in the control panel": the person who left is no longer in the group, here either. */
    public function confirmRemoval(int $memberId): void
    {
        $this->authorizeConfirmer();

        MailListMember::query()->leaving()->whereKey($memberId)->delete();
    }

    /** Every removal of one person, across lists, at once. */
    public function confirmRemovalEmail(string $email): void
    {
        $this->authorizeConfirmer();

        MailListMember::query()->leaving()->where('email', Str::lower($email))->delete();
    }

    public function render()
    {
        $user = auth()->user();
        $canManage = (bool) $user?->hasActivePermission('mail-lists.manage');
        $canConfirm = $this->canConfirm();
        $lists = MailList::query()->with('members')->orderBy('position')->orderBy('name')->get();

        $accounts = User::query()
            ->whereIn('email', $lists->flatMap->members->pluck('email')->unique()->all())
            ->with('profile')
            ->get()
            ->keyBy(fn (User $account) => Str::lower($account->email));

        $rows = $lists->map(function (MailList $list) use ($accounts, $canManage): array {
            $members = $list->members->map(function (MailListMember $member) use ($accounts): array {
                $profile = $accounts->get($member->email)?->profile;

                return [
                    'id' => $member->id,
                    'email' => $member->email,
                    'name' => $profile?->name,
                    'former' => $profile !== null && ! $profile->is_active,
                    'account' => $profile !== null,
                    'external' => ! str_ends_with($member->email, '@'.MailList::DOMAIN) && $profile === null,
                    'pending' => $member->isPending(),
                    'leaving' => $member->isLeaving(),
                ];
            })->filter(fn (array $member) => $canManage || ! $member['former'])
                ->sortBy(fn (array $member) => Str::lower($member['name'] ?? $member['email']))
                ->values();

            return ['list' => $list, 'members' => $members, 'category' => MailList::categoryOf($list->address)];
        });

        $all = $rows;
        $total = $rows->flatMap(fn (array $row) => $row['members']->pluck('email'))->unique()->count();

        // Search: the list's name or address, or a person on it (shown as who matched).
        $term = Str::lower(trim($this->search));

        if ($term !== '') {
            $rows = $rows->map(function (array $row) use ($term): ?array {
                $byList = str_contains(Str::lower($row['list']->name.' '.$row['list']->address), $term);
                $people = $row['members']->filter(fn (array $member) => str_contains(Str::lower(($member['name'] ?? '').' '.$member['email']), $term));

                return ($byList || $people->isNotEmpty()) ? [...$row, 'matches' => $byList ? collect() : $people->take(3)->values()] : null;
            })->filter()->values();
        }

        $categories = collect([...MailList::CATEGORIES, 'other'])
            ->mapWithKeys(fn (string $key) => [$key => $rows->where('category', $key)->count()])
            ->filter();

        if ($this->category !== '') {
            $rows = $rows->where('category', $this->category)->values();
        }

        // The panel keeps its list even when the search or group would hide its card.
        $open = $this->openId ? $all->firstWhere('list.id', $this->openId) : null;

        // The confirmer's queue: one line per person, with the lists to add them to.
        $pending = $canConfirm
            ? MailListMember::query()->pending()->with(['mailList', 'addedBy.profile'])->orderBy('created_at')->get()
                ->groupBy('email')
                ->map(fn ($members, $email) => [
                    'email' => $email,
                    'name' => ($accounts->get($email) ?? User::query()->where('email', $email)->with('profile')->first())?->profile?->name,
                    'lists' => $members->pluck('mailList.address')->all(),
                    'since' => $members->min('created_at'),
                    'by' => $members->first()->addedBy?->profile?->name,
                ])->values()
            : collect();

        // And who left and must be taken out of the groups there.
        $leaving = $canConfirm
            ? MailListMember::query()->leaving()->with('mailList')->orderBy('updated_at')->get()
                ->groupBy('email')
                ->map(fn ($members, $email) => [
                    'email' => $email,
                    'name' => ($accounts->get($email) ?? User::query()->where('email', $email)->with('profile')->first())?->profile?->name,
                    'lists' => $members->pluck('mailList.address')->all(),
                ])->values()
            : collect();

        return view('livewire.mail-lists.index', [
            'leaving' => $leaving,
            'rows' => $rows,
            'canManage' => $canManage,
            'canConfirm' => $canConfirm,
            'pending' => $pending,
            'total' => $total,
            'categories' => $categories,
            'open' => $open,
        ]);
    }

    /**
     * mail-lists.confirm is one person's, not a role's: read it with
     * hasExplicitPermission(), which also refuses the administrator bypass.
     */
    private function canConfirm(): bool
    {
        return (bool) auth()->user()?->hasExplicitPermission('mail-lists.confirm');
    }

    private function authorizeConfirmer(): void
    {
        abort_unless($this->canConfirm(), 403);
    }

    private function markActive($query): void
    {
        $query->update([
            'status' => MailListMember::ACTIVE,
            'confirmed_by_user_id' => auth()->id(),
            'confirmed_at' => now(),
        ]);
    }
}
