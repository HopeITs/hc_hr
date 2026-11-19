<?php

namespace App\Livewire\MailLists;

use App\Livewire\Concerns\AuthorizesActivePermissions;
use App\Livewire\Concerns\WithFeedback;
use App\Models\City;
use App\Models\Department;
use App\Models\MailList;
use App\Models\MailListMember;
use App\Models\Program;
use App\Services\MailListSync;
use Livewire\Component;

/**
 * Each mailing list against the people who should be on it by HR data: who is missing, who is on it
 * and does not match its rule, and who has left the organisation. HR (mail-lists.manage) edits the
 * rule of a list and queues the missing people as pending for the person who holds the control
 * panel; nothing is changed at the mail provider from here.
 */
class Check extends Component
{
    use AuthorizesActivePermissions;
    use WithFeedback;

    /** The list whose rule is being edited. */
    public ?int $editId = null;

    public string $ruleType = '';

    public ?int $ruleCity = null;

    public ?int $ruleProgram = null;

    public ?int $ruleDepartment = null;

    public bool $ruleManagersOnly = false;

    public function editRule(int $listId): void
    {
        $this->authorizeActivePermission('mail-lists.manage');

        $list = MailList::findOrFail($listId);

        $this->editId = $list->id;
        $this->ruleType = (string) $list->rule_type;
        $this->ruleCity = $list->rule_city_id;
        $this->ruleProgram = $list->rule_program_id;
        $this->ruleDepartment = $list->rule_department_id;
        $this->ruleManagersOnly = $list->rule_managers_only;
    }

    public function cancelRule(): void
    {
        $this->editId = null;
    }

    public function saveRule(): void
    {
        $this->authorizeActivePermission('mail-lists.manage');

        $this->validate([
            'ruleType' => ['nullable', 'in:'.implode(',', [MailList::RULE_ALL_STAFF, MailList::RULE_BOARD, MailList::RULE_CUSTOM])],
            'ruleCity' => ['nullable', 'exists:cities,id'],
            'ruleProgram' => ['nullable', 'exists:programs,id'],
            'ruleDepartment' => ['nullable', 'exists:departments,id'],
        ]);

        $custom = $this->ruleType === MailList::RULE_CUSTOM;

        // A custom rule with no filter at all would be everyone: that is the all-staff rule.
        if ($custom && ! $this->ruleCity && ! $this->ruleProgram && ! $this->ruleDepartment && ! $this->ruleManagersOnly) {
            $this->addError('ruleType', __('mail_lists.check.rule_empty'));

            return;
        }

        MailList::findOrFail($this->editId)->update([
            'rule_type' => $this->ruleType === '' ? null : $this->ruleType,
            'rule_city_id' => $custom ? $this->ruleCity : null,
            'rule_program_id' => $custom ? $this->ruleProgram : null,
            'rule_department_id' => $custom ? $this->ruleDepartment : null,
            'rule_managers_only' => $custom && $this->ruleManagersOnly,
        ]);

        $this->editId = null;
        $this->toast(__('mail_lists.check.rule_saved'));
    }

    /** Queue everyone missing from one list as pending. */
    public function queueMissing(int $listId): void
    {
        $this->authorizeActivePermission('mail-lists.manage');

        $count = app(MailListSync::class)->queueMissing(MailList::findOrFail($listId), auth()->id());

        $this->toast(trans_choice('mail_lists.check.queued', $count, ['count' => $count]));
    }

    public function queueOne(int $listId, string $email): void
    {
        $this->authorizeActivePermission('mail-lists.manage');

        $done = app(MailListSync::class)->queueOne(MailList::findOrFail($listId), $email, auth()->id());

        $this->toast($done ? trans_choice('mail_lists.check.queued', 1, ['count' => 1]) : __('mail_lists.check.not_expected'));
    }

    /** Take a person off the list here (they still have to be removed at the control panel). */
    public function removeMember(int $memberId): void
    {
        $this->authorizeActivePermission('mail-lists.manage');

        MailListMember::findOrFail($memberId)->delete();
    }

    /** Someone who left: flag them for removal at the control panel (they stay on the list until it is done). */
    public function queueRemoval(int $memberId): void
    {
        $this->authorizeActivePermission('mail-lists.manage');

        app(MailListSync::class)->queueRemoval(MailListMember::findOrFail($memberId));
        $this->toast(__('mail_lists.check.removal_queued'));
    }

    public function render()
    {
        $sync = app(MailListSync::class);

        $lists = MailList::query()->orderBy('position')->orderBy('name')->get();

        $rows = $lists->map(fn (MailList $list) => [
            'list' => $list,
            'result' => $list->hasRule() ? $sync->compare($list) : null,
        ]);

        return view('livewire.mail-lists.check', [
            'rows' => $rows,
            'checked' => $rows->filter(fn (array $row) => $row['result'] !== null),
            'canManage' => (bool) auth()->user()?->hasActivePermission('mail-lists.manage'),
            'cities' => City::query()->orderBy('name_en')->get(),
            'programs' => Program::query()->orderBy('name')->get(),
            'departments' => Department::query()->orderBy('name')->get(),
        ]);
    }
}
