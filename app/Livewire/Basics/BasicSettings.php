<?php

namespace App\Livewire\Basics;

use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One page for the reference tables of Project Basics. It only chooses which
 * existing module to mount; each module keeps its own table, dialog,
 * validation and permission checks (and its own access-control guards).
 * The active tab lives in the query string (?tab=cities) so it can be
 * bookmarked and works with the back button.
 */
class BasicSettings extends Component
{
    /** tab => [Livewire component, label key, plural?] */
    public const TABS = [
        'cities' => ['basics.city-module', 'general.city', true],
        'contract-types' => ['basics.contract-type-module', 'general.contract-type', true],
        'leave-types' => ['basics.leave-type-module', 'general.leave-type', true],
        'annual-leave-rules' => ['basics.annual-leave-rule-module', 'general.annual_leave_rules', false],
        'termination-reasons' => ['basics.termination-reason-module', 'general.termination_reasons', false],
        'checklist-items' => ['basics.checklist-item-module', 'general.checklist-items', true],
        'job-levels' => ['basics.job-level-module', 'general.job-level', true],
        'departments' => ['basics.department-module', 'general.department', true],
        'course-categories' => ['basics.course-category-module', 'general.course-category', true],
        'holidays' => ['basics.holiday-module', 'holidays.title', false],
    ];

    #[Url(as: 'tab', history: true)]
    public string $tab = 'cities';

    /** @return array<string, string> tab => label, for the tabs the active role may open */
    public function tabs(): array
    {
        $tabs = [];

        foreach (self::TABS as $key => [, $label, $plural]) {
            // Holidays are set by national HR only (D-4); program managers keep their own page.
            if ($key === 'holidays' && ! auth()->user()?->activeRoleIs(...config('access-control.administrator_roles', []))) {
                continue;
            }

            $tabs[$key] = $plural ? trans_choice($label, 2) : __($label);
        }

        return $tabs;
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, $this->tabs())) {
            $this->tab = $tab;
        }
    }

    public function render()
    {
        $tabs = $this->tabs();

        if (! array_key_exists($this->tab, $tabs)) {
            $this->tab = array_key_first($tabs);
        }

        return view('livewire.basics.basic-settings', [
            'tabs' => $tabs,
            'component' => self::TABS[$this->tab][0],
        ]);
    }
}
