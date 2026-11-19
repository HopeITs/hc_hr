<?php

namespace Tests\Feature;

use App\Enums\ContractPlace;
use App\Enums\ContractType;
use App\Enums\EducationStatus;
use App\Enums\EducationType;
use App\Enums\KinshipType;
use App\Livewire\Employee\EmployeeProfile;
use App\Livewire\Inbox\Index as Inbox;
use App\Models\City;
use App\Models\CourseCategory;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Notifications\ProfileChangeDecided;
use App\Notifications\ProfileUpdatedByEmployee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The employee adds education, courses, experience, family and the photo to
 * their own profile directly (meeting 19/11, track 2), and HR of their city is
 * told what was added. Proposals sent before that decision still wait for HR
 * in the Inbox (D1, profile-contract.md §5.1).
 *
 * Lina (Aleppo) adds; Huda is HR-ALP; Karim is HR-HMS; Rana is an Aleppo
 * colleague.
 */
class ProfileChangeRequestsTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $aleppo;

    private Employee $lina;

    private User $linaUser;

    private User $huda;

    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $this->aleppo = $this->makeCity('ALP', '001');
        $this->lina = $this->makeEmployee($this->aleppo, 'lina', ['first_name_en' => 'Lina']);
        $this->makeContractFor($this->lina);
        $this->linaUser = $this->makeUser($this->aleppo, 'employee', [], $this->lina);
        $this->huda = $this->makeUser($this->aleppo, 'HR-ALP', ['employees.view', 'employees.update']);
        $this->karim = $this->makeUser($this->makeCity('HMS', '002'), 'HR-HMS', ['employees.view', 'employees.update']);
    }

    // ── Direct additions ────────────────────────────────────────────────

    public function test_an_education_is_on_the_profile_at_once_with_its_file_and_hr_of_the_city_is_told(): void
    {
        Notification::fake();

        $this->add('education', $this->education(), UploadedFile::fake()->create('diploma.pdf', 20, 'application/pdf'))
            ->assertHasNoErrors()
            ->assertSet('entryModal', false);

        $education = $this->lina->educations()->sole();
        $this->assertSame('Physics', $education->name);
        $this->assertNotNull($education->getFirstMedia('file'));
        $this->assertSame(0, ProfileChangeRequest::query()->count());

        Notification::assertSentTo($this->huda, ProfileUpdatedByEmployee::class, function (ProfileUpdatedByEmployee $n): bool {
            $data = $n->toArray($this->huda);

            return $data['body']['en'] === 'Added education: Physics · Optics'
                && str_contains($data['body']['ar'], 'Physics')
                && $data['url'] === route('employees.show', $this->lina->username)
                && $data['title']['en'] === 'Lina '.$this->lina->last_name_en.' updated their profile';
        });
        Notification::assertNotSentTo([$this->karim, $this->linaUser], ProfileUpdatedByEmployee::class);
    }

    public function test_a_course_and_an_experience_are_written_directly(): void
    {
        Notification::fake();
        $category = CourseCategory::query()->create(['name_en' => 'IT', 'name_ar' => 'تقنية']);

        $this->add('course', [
            'title' => 'First aid', 'category_id' => $category->id, 'organization' => 'Personal',
            'end_date' => '2026-05-01', 'status' => 5,
        ])->assertHasNoErrors();
        $this->add('experience', [
            'job_title' => 'Teacher', 'company' => 'School', 'start_date' => '2020-01-01',
            'place' => ContractPlace::getValues()[0], 'type' => ContractType::getValues()[0],
        ])->assertHasNoErrors();

        $this->assertSame('First aid', $this->lina->courses()->sole()->title);
        $this->assertFalse((bool) $this->lina->courses()->sole()->has_certificate);
        $this->assertSame('Teacher', $this->lina->experiences()->sole()->job_title);
        Notification::assertSentToTimes($this->huda, ProfileUpdatedByEmployee::class, 2);
    }

    public function test_a_family_member_is_written_and_the_phone_never_reaches_the_notification(): void
    {
        Notification::fake();

        $this->add('relative', ['name' => 'Omar', 'relation' => KinshipType::getValues()[0], 'phone' => '0912345678'])
            ->assertHasNoErrors();

        $this->assertSame('0912345678', $this->lina->relatives()->sole()->phone);
        Notification::assertSentTo($this->huda, ProfileUpdatedByEmployee::class, function (ProfileUpdatedByEmployee $n): bool {
            $json = json_encode($n->toArray($this->huda), JSON_UNESCAPED_UNICODE);

            return str_contains($json, 'Omar') && ! str_contains($json, '0912345678');
        });
    }

    public function test_a_new_photo_becomes_the_avatar_without_touching_other_data(): void
    {
        Notification::fake();
        $before = $this->lina->fresh()->only(['first_name_en', 'national_number', 'personal_phone']);

        $this->add('photo', [], UploadedFile::fake()->image('me.png', 120, 120))->assertHasNoErrors();

        $this->assertNotNull($this->lina->fresh()->getFirstMedia('avatar'));
        $this->assertSame($before, $this->lina->fresh()->only(['first_name_en', 'national_number', 'personal_phone']));
        Notification::assertSentTo($this->huda, ProfileUpdatedByEmployee::class,
            fn (ProfileUpdatedByEmployee $n): bool => $n->toArray($this->huda)['body']['en'] === 'Updated photo');
    }

    public function test_invalid_entries_are_reported_on_their_fields_and_nothing_is_written_or_sent(): void
    {
        Notification::fake();

        $this->add('education', ['name' => '', 'degree' => 999])
            ->assertHasErrors(['entry.name', 'entry.degree', 'entry.status'])
            ->assertSet('entryModal', true);
        $this->add('photo', [])->assertHasErrors('entryFile');

        $this->assertSame(0, $this->lina->educations()->count());
        $this->assertNull($this->lina->fresh()->getFirstMedia('avatar'));
        Notification::assertNothingSent();
    }

    public function test_only_the_active_owner_adds_and_only_to_their_own_profile(): void
    {
        Notification::fake();
        $rana = $this->makeUser($this->aleppo, 'employee', [], $this->makeEmployee($this->aleppo, 'rana'));

        // Someone else's profile: no Add buttons, and the actions are refused.
        $this->profile($rana)->assertDontSeeHtml('data-entry=')
            ->call('openEntry', 'education')->assertNotFound();
        $this->profile($rana)->set('adding', 'education')->set('entry', $this->education())
            ->call('saveEntry')->assertNotFound();
        $this->profile($this->huda)->call('openEntry', 'education')->assertNotFound();

        // A former employee cannot add.
        $this->lina->update(['is_active' => false]);
        $this->profile($this->linaUser->fresh())->call('openEntry', 'education')->assertNotFound();

        $this->assertSame(0, $this->lina->educations()->count());
        Notification::assertNothingSent();
    }

    public function test_a_city_without_hr_reports_to_national_hr(): void
    {
        Notification::fake();
        $national = $this->makeUser($this->makeCity('SYR', '000'), 'HR-SYR', ['employees.view', 'employees.update']);
        $latakia = $this->makeCity('LAT', '005');
        $sara = $this->makeEmployee($latakia, 'sara');
        $this->makeContractFor($sara);
        $saraUser = $this->makeUser($latakia, 'employee', [], $sara);

        Livewire::actingAs($saraUser)->test(EmployeeProfile::class, ['employee' => $sara])
            ->call('openEntry', 'relative')
            ->set('entry.name', 'Ziad')->set('entry.relation', KinshipType::getValues()[0])
            ->call('saveEntry')->assertHasNoErrors();

        Notification::assertSentTo($national, ProfileUpdatedByEmployee::class);
        Notification::assertNotSentTo([$this->huda, $this->karim], ProfileUpdatedByEmployee::class);
    }

    // ── Proposals sent before 19/11 ─────────────────────────────────────

    public function test_an_old_pending_proposal_still_shows_to_its_owner_and_hr_approves_it_once(): void
    {
        Notification::fake();
        $change = $this->pending('education', $this->education(), UploadedFile::fake()->create('diploma.pdf', 20, 'application/pdf'));

        $this->profile($this->linaUser)->assertSeeHtml('data-testid="proposal-pending-education"');
        $rana = $this->makeUser($this->aleppo, 'employee', [], $this->makeEmployee($this->aleppo, 'rana'));
        $this->profile($rana)->assertDontSeeHtml('data-entry=')->assertDontSee('Physics');

        // HR of another city sees nothing and cannot decide it.
        Livewire::actingAs($this->karim)->test(Inbox::class)->assertDontSee('Physics')
            ->call('approveProfile', "profile:{$change->id}:approve");
        $this->assertSame(ProfileChangeRequest::PENDING, $change->fresh()->status);

        $key = "profile:{$change->id}:approve";
        Livewire::actingAs($this->huda)->test(Inbox::class)
            ->assertSee('Physics')
            ->call('approveProfile', $key)
            ->assertHasNoErrors()
            ->call('approveProfile', $key);

        $education = $this->lina->educations()->sole();
        $this->assertNotNull($education->getFirstMedia('file'));
        $this->assertSame(ProfileChangeRequest::APPROVED, $change->fresh()->status);
        Notification::assertSentTo($this->linaUser, ProfileChangeDecided::class);
    }

    public function test_an_old_pending_proposal_can_still_be_rejected_with_a_reason(): void
    {
        Notification::fake();
        $change = $this->pending('relative', ['name' => 'Omar', 'relation' => KinshipType::getValues()[0]]);
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
        $this->profile($this->linaUser)->assertSee('Not approved by HR: Please attach the family book.');
    }

    private function add(string $section, array $data, ?UploadedFile $file = null)
    {
        $component = $this->profile($this->linaUser)->call('openEntry', $section);

        foreach ($data as $field => $value) {
            $component->set("entry.{$field}", $value);
        }

        if ($file) {
            $component->set('entryFile', $file);
        }

        return $component->call('saveEntry');
    }

    /** A proposal as the pre-19/11 flow left it in the table. */
    private function pending(string $section, array $data, ?UploadedFile $file = null): ProfileChangeRequest
    {
        $change = ProfileChangeRequest::query()->create([
            'employee_id' => $this->lina->id,
            'section' => $section,
            'proposed' => $data,
            'status' => ProfileChangeRequest::PENDING,
            'requested_by' => $this->linaUser->id,
        ]);

        if ($file) {
            $change->addMedia($file)->toMediaCollection('file', 'local');
        }

        return $change;
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
