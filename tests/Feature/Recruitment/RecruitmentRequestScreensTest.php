<?php

namespace Tests\Feature\Recruitment;

use App\Enums\RecruitmentRequestStatus;
use App\Enums\RecruitmentRequestType;
use App\Livewire\Recruitment\Requests\Details;
use App\Livewire\Recruitment\Requests\Index;
use App\Livewire\Recruitment\Requests\RequestTable;
use App\Models\City;
use App\Models\RecruitmentRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

class RecruitmentRequestScreensTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_list_and_details_are_unavailable_without_permission(): void
    {
        $city = $this->makeCity('HMS', '002');
        $user = $this->makeUser($city, 'employee');
        $request = $this->request($city, $user, 'RR-PRIVATE');

        Livewire::actingAs($user)->test(Index::class)->assertForbidden();
        Livewire::test(RequestTable::class)->assertForbidden();
        Livewire::test(Details::class, ['requestId' => $request->id])->assertForbidden();
    }

    public function test_regional_hr_list_and_detail_use_the_same_scope(): void
    {
        $city = $this->makeCity('HMS', '002');
        $otherCity = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-HMS', ['recruitment-requests.view']);
        $allowed = $this->request($city, $user, 'RR-LOCAL');
        $outside = $this->request($otherCity, $user, 'RR-OUTSIDE');

        Livewire::actingAs($user)->test(RequestTable::class)
            ->assertSee('RR-LOCAL')->assertDontSee('RR-OUTSIDE');
        Livewire::test(Details::class, ['requestId' => $allowed->id])->assertSee('RR-LOCAL');
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Details::class, ['requestId' => $outside->id]);
    }

    public function test_assigned_governorate_is_visible_to_hr_latakia(): void
    {
        $latakia = $this->makeCity('LAT', '004');
        $tartous = $this->makeCity('TAR', '005');
        $user = $this->makeUser($latakia, 'HR-LAT', ['recruitment-requests.view']);
        $request = $this->request($tartous, $user, 'RR-TARTOUS');

        Livewire::actingAs($user)->test(RequestTable::class)->assertSee('RR-TARTOUS');
        Livewire::test(Details::class, ['requestId' => $request->id])->assertSee('RR-TARTOUS');
    }

    public function test_details_recheck_scope_on_livewire_refresh(): void
    {
        $city = $this->makeCity('HMS', '002');
        $otherCity = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-HMS', ['recruitment-requests.view']);
        $request = $this->request($city, $user, 'RR-MOVED');
        $screen = Livewire::actingAs($user)->test(Details::class, ['requestId' => $request->id]);

        $request->update(['scope_city_id' => $otherCity->id]);

        $this->expectException(ModelNotFoundException::class);
        $screen->call('$refresh');
    }

    public function test_details_escape_comments_and_display_the_recorded_timeline(): void
    {
        $city = $this->makeCity('HMS', '002');
        $user = $this->makeUser($city, 'HR-SYR');
        $request = $this->request($city, $user, 'RR-HISTORY');
        $request->comments()->create(['user_id' => $user->id, 'body' => 'Visible comment']);

        // Free text written by one user and read by another is the whole of the
        // attack surface on this page, so it is checked on text the page really
        // renders rather than on a field it happens to hold.
        $request->comments()->create(['user_id' => $user->id, 'body' => '<script>alert(1)</script>']);

        Livewire::actingAs($user)->test(Details::class, ['requestId' => $request->id])
            ->assertSee('Visible comment')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_localized_routes_render_and_reject_out_of_scope_ids(): void
    {
        $city = $this->makeCity('HMS', '002');
        $otherCity = $this->makeCity('DMS', '003');
        $user = $this->makeUser($city, 'HR-HMS', ['recruitment-requests.view']);
        $request = $this->request($city, $user, 'RR-ROUTE');
        $outside = $this->request($otherCity, $user, 'RR-HIDDEN');

        // Locale prefixes are registered from the incoming URL at application
        // boot. CLI tests use the registered route and bypass redirects only.
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
        ]);
        app()->setLocale('en');
        $this->actingAs($user)->get(route('recruitment.requests.index'))->assertOk()->assertSee('Recruitment requests');
        $this->get(route('recruitment.requests.show', $request->id))->assertOk()->assertSee('RR-ROUTE');
        $this->get(route('recruitment.requests.show', $outside->id))->assertNotFound();
        app()->setLocale('ar');
        Livewire::test(Details::class, ['requestId' => $request->id])->assertSee('تفاصيل الطلب');
    }

    public function test_version_selection_is_read_only_and_cannot_cross_requests(): void
    {
        $city = $this->makeCity('HMS', '002');
        $user = $this->makeUser($city, 'HR-SYR');
        $request = $this->request($city, $user, 'RR-VERSIONS');
        $first = $request->versions()->create(['version_no' => 1, 'snapshot_json' => [], 'created_by_user_id' => $user->id, 'submitted_at' => now()]);
        $first->positionDetail()->create(['role_title_en' => 'Earlier title']);
        $second = $request->versions()->create(['version_no' => 2, 'snapshot_json' => [], 'created_by_user_id' => $user->id, 'submitted_at' => now()]);
        $second->positionDetail()->create(['role_title_en' => 'Latest title']);
        $other = $this->request($city, $user, 'RR-OTHER');
        $foreign = $other->versions()->create(['version_no' => 1, 'snapshot_json' => [], 'created_by_user_id' => $user->id, 'submitted_at' => now()]);

        $screen = Livewire::actingAs($user)->test(Details::class, ['requestId' => $request->id])
            ->assertSee('Latest title')->assertDontSee('Earlier title')
            ->set('versionId', $first->id)->assertSee('Earlier title')->assertDontSee('Latest title');
        $this->assertSame('Earlier title', $first->positionDetail->fresh()->role_title_en);
        $this->expectException(ModelNotFoundException::class);
        $screen->set('versionId', $foreign->id);
    }

    private function request(City $city, User $user, string $number): RecruitmentRequest
    {
        return RecruitmentRequest::query()->create([
            'request_number' => $number,
            'requester_user_id' => $user->id,
            'request_type' => RecruitmentRequestType::FillVacancy,
            'status' => RecruitmentRequestStatus::Draft,
            'scope_city_id' => $city->id,
        ]);
    }
}
