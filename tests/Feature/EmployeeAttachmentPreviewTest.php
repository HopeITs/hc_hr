<?php

namespace Tests\Feature;

use App\Livewire\Employee\EmployeeEdit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Picking a replacement file used to leave the stored attachment on screen —
 * the preview was fed `old_file_url` and never the staged upload, so the pick
 * looked like it had not registered.
 */
class EmployeeAttachmentPreviewTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_choosing_a_file_previews_it_instead_of_the_stored_one(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'HR-ALP', ['employees.update']);
        $employee = $this->makeEmployee($city, 'attach-'.uniqid());

        // Held in a variable: the fake's temp file is unlinked as soon as the
        // UploadedFile object is collected.
        $stored = UploadedFile::fake()->create('stored-contract.pdf', 20);

        $employee
            ->addMedia($stored->getRealPath())
            ->usingFileName('stored-contract.pdf')
            ->preservingOriginal()
            ->withCustomProperties(['name' => 'Contract', 'category' => ''])
            ->toMediaCollection('attachments', 'local');

        $component = Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('activeTab', 'attachments');

        // Before picking anything, the stored file is what the tab shows.
        $component->assertSee('stored-contract.pdf');

        $component->set(
            'attachmentForm.attachments.0.file',
            UploadedFile::fake()->create('replacement.pdf', 20),
        );

        $component
            ->assertSee('replacement.pdf')
            ->assertDontSee('stored-contract.pdf');
    }

    /**
     * The PDF preview is shared with Education, Courses and Experience, which
     * feed it a URL string / null / a staged upload through the same three
     * paths. These pin that the rewritten branch still renders each one.
     */
    public function test_the_shared_pdf_preview_still_renders_every_state(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'HR-ALP', ['employees.update']);
        $employee = $this->makeEmployee($city, 'edu-'.uniqid());

        $education = $employee->educations()->create([
            'name' => 'Damascus University',
            'specialization' => 'Civil Engineering',
            'degree' => 1,
            'status' => 1,
        ]);

        // 1. Nothing attached.
        Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('activeTab', 'education')
            ->assertSee(__('employees.file-input.no-file'));

        // 2. A stored file is named and linked.
        $stored = UploadedFile::fake()->create('degree-certificate.pdf', 20);
        $education->addMedia($stored->getRealPath())
            ->usingFileName('degree-certificate.pdf')
            ->preservingOriginal()
            ->toMediaCollection('file', 'local');

        $component = Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('activeTab', 'education')
            ->assertSee('degree-certificate.pdf')
            ->assertDontSee(__('employees.file-input.no-file'));

        // 3. Picking a replacement shows the new name, not the stored one.
        $component
            ->set(
                'educationForm.educations.0.edu_attachment',
                UploadedFile::fake()->create('newer-certificate.pdf', 20),
            )
            ->assertSee('newer-certificate.pdf')
            ->assertDontSee('degree-certificate.pdf');
    }

    /**
     * The file input used to render `$errors->first(null)` when given no
     * `name`, and MessageBag::first(null) returns the first message in the
     * whole bag — so an unrelated error was echoed under every file picker.
     * Each message should now appear twice: the form-wide list, and once
     * against its own field.
     */
    public function test_a_field_error_is_not_echoed_a_third_time_under_the_file_input(): void
    {
        $city = $this->makeCity('ALP', '001');
        $user = $this->makeUser($city, 'HR-ALP', ['employees.update']);
        $employee = $this->makeEmployee($city, 'errors-'.uniqid());

        $html = Livewire::actingAs($user)
            ->test(EmployeeEdit::class, ['employee' => $employee])
            ->set('activeTab', 'attachments')
            ->set('attachmentForm.attachments.0.name', 'Experience letter')
            ->call('saveAttachments')
            ->assertHasErrors('attachmentForm.attachments.0.file')
            ->html();

        $this->assertSame(
            2,
            substr_count($html, e(__('validation.required', [
                'attribute' => __('general.file').' ('.__('general.attachment').' 1)',
            ]))),
        );
    }
}
