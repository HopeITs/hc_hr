<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Enums\Nationality;
use App\Livewire\Employee\EmployeeCreate;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class ScannedDocumentReaderTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private const ID_FIELDS = [
        'national_number' => '02010345678', 'first_name_ar' => 'سامر', 'last_name_ar' => 'الحداد',
        'father_name_ar' => 'جورج', 'mother_name_ar' => 'ليلى خوري', 'first_name_en' => 'Samer',
        'last_name_en' => 'Al-Haddad', 'father_name_en' => 'George', 'mother_name_en' => 'Laila Khoury',
        'pob_ar' => 'حلب', 'pob_en' => 'Aleppo', 'dob' => '1990-03-15', 'gender' => 'male',
        'civil_registry_office' => 'العزيزية', 'civil_registry_number' => '247',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.post_gen' => ['url' => 'https://post-gen.test', 'key' => 'secret-key', 'timeout' => 30]]);
    }

    public function test_an_id_card_fills_only_the_empty_profile_fields(): void
    {
        $this->fakeReading(self::ID_FIELDS, docType: 'بطاقة شخصية سورية');
        $city = $this->makeCity('ALP', '001');

        Livewire::actingAs($this->hr($city, 'employees.create'))->test(EmployeeCreate::class)
            ->assertSee(__('documents.reader.id-title'))
            ->set('infoForm.first_name_en', 'Sam')
            ->set('documentScans', [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.jpg')])
            ->call('readIdCard')
            ->assertHasNoErrors()
            ->assertSet('infoForm.first_name_en', 'Sam')
            ->assertSet('infoForm.first_name_ar', 'سامر')
            ->assertSet('infoForm.national_number', '02010345678')
            ->assertSet('infoForm.dob', '1990-03-15')
            ->assertSet('infoForm.gender', Gender::Male)
            ->assertSet('infoForm.nationality', Nationality::Syrian)
            ->assertSet('infoForm.civil_registry_number', '247')
            ->assertSet('idCardReading.filled', 15)  // 14 card fields + gender + nationality, less the typed first name
            ->assertSet('documentScans', []);
    }

    public function test_a_failed_reading_shows_an_error_and_changes_nothing(): void
    {
        Http::fake(['post-gen.test/*' => Http::response(' {"error":"daily limit reached"}')]);
        $city = $this->makeCity('ALP', '001');

        Livewire::actingAs($this->hr($city, 'employees.create'))->test(EmployeeCreate::class)
            ->set('documentScans', [UploadedFile::fake()->image('front.jpg')])
            ->call('readIdCard')
            ->assertHasErrors('documentScans')
            ->assertSet('infoForm.first_name_ar', null)
            ->assertSet('idCardReading', null);
    }

    public function test_only_images_and_pdfs_are_sent(): void
    {
        Http::fake();
        $city = $this->makeCity('ALP', '001');

        Livewire::actingAs($this->hr($city, 'employees.create'))->test(EmployeeCreate::class)
            ->set('documentScans', [UploadedFile::fake()->create('card.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])
            ->call('readIdCard')
            ->assertHasErrors('documentScans.0');

        Http::assertNothingSent();
    }

    public function test_without_a_key_the_reader_is_not_offered(): void
    {
        config(['services.post_gen.key' => null]);
        $city = $this->makeCity('ALP', '001');

        Livewire::actingAs($this->hr($city, 'employees.create'))->test(EmployeeCreate::class)
            ->assertDontSee(__('documents.reader.id-title'))
            ->call('readIdCard')
            ->assertStatus(404);
    }

    private function hr(City $city, string $permission): User
    {
        return $this->makeUser($city, 'HR-'.$city->slug, [$permission]);
    }

    private function fakeReading(array $fields, string $docType = ''): void
    {
        Http::fake(['post-gen.test/extract/id' => Http::response('   '.json_encode([
            'ok' => true, 'kind' => 'id', 'doc_type' => $docType, 'fields' => $fields, 'text' => '', 'lite' => false,
        ]))]);
    }
}
