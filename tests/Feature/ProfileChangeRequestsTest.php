<?php

namespace Tests\Feature;

use App\Enums\EducationStatus;
use App\Enums\EducationType;
use App\Enums\KinshipType;
use App\Livewire\Employee\EmployeeProfile;
use App\Livewire\Inbox\Index as Inbox;
use App\Models\City;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Notifications\ProfileChangeDecided;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * D1 self-editing with HR approval (profile-contract.md §5.1, Q5 = 4; table
 * approved by the technical lead 2026-10-27). Lina (Aleppo) proposes; Huda is
 * HR-ALP; Karim is HR-HMS; Rana is an Aleppo colleague.
 */
class ProfileChangeRequestsTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $aleppo;

    private Employee $lina;

    private User $linaUser;

    private User $huda;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $this->aleppo = $this->makeCity('ALP', '001');
        $this->lina = $this->makeEmployee($this->aleppo, 'lina', ['first_name_en' => 'Lina']);
        $this->makeContractFor($this->lina);
        $this->linaUser = $this->makeUser($this->aleppo, 'employee', [], $this->lina);
        $this->huda = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'employees.update']);
    }

    public function test_a_proposal_waits_for_hr_and_is_not_on_the_profile_until_approved(): void
    {
        $this->propose('education', $this->education(), UploadedFile::fake()->create('diploma.pdf', 20, 'application/pdf'))
            ->assertHasNoErrors()
            ->assertSet('proposalModal', false);

        $change = ProfileChangeRequest::query()->sole();
        $this->assertSame(ProfileChangeRequest::PENDING, $change->status);
        $this->assertSame(0, $this->lina->educations()->count());
        $this->assertNotNull($change->getFirstMedia('file'));

        // The owner sees it waiting; a colleague sees no trace of it.
        $this->profile($this->linaUser)->assertSeeHtml('data-testid="proposal-pending-education"');
        $rana = $this->makeUser($this->aleppo, 'employee', [], $this->makeEmployee($this->aleppo, 'rana'));
        $this->profile($rana)->assertDontSeeHtml('data-proposal=')->assertDontSee('Physics');

        // HR of the city sees what will be written, with the file; HR of another city does not.
        Livewire::actingAs($this->huda)->test(Inbox::class)
            ->assertSeeHtml('data-testid="inbox-profile-change"')
            ->assertSee('Physics')
            ->assertSeeHtml(route('employee-files.download', $change->getFirstMedia('file')));
        $karim = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['employees.view', 'employees.update']);
        Livewire::actingAs($karim)->test(Inbox::class)->assertDontSee('Physics');
    }

    public function test_hr_approval_writes_the_entry_once_copies_the_file_and_tells_the_employee(): void
    {
        Notification::fake();
        $this->propose('education', $this->education(), UploadedFile::fake()->create('diploma.pdf', 20, 'application/pdf'));
        $change = ProfileChangeRequest::query()->sole();
        $key = "profile:{$change->id}:approve";

        Livewire::actingAs($this->huda)->test(Inbox::class)
            ->call('approveProfile', $key)
            ->assertHasNoErrors()
            ->call('approveProfile', $key);

        $education = $this->lina->educations()->sole();
        $this->assertSame('Physics', $education->name);
        $this->assertNotNull($education->getFirstMedia('file'));
        $this->assertSame(ProfileChangeRequest::APPROVED, $change->fresh()->status);
        $this->assertSame($this->huda->id, $change->fresh()->decided_by);
        Notification::assertSentTo($this->linaUser, ProfileChangeDecided::class);
    }

    public function test_a_rejection_needs_a_reason_writes_nothing_and_the_employee_reads_it(): void
    {
        Notification::fake();
        $this->propose('relative', ['name' => 'Omar', 'relation' => KinshipType::getValues()[0], 'phone' => '0912345678']);
        $change = ProfileChangeRequest::query()->sole();
        $key = "profile:{$change->id}:approve";
        $slug = "profile-{$change->id}-approve";

        Livewire::actingAs($this->huda)->test(Inbox::class)
            ->call('rejectProfile', $key)
            ->assertHasErrors("notes.{$slug}")
            ->set("notes.{$slug}", 'Please attach the family book.')
            ->call('rejectProfile', $key)
            ->assertHasNoErrors();

        $this->assertSame(0, $this->lina->relatives()->count());
        $this->assertSame(ProfileChangeRequest::REJECTED, $change->fresh()->status);
        Notification::assertSentTo($this->linaUser, ProfileChangeDecided::class);
        $this->profile($this->linaUser)->assertSee('Not approved by HR: Please attach the family book.');
    }

    public function test_a_newer_proposal_replaces_the_open_one(): void
    {
        $this->propose('education', $this->education());
        $this->propose('education', [...$this->education(), 'name' => 'Chemistry']);

        $this->assertSame(1, ProfileChangeRequest::query()->pending()->count());
        $this->assertSame('Chemistry', ProfileChangeRequest::query()->pending()->sole()->proposed['name']);
        $this->assertSame(1, ProfileChangeRequest::query()->where('status', ProfileChangeRequest::REPLACED)->count());
    }

    public function test_an_approved_photo_becomes_the_avatar_without_touching_other_data(): void
    {
        $before = $this->lina->fresh()->only(['first_name_en', 'national_number', 'personal_phone']);

        $this->propose('photo', [], UploadedFile::fake()->image('me.png', 120, 120))->assertHasNoErrors();
        $change = ProfileChangeRequest::query()->sole();
        $this->assertNull($this->lina->fresh()->getFirstMedia('avatar'));

        Livewire::actingAs($this->huda)->test(Inbox::class)->call('approveProfile', "profile:{$change->id}:approve");

        $this->assertNotNull($this->lina->fresh()->getFirstMedia('avatar'));
        $this->assertNull($change->fresh()->getFirstMedia('photo'));
        $this->assertSame($before, $this->lina->fresh()->only(['first_name_en', 'national_number', 'personal_phone']));
    }

    public function test_only_the_active_owner_proposes_and_only_hr_in_scope_decides(): void
    {
        // Someone else's profile: no Add buttons, and the action is refused.
        $rana = $this->makeUser($this->aleppo, 'employee', [], $this->makeEmployee($this->aleppo, 'rana'));
        $this->profile($rana)->assertDontSeeHtml('data-testid="propose-')->call('openProposal', 'education')->assertNotFound();
        $this->profile($this->huda)->call('openProposal', 'education')->assertNotFound();

        // A former employee cannot propose.
        $this->lina->update(['is_active' => false]);
        $this->profile($this->linaUser->fresh())->call('openProposal', 'education')->assertNotFound();
        $this->lina->update(['is_active' => true]);

        // HR of another city is refused like a missing row.
        $this->propose('education', $this->education());
        $change = ProfileChangeRequest::query()->sole();
        $karim = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['employees.view', 'employees.update']);
        Livewire::actingAs($karim)->test(Inbox::class)->call('approveProfile', "profile:{$change->id}:approve");
        $this->assertSame(ProfileChangeRequest::PENDING, $change->fresh()->status);
        $this->assertSame(0, $this->lina->educations()->count());
    }

    public function test_invalid_entries_are_reported_on_their_fields_and_nothing_is_sent(): void
    {
        $this->propose('education', ['name' => '', 'degree' => 999])
            ->assertHasErrors(['proposal.name', 'proposal.degree', 'proposal.status'])
            ->assertSet('proposalModal', true);

        $this->assertSame(0, ProfileChangeRequest::query()->count());
    }

    private function propose(string $section, array $data, ?UploadedFile $file = null)
    {
        $component = $this->profile($this->linaUser)->call('openProposal', $section);

        foreach ($data as $field => $value) {
            $component->set("proposal.{$field}", $value);
        }

        if ($file) {
            $component->set('proposalFile', $file);
        }

        return $component->call('submitProposal');
    }

    private function profile(User $user)
    {
        return Livewire::actingAs($user)->test(EmployeeProfile::class, ['employee' => $this->lina]);
    }

    private function education(): array
    {
        return [
            'name' => 'Physics',
            'specialization' => 'Optics',
            'degree' => EducationType::getValues()[0],
            'status' => EducationStatus::getValues()[0],
        ];
    }
}
