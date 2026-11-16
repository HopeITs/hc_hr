<?php

namespace App\Livewire\MailLists;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\MailList;
use App\Models\MailListMember;
use App\Models\User;
use App\Services\MailListImporter;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The organisation's mailing lists. Every employee reads them and emails one
 * (a mailto: to the list's own address: nothing is sent from here). Importing
 * the CSV and editing members is `mail-lists.manage`, HR's. Members who left
 * the organisation are not shown to anyone but those who manage the lists.
 */
class Index extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;
    use WithFileUploads;

    public $file = null;

    /** @var array<int, string> typed address per list id */
    public array $newEmail = [];

    public function import(MailListImporter $importer): void
    {
        $this->authorizeActivePermission('mail-lists.manage');

        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $parsed = $importer->parse($importer->readCsv($this->file->getRealPath()));

        if ($parsed === []) {
            $this->addError('file', __('mail_lists.nothing_found'));

            return;
        }

        $result = $importer->import($parsed);
        $this->reset('file');
        $this->toast(__('mail_lists.imported', $result));
    }

    public function addMember(int $listId): void
    {
        $this->authorizeActivePermission('mail-lists.manage');

        $email = Str::lower(trim((string) ($this->newEmail[$listId] ?? '')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError("newEmail.$listId", __('mail_lists.bad_email'));

            return;
        }

        MailList::findOrFail($listId)->members()->firstOrCreate(['email' => $email]);
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

    public function render()
    {
        $canManage = (bool) auth()->user()?->hasActivePermission('mail-lists.manage');
        $lists = MailList::query()->with('members')->orderBy('position')->orderBy('name')->get();

        $accounts = User::query()
            ->whereIn('email', $lists->flatMap->members->pluck('email')->unique()->all())
            ->with('profile')
            ->get()
            ->keyBy(fn (User $user) => Str::lower($user->email));

        // Name and status of each member; a former employee only to those who manage the lists.
        $rows = $lists->map(function (MailList $list) use ($accounts, $canManage): array {
            $members = $list->members->map(function (MailListMember $member) use ($accounts): array {
                $profile = $accounts[$member->email]?->profile;

                return [
                    'id' => $member->id,
                    'email' => $member->email,
                    'name' => $profile?->name,
                    'former' => $profile !== null && ! $profile->is_active,
                    'account' => $profile !== null,
                ];
            })->filter(fn (array $member) => $canManage || ! $member['former'])
                ->sortBy(fn (array $member) => Str::lower($member['name'] ?? $member['email']))
                ->values();

            return ['list' => $list, 'members' => $members];
        });

        return view('livewire.mail-lists.index', ['rows' => $rows, 'canManage' => $canManage]);
    }
}
