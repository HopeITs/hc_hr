<?php

namespace Tests\Feature;

use App\Livewire\Contract\ContractsTable;
use App\Livewire\Employee\EmployeeCreate;
use App\Livewire\Employee\EmployeeEdit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * M1: the employee form's section navigation on phones (one selector instead
 * of a stack of disabled buttons) and the phone list filters. The selector is
 * driven by the same section list as the desktop list, so these tests pin
 * what a phone user can reach, not how it is styled.
 */
class PhoneEmployeeContractFlowTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_create_mode_offers_only_the_sections_that_do_not_need_a_saved_employee(): void
    {
        $city = $this->makeCity('ALP', '001');

        $component = Livewire::actingAs($this->makeUser($city, 'HR-ALP', ['employees.create']))
            ->test(EmployeeCreate::class);

        $options = $this->sectionOptions($component->html());

        $this->assertFalse($options['information']);
        $this->assertFalse($options['contact']);
        foreach (['education', 'courses', 'experience', 'relatives', 'checklist', 'attachments', 'items', 'contracts_history'] as $locked) {
            $this->assertTrue($options[$locked], "{$locked} must stay unavailable until the employee is saved.");
        }

        $component->assertSee(__('mobile.sections-unlock-after-save'));
    }

    public function test_edit_mode_unlocks_every_section_and_marks_the_current_one(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'phone.nav');

        $component = Livewire::actingAs($this->makeUser($city, 'HR-ALP', ['employees.update']))
            ->test(EmployeeEdit::class, ['employee' => $employee]);

        $options = $this->sectionOptions($component->html());
        $this->assertNotContains(true, $options, 'No section may be disabled for a saved employee.');
        $this->assertArrayHasKey('contracts_history', $options);

        $component->assertDontSee(__('mobile.sections-unlock-after-save'));

        $html = $component->call('setActiveTab', 'education')->html();
        $this->assertMatchesRegularExpression('/<option value="education"[^>]*\bselected\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="information"[^>]*\bselected\b/', $html);
    }

    public function test_the_phone_selector_never_lists_a_section_the_role_cannot_open(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'phone.nav.employee');

        $options = $this->sectionOptions(
            Livewire::actingAs($this->makeUser($city, 'employee', ['employees.update']))
                ->test(EmployeeEdit::class, ['employee' => $employee])
                ->html(),
        );

        $this->assertArrayNotHasKey('contracts_history', $options);
    }

    public function test_the_save_row_is_the_sticky_phone_action_bar(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($city, 'phone.actions');

        $html = Livewire::actingAs($this->makeUser($city, 'HR-ALP', ['employees.update']))
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->html();

        $this->assertStringContainsString('m1-action-bar', $html);
    }

    public function test_the_contracts_list_collapses_its_phone_filters_but_keeps_them_rendered(): void
    {
        $city = $this->makeCity('ALP', '001');
        $this->makeContractFor($this->makeEmployee($city, 'phone.filters'));

        $html = Livewire::actingAs($this->makeUser($city, 'HR-ALP', ['contracts.view']))
            ->test(ContractsTable::class)
            ->html();

        $this->assertStringContainsString('x-data="{ filtersOpen: false }"', $html);
        $this->assertMatchesRegularExpression('/aria-controls="phone-filters-[^"]+"/', $html);
        $this->assertStringContainsString('phone-filter-', $html, 'The filters stay in the page; only their visibility is toggled.');
    }

    public function test_the_new_phone_labels_exist_in_both_languages(): void
    {
        foreach (array_keys(trans('mobile', [], 'en')) as $key) {
            $en = trans('mobile.'.$key, [], 'en');
            $ar = trans('mobile.'.$key, [], 'ar');

            $this->assertNotSame('mobile.'.$key, $ar, "Missing AR mobile.{$key}");
            $this->assertNotSame($en, $ar, "mobile.{$key} is not translated");
        }
    }

    /** @return array<string, bool> option value => disabled */
    private function sectionOptions(string $html): array
    {
        preg_match('/<select[^>]*id="employee-section-select".*?<\/select>/s', $html, $select);
        $this->assertNotEmpty($select, 'The phone section selector is missing.');

        preg_match_all('/<option value="([a-z_]+)"([^>]*)>/', $select[0], $options, PREG_SET_ORDER);

        $result = [];
        foreach ($options as [, $value, $attributes]) {
            $result[$value] = str_contains($attributes, 'disabled');
        }

        return $result;
    }
}
