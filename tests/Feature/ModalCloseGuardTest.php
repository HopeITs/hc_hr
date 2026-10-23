<?php

namespace Tests\Feature;

use App\Livewire\Basics\DepartmentModule;
use App\Livewire\ProgramManagement\Center\CentersModule;
use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * A5-M: the shared close guard of editable dialogs (GuardsModalClose).
 * The leave dialogs and the renewal dialog have their own cases next to
 * their fixtures (LeaveSelectorStateTest, LeaveRequestWorkflowTest,
 * ContractRenewalTest).
 */
class ModalCloseGuardTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    /**
     * Dialogs that show a record without editing it keep the ordinary close
     * (backdrop, Escape, close button). Every other <x-modal> in a Livewire
     * view must pass close-action.
     */
    private const READ_ONLY_DIALOGS = [
        'livewire/access-management/index.blade.php' => ['assigned-role-users-modal'],
        'livewire/badges/badges-module.blade.php' => ['badgeDetails'],
        'livewire/employee/leave/all.blade.php' => ['showModal'],
        'livewire/employee/leave/index.blade.php' => ['leaveDetailsModal'],
        // Rendered only by UpdateLeaveRequest, which no route or view mounts.
        'livewire/employee/leave/form.blade.php' => ['showModal'],
        // No component renders this view.
        'livewire/program-management/positions-module.blade.php' => ['showModal'],
    ];

    private City $city;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity('ALP', '001');
        $this->hr = $this->makeUser($this->city, 'HR-ALP', [
            'project-basics.create', 'project-basics.update', 'centers.create', 'centers.update',
        ]);
    }

    public function test_a_pristine_dialog_closes_at_once_without_asking_or_writing(): void
    {
        Livewire::actingAs($this->hr)
            ->test(DepartmentModule::class)
            ->call('create')
            ->assertSet('showModal', true)
            ->call('requestClose', 'showModal')
            ->assertSet('showModal', false)
            ->assertNotDispatched('confirm-dialog');

        $this->assertSame(0, Department::query()->count());
    }

    public function test_a_dirty_dialog_asks_once_and_keeps_entries_errors_and_the_dialog(): void
    {
        Livewire::actingAs($this->hr)
            ->test(DepartmentModule::class)
            ->call('create')
            ->set('item.name', 'Finance')
            ->call('save')
            ->assertHasErrors('item.code')
            ->call('requestClose', 'showModal')
            ->assertSet('showModal', true)
            ->assertSet('item.name', 'Finance')
            ->assertHasErrors('item.code')
            ->assertDispatched('confirm-dialog',
                method: 'discardChanges',
                payload: 'showModal',
                title: __('general.unsaved.title'),
                message: __('general.unsaved.message'),
                confirmLabel: __('general.unsaved.discard'),
                cancelLabel: __('general.unsaved.keep-editing'),
                danger: false,
            );

        $this->assertSame(0, Department::query()->count());
    }

    public function test_discarding_closes_without_writing_and_a_reopened_form_is_clean(): void
    {
        $department = Department::query()->forceCreate(['name' => 'Programs', 'code' => 'PRG']);

        Livewire::actingAs($this->hr)
            ->test(DepartmentModule::class)
            ->call('edit', $department->id)
            ->set('item.name', 'Renamed')
            ->call('discardChanges', 'showModal')
            ->assertSet('showModal', false)
            ->assertHasNoErrors()
            ->call('edit', $department->id)
            ->assertSet('item.name', 'Programs')
            ->call('create')
            ->assertSet('item.name', '')
            ->call('requestClose', 'showModal')
            ->assertSet('showModal', false)
            ->assertNotDispatched('confirm-dialog');

        $this->assertSame('Programs', $department->fresh()->name);
    }

    public function test_entries_that_only_look_different_are_clean(): void
    {
        $center = Center::query()->create([
            'name_en' => 'Hope Center',
            'name_ar' => 'مركز الرجاء',
            'city_id' => $this->city->id,
            'is_active' => true,
            'weekends' => 'friday,saturday',
            'open_at' => '08:00:00',
            'close_at' => '16:00:00',
        ]);

        Livewire::actingAs($this->hr)
            ->test(CentersModule::class)
            ->call('edit', $center->id)
            // What the browser sends back for the same values.
            ->set('item.open_at', '08:00')
            ->set('item.city_id', (string) $this->city->id)
            ->set('item.weekends', ['saturday', 'friday'])
            ->set('item.name_en', 'Hope Center ')
            ->call('requestClose', 'showModal')
            ->assertSet('showModal', false)
            ->assertNotDispatched('confirm-dialog');
    }

    public function test_a_changed_then_restored_entry_is_clean_and_a_real_change_is_not(): void
    {
        Livewire::actingAs($this->hr)
            ->test(DepartmentModule::class)
            ->call('create')
            ->set('item.code', 'X')
            ->set('item.code', '')
            ->call('requestClose', 'showModal')
            ->assertSet('showModal', false)
            ->assertNotDispatched('confirm-dialog')
            ->call('create')
            ->set('item.code', '001')
            ->call('requestClose', 'showModal')
            ->assertSet('showModal', true)
            ->assertDispatched('confirm-dialog');
    }

    public function test_a_close_after_a_successful_save_does_nothing(): void
    {
        Livewire::actingAs($this->hr)
            ->test(DepartmentModule::class)
            ->call('create')
            ->set('item.name', 'Finance')
            ->set('item.code', 'FIN')
            ->call('save')
            ->assertSet('showModal', false)
            // A Cancel or Escape queued behind the save.
            ->call('requestClose', 'showModal')
            ->assertNotDispatched('confirm-dialog');

        $this->assertSame(1, Department::query()->count());
    }

    public function test_only_the_components_own_dialogs_can_be_named(): void
    {
        Livewire::actingAs($this->hr)
            ->test(DepartmentModule::class)
            ->call('requestClose', 'modalTitle')
            ->assertNotFound();

        Livewire::actingAs($this->hr)
            ->test(DepartmentModule::class)
            ->call('discardChanges', 'item')
            ->assertNotFound();
    }

    public function test_the_baseline_cannot_be_set_from_the_browser(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->hr)
            ->test(DepartmentModule::class)
            ->call('create')
            ->set('closeBaselines', []);
    }

    public function test_every_editable_dialog_is_guarded_and_no_cancel_navigates(): void
    {
        $views = resource_path('views');
        $unguarded = [];
        $navigating = [];

        foreach (File::allFiles($views.'/livewire') as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($views) + 1));
            $source = $file->getContents();

            preg_match_all('/<x-modal(?:\.dialog)?\s[^>]*>/s', $source, $tags);

            foreach ($tags[0] as $tag) {
                preg_match('/\bid="([^"]+)"/', $tag, $id);
                $id = $id[1] ?? '?';

                if (! str_contains($tag, 'close-action=') && ! in_array($id, self::READ_ONLY_DIALOGS[$relative] ?? [], true)) {
                    $unguarded[] = "{$relative}#{$id}";
                }
            }

            // A dialog's Cancel that is a link navigates away from the page
            // (full-page forms, without dialogs, may link back to their list).
            if ($tags[0] !== [] && preg_match_all('/<x-primary-button[^>]*:href="route\([^)]*\)"[^>]*>\s*@lang\(\'general\.cancel\'\)/s', $source)) {
                $navigating[] = $relative;
            }
        }

        $this->assertSame([], $unguarded, 'Editable dialogs without close-action (or add read-only ones to READ_ONLY_DIALOGS).');
        $this->assertSame([], $navigating, 'Cancel buttons that navigate instead of closing the dialog.');
    }

    /**
     * Blade does not compile @js() inside a component tag's attribute, which
     * once left the dismiss button reading "Cancel" instead of "Keep editing".
     */
    public function test_the_confirmation_renames_its_dismiss_button_from_the_store(): void
    {
        foreach (['en' => 'Cancel', 'ar' => 'إلغاء'] as $locale => $cancel) {
            app()->setLocale($locale);

            $html = Blade::render('<x-confirm-dialog/>');

            $this->assertStringNotContainsString('@js(', $html);
            $this->assertStringContainsString('x-text="$store.confirm.cancelLabel"', $html);
            // @js() writes the defaults as JSON with quotes escaped as \u0022.
            $this->assertStringContainsString('\\u0022cancelLabel\\u0022:\\u0022'.$cancel.'\\u0022', $html);
        }
    }

    /** Answering "Keep editing" hands focus back to the dialog, so Escape still reaches it. */
    public function test_the_dialog_takes_focus_back_when_the_confirmation_closes(): void
    {
        View::share('errors', new ViewErrorBag);
        View::share('__livewire', new class
        {
            public function getId(): string
            {
                return 'test-component';
            }
        });

        $html = Blade::render('<x-modal.dialog id="m" title="T" wire:model="show" close-action="requestClose">Body</x-modal.dialog>');

        $this->assertStringContainsString("\$watch('\$store.confirm.open'", $html);
        $this->assertStringContainsString("\$wire.call(this.guard, 'm')", $html);
    }
}
