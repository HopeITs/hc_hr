<?php

namespace Tests\Feature\Security;

use App\Models\Employee;
use App\Services\DigitalIdManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesDigitalIdCards;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Employee photographs are protection-sensitive. They must be unreachable
 * anonymously, unreachable across cities, and reachable on the public ID card
 * only through a signed link minted after the card resolved.
 */
class PrivateAvatarAccessTest extends TestCase
{
    use CreatesDigitalIdCards, CreatesHrFixtures, RefreshDatabase;

    public function test_the_photo_is_not_stored_on_the_public_disk(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->withPhoto($this->makeEmployee($city, 'photo-subject'));

        $media = $employee->getFirstMedia('avatar');

        $this->assertSame('local', $media->disk);
        // storage/app/private, which no web server route maps to.
        $this->assertStringContainsString('private', str_replace('\\', '/', $media->getPath()));
    }

    public function test_an_anonymous_visitor_cannot_download_a_photo(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->withPhoto($this->makeEmployee($city, 'photo-subject'));

        $this->get(route('employees.avatar', $employee))->assertRedirect(route('login'));
    }

    /**
     * The expectation changed with the signed A2-D policy (access-policy.md
     * §4.3, A2D-Q4, §8): the photo follows the Directory tier, so an Aleppo
     * HR officer sees an ACTIVE Homs colleague's face like every other member
     * of staff does (this is what broke 134 photos on that officer's
     * dashboard, X1). The city boundary still holds for what is not Directory
     * tier: a FORMER Homs employee's photo is refused, with the same 404 as
     * an id that does not exist (§4.4).
     */
    public function test_hr_in_another_city_sees_active_photos_but_not_former_employees(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $homs = $this->makeCity('HMS', '002');

        $active = $this->withPhoto($this->makeEmployee($homs, 'homs-subject'));
        $former = $this->withPhoto($this->makeEmployee($homs, 'homs-former', ['is_active' => false]));
        $aleppoHr = $this->makeUser($aleppo, 'HR-ALP', ['employees.view']);

        $this->actingAs($aleppoHr)
            ->get(route('employees.avatar', $active))
            ->assertOk();

        $this->actingAs($aleppoHr)
            ->get(route('employees.avatar', $former))
            ->assertNotFound();

        $this->actingAs($aleppoHr)
            ->get(route('employees.avatar', ['employee' => 999999]))
            ->assertNotFound();
    }

    public function test_hr_in_the_same_city_can_download_a_photo_privately(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $subject = $this->withPhoto($this->makeEmployee($aleppo, 'aleppo-subject'));
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['employees.view']);

        $response = $this->actingAs($hr)->get(route('employees.avatar', $subject));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_an_employee_role_user_without_employees_view_can_still_see_a_colleagues_photo(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $subject = $this->withPhoto($this->makeEmployee($aleppo, 'aleppo-subject'));
        $viewer = $this->makeUser($aleppo, 'employee', []);

        $this->actingAs($viewer)
            ->get(route('employees.avatar', $subject))
            ->assertOk();
    }

    public function test_the_digital_id_photo_requires_a_valid_signature(): void
    {
        $city = $this->makeCity('DIG', '998');
        $employee = $this->withPhoto($this->makeEmployee($city, 'digital-subject'));

        // Unsigned: refused outright.
        $this->get('/ID/'.$employee->id.'/photo')->assertForbidden();

        $signed = URL::temporarySignedRoute(
            'digital-id.avatar',
            now()->addMinutes(10),
            ['employee' => $employee->id],
        );

        $this->get($signed)->assertOk();
    }

    public function test_an_expired_digital_id_photo_link_is_refused(): void
    {
        $city = $this->makeCity('DIG', '998');
        $employee = $this->withPhoto($this->makeEmployee($city, 'digital-subject'));

        $signed = URL::temporarySignedRoute(
            'digital-id.avatar',
            now()->addMinutes(5),
            ['employee' => $employee->id],
        );

        $this->travel(10)->minutes();

        $this->get($signed)->assertForbidden();
    }

    public function test_deactivating_an_employee_kills_an_unexpired_photo_link(): void
    {
        $city = $this->makeCity('DIG', '998');
        $employee = $this->withPhoto($this->makeEmployee($city, 'digital-subject'));

        $signed = URL::temporarySignedRoute(
            'digital-id.avatar',
            now()->addMinutes(10),
            ['employee' => $employee->id],
        );

        $employee->update(['is_active' => false]);

        $this->get($signed)->assertNotFound();
    }

    /** access-policy.md §7 #3, card link per §9 A2D-Q6 (no token any more). */
    public function test_a_disabled_digital_id_stops_resolving(): void
    {
        $city = $this->makeCity('DIG', '998');
        $employee = $this->makeCardHolder($city, 'digital-subject');
        $card = $this->cardPath($employee);

        $this->get($card)->assertOk();

        app(DigitalIdManager::class)->disable($employee);

        $this->get($card)->assertNotFound()->assertSee('This card is not valid');
    }

    /**
     * access-policy.md §7 #4 under §9 A2D-Q6: re-issue replaces token rotation.
     * The printed card's index stops resolving at once; only the new one does.
     */
    public function test_reissuing_the_card_invalidates_the_printed_card(): void
    {
        $city = $this->makeCity('DIG', '998');
        $employee = $this->makeCardHolder($city, 'digital-subject');
        $printed = $this->cardPath($employee);

        app(DigitalIdManager::class)->reissue($employee, 0);

        $this->get($printed)->assertNotFound()->assertSee('This card is not valid');
        $this->assertStringEndsWith('-1', $this->cardPath($employee));
        $this->get($this->cardPath($employee))->assertOk();
    }

    public function test_the_public_card_is_marked_noindex(): void
    {
        $city = $this->makeCity('DIG', '998');
        $employee = $this->makeCardHolder($city, 'digital-subject');

        $this->get($this->cardPath($employee))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, noimageindex');
    }

    private function withPhoto(Employee $employee): Employee
    {
        $image = imagecreatetruecolor(50, 50);
        $path = tempnam(sys_get_temp_dir(), 'av');
        imagejpeg($image, $path);
        imagedestroy($image);

        $employee->addMedia(new UploadedFile($path, 'p.jpg', null, null, true))
            ->toMediaCollection('avatar', 'local');

        return $employee->fresh();
    }
}
