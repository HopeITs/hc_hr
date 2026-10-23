<?php

namespace Tests\Feature\Security;

use App\Livewire\Basics\CityModule;
use App\Livewire\EmployeeCards\ManageDigitalId;
use App\Models\City;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\DigitalIdReportedLost;
use App\Services\DigitalIdException;
use App\Services\DigitalIdManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesDigitalIdCards;
use Tests\TestCase;

/**
 * The public digital-ID card and its management (AC-A2-02).
 *
 * Signed policy: docs/ux/access-policy.md §9 — "DEC-02 option" (A), A2D-Q5
 * (who manages cards), A2D-Q6 (link format /ID/[city][program][id]-[index],
 * readable, enumeration risk accepted by HR), A2D-Q7 (city HR hotline) — and
 * docs/ux/decisions.md "DEC-02 revision — card link". This replaces the token
 * and legacy-link design of §5.3; §7 #2–#4 are rewritten accordingly in
 * SecurityAuthorizationTest and PrivateAvatarAccessTest.
 */
class DigitalIdCardPolicyTest extends TestCase
{
    use CreatesDigitalIdCards, RefreshDatabase;

    private const NOT_VALID_EN = 'This card is not valid';

    private const NOT_VALID_AR = 'هذه البطاقة غير صالحة';

    private const ROBOTS = 'noindex, nofollow, noarchive, noimageindex';

    // --- The public card ---------------------------------------------------

    public function test_a_valid_card_renders_its_contents(): void
    {
        Storage::fake('local');
        $aleppo = $this->city('ALP', '001', 'Aleppo Governorate');
        $holder = $this->withPhoto($this->makeCardHolder($aleppo, 'rania', '007', $this->contactDetails()));
        $aleppo->forceFill(['hr_hotline' => '+963 21 555 0100'])->save();

        $response = $this->get($this->cardPath($holder));

        $response->assertOk()
            ->assertSee('Rania')
            ->assertSee('Haddad')
            ->assertSee('Program Officer')
            ->assertSee('Hope Center ALP')
            ->assertSee('Aleppo Governorate')
            ->assertSee('tel:+963944000111', false)
            ->assertSee('https://t.me/rania_hc', false)
            ->assertSee('https://www.linkedin.com/in/rania-haddad', false)
            ->assertSee('+963 21 555 0100')
            // The photo is a short-lived signed link, never the internal route.
            ->assertSee('/ID/'.$holder->id.'/photo?expires=', false)
            ->assertDontSee(route('employees.avatar', $holder), false)
            ->assertHeader('X-Robots-Tag', self::ROBOTS);
    }

    public function test_the_link_has_the_signed_format_and_pads_short_codes(): void
    {
        $city = $this->city('ALP', '1');
        $holder = $this->makeCardHolder($city, 'padded', '7');

        $this->assertSame('/ID/001007'.$holder->id.'-0', $this->cardPath($holder));
        $this->assertSame(url('/ID/001007'.$holder->id.'-0'), $holder->digitalIdUrl());
        $this->get('/ID/001007'.$holder->id.'-0')->assertOk();
    }

    public function test_no_link_is_built_without_a_numeric_code_or_a_current_position(): void
    {
        $city = $this->city('ALP', '001');
        $manager = app(DigitalIdManager::class);

        $noPosition = $this->makeEmployee($city, 'no-position');
        $this->assertNull($noPosition->digitalIdUrl());
        $this->assertSame('digital_id.link_missing.no_position', $manager->missingLinkReason($noPosition));

        // CreatesContractFixtures' default program is coded "CORE".
        $lettered = $this->makeEmployee($city, 'lettered');
        $this->makeContractFor($lettered, [
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);
        $this->assertNull($lettered->fresh()->digitalIdUrl());
        $this->assertSame('digital_id.link_missing.program_code', $manager->missingLinkReason($lettered->fresh()));

        $this->assertSame('001', DigitalIdManager::normaliseCode('1'));
        $this->assertSame('012', DigitalIdManager::normaliseCode(' 12 '));
        $this->assertNull(DigitalIdManager::normaliseCode('0001'));
        $this->assertNull(DigitalIdManager::normaliseCode('A1'));
        $this->assertNull(DigitalIdManager::normaliseCode(null));
    }

    /**
     * One page, Arabic and English, one status, no personal data, for every
     * way a card can be invalid — so the page cannot be used to test whether
     * an id exists.
     */
    public function test_every_invalid_card_gets_the_same_not_valid_page_without_personal_data(): void
    {
        Storage::fake('local');
        $city = $this->city('DIG', '998', 'Digital Governorate');
        $holder = $this->withPhoto($this->makeCardHolder($city, 'rania', '007', $this->contactDetails()));
        $id = $holder->id;

        $disabled = $this->makeCardHolder($city, 'disabled-holder', '007', $this->contactDetails());
        app(DigitalIdManager::class)->disable($disabled);

        $departed = $this->makeCardHolder($city, 'departed-holder', '007', $this->contactDetails());
        $departed->update(['is_active' => false]);

        $reissued = $this->makeCardHolder($city, 'reissued-holder', '007', $this->contactDetails());
        app(DigitalIdManager::class)->reissue($reissued, 0);

        $invalid = [
            'unknown id' => '/ID/998007999999-0',
            'wrong city code' => "/ID/001007{$id}-0",
            'wrong program code' => "/ID/998008{$id}-0",
            'index not issued yet' => "/ID/998007{$id}-1",
            'old index' => "/ID/998007{$reissued->id}-0",
            'disabled card' => "/ID/998007{$disabled->id}-0",
            'departed employee' => "/ID/998007{$departed->id}-0",
            'old username link' => '/ID/rania',
            'letters' => '/ID/abc',
            'codes only' => '/ID/998007',
            'no index' => "/ID/998007{$id}",
            'empty index' => "/ID/998007{$id}-",
            'leading zero index' => "/ID/998007{$id}-00",
            'leading zero id' => "/ID/9980070{$id}-0",
            'extra part' => "/ID/998007{$id}-0-0",
            'index too large' => "/ID/998007{$id}-99999",
            'arabic digits' => '/ID/'.rawurlencode('٩٩٨٠٠٧١-٠'),
            'space' => '/ID/%20',
            'trailing newline' => "/ID/998007{$id}-0%0A",
        ];

        $bodies = [];

        foreach ($invalid as $case => $path) {
            $response = $this->get($path);

            $response->assertNotFound()
                ->assertSee(self::NOT_VALID_EN)
                ->assertSee(self::NOT_VALID_AR)
                ->assertHeader('X-Robots-Tag', self::ROBOTS);

            foreach (['Rania', 'Haddad', '944000111', 'rania_hc', 'rania-haddad', 'Program Officer', 'Digital Governorate', '/photo'] as $personal) {
                $response->assertDontSee($personal, false);
            }

            $bodies[$case] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies), 'Every invalid card must get a byte-identical page.');

        // The holder's own current card still works.
        $this->get("/ID/998007{$id}-0")->assertOk()->assertSee('Rania');
    }

    public function test_an_overlong_segment_is_a_plain_404_without_personal_data(): void
    {
        $city = $this->city('DIG', '998');
        $holder = $this->makeCardHolder($city, 'rania', '007', $this->contactDetails());

        $this->get('/ID/998007'.$holder->id.'-0'.str_repeat('9', 64))
            ->assertNotFound()
            ->assertDontSee('Rania');
    }

    // --- Card lifecycle ----------------------------------------------------

    public function test_the_cardboard_card_works_until_the_plastic_card_is_issued(): void
    {
        $city = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($city, 'cardboard');
        $cardboard = "/ID/001007{$holder->id}-0";

        $this->get($cardboard)->assertOk();

        $this->assertSame(1, app(DigitalIdManager::class)->reissue($holder, 0));

        $this->get($cardboard)->assertNotFound()->assertSee(self::NOT_VALID_EN);
        $this->get("/ID/001007{$holder->id}-1")->assertOk();
    }

    public function test_reissuing_a_plastic_card_ends_the_previous_one(): void
    {
        $city = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($city, 'plastic');
        $manager = app(DigitalIdManager::class);
        $manager->reissue($holder, 0);

        $this->assertSame(2, $manager->reissue($holder, 1));

        $this->get("/ID/001007{$holder->id}-0")->assertNotFound();
        $this->get("/ID/001007{$holder->id}-1")->assertNotFound();
        $this->get("/ID/001007{$holder->id}-2")->assertOk();
    }

    public function test_a_stale_reissue_changes_nothing(): void
    {
        $city = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($city, 'double-click');
        $manager = app(DigitalIdManager::class);
        $manager->reissue($holder, 0);

        // A second click (or a second tab) still showing card no. 0.
        try {
            $manager->reissue($holder->fresh(), 0);
            $this->fail('A stale re-issue must be refused.');
        } catch (DigitalIdException $exception) {
            $this->assertSame('digital_id.errors.stale_index', $exception->getMessage());
        }

        $this->assertSame(1, $holder->fresh()->digital_id_card_index);
        $this->get("/ID/001007{$holder->id}-1")->assertOk();
    }

    public function test_a_disabled_card_is_not_valid_until_it_is_enabled_again(): void
    {
        $city = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($city, 'found-again');
        $card = $this->cardPath($holder);
        $manager = app(DigitalIdManager::class);

        $manager->disable($holder);
        $this->get($card)->assertNotFound()->assertSee(self::NOT_VALID_EN);

        $manager->enable($holder->fresh());
        $this->get($card)->assertOk();
    }

    public function test_deactivating_the_employee_ends_the_card_and_it_cannot_be_enabled(): void
    {
        $city = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($city, 'departing');
        $card = $this->cardPath($holder);

        $holder->update(['is_active' => false]);

        $this->assertFalse($holder->fresh()->digital_id_enabled);
        $this->get($card)->assertNotFound()->assertSee(self::NOT_VALID_EN);

        $this->expectException(DigitalIdException::class);
        app(DigitalIdManager::class)->enable($holder->fresh());
    }

    public function test_moving_to_another_governorate_ends_the_printed_link(): void
    {
        $aleppo = $this->city('ALP', '001');
        $homs = $this->city('HMS', '002');
        $holder = $this->makeCardHolder($aleppo, 'mover');
        $printed = "/ID/001007{$holder->id}-0";

        $this->get($printed)->assertOk();

        $holder->update(['city_id' => $homs->id]);

        $this->get($printed)->assertNotFound()->assertSee(self::NOT_VALID_EN);
        $this->assertSame("/ID/002007{$holder->id}-0", $this->cardPath($holder));
        $this->get("/ID/002007{$holder->id}-0")->assertOk();
    }

    public function test_a_position_in_another_program_ends_the_printed_link(): void
    {
        $city = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($city, 'program-change', '007');
        $printed = "/ID/001007{$holder->id}-0";

        // The new contract starts later, so it is the current one.
        $this->giveCurrentPosition($holder, '009', ['start_date' => now()->subDay()->toDateString()]);

        $this->get($printed)->assertNotFound();
        $this->get("/ID/001009{$holder->id}-0")->assertOk();
    }

    /** Documents a limit of the signed format: nothing ends the link by itself here. */
    public function test_a_new_position_in_the_same_city_and_program_keeps_the_link(): void
    {
        $city = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($city, 'same-program', '007');
        $printed = $this->cardPath($holder);

        $this->giveCurrentPosition($holder, '007', ['start_date' => now()->subDay()->toDateString()]);

        $this->assertSame($printed, $this->cardPath($holder));
        $this->get($printed)->assertOk();
    }

    public function test_the_signed_photo_refuses_a_disabled_card_and_an_inactive_employee(): void
    {
        Storage::fake('local');
        $city = $this->city('ALP', '001');
        $disabled = $this->withPhoto($this->makeCardHolder($city, 'photo-disabled'));
        $departed = $this->withPhoto($this->makeCardHolder($city, 'photo-departed'));

        $signed = fn (Employee $employee) => URL::temporarySignedRoute(
            'digital-id.avatar',
            now()->addMinutes(10),
            ['employee' => $employee->id],
        );

        $disabledLink = $signed($disabled);
        $departedLink = $signed($departed);
        $this->get($disabledLink)->assertOk();
        $this->get($departedLink)->assertOk();

        app(DigitalIdManager::class)->disable($disabled);
        $departed->update(['is_active' => false]);

        $this->get($disabledLink)->assertNotFound();
        $this->get($departedLink)->assertNotFound();
    }

    // --- Emergency number (A2D-Q7) -------------------------------------------

    public function test_the_city_hotline_is_the_emergency_number_with_the_hr_phone_as_fallback(): void
    {
        $city = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($city, 'card-holder');

        $hrDepartment = Department::query()->firstOrCreate(['code' => 'HR'], ['name' => 'Human Resource']);
        $hrOfficer = $this->makeEmployee($city, 'hr-officer', ['work_phone' => '+963 21 444 0000']);
        $this->makeContractFor($hrOfficer, [
            'position_id' => $this->makePosition($city, ['department_id' => $hrDepartment->id])->id,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);

        $this->get($this->cardPath($holder))->assertOk()->assertSee('+963 21 444 0000');

        $city->forceFill(['hr_hotline' => '+963 21 555 0100'])->save();

        $this->get($this->cardPath($holder))
            ->assertOk()
            ->assertSee('+963 21 555 0100')
            ->assertDontSee('+963 21 444 0000');
    }

    public function test_hr_with_basics_update_sets_the_city_hotline(): void
    {
        $city = $this->city('ALP', '001');
        $hr = $this->makeUser($city, 'HR-ALP', ['project-basics.view', 'project-basics.update']);

        Livewire::actingAs($hr)
            ->test(CityModule::class)
            ->call('edit', $city->id)
            ->set('item.hr_hotline', 'not a phone')
            ->call('save')
            ->assertHasErrors(['item.hr_hotline'])
            ->set('item.hr_hotline', ' +963 (21) 555-0100 ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('+963 (21) 555-0100', $city->fresh()->hr_hotline);

        Livewire::actingAs($hr)
            ->test(CityModule::class)
            ->call('edit', $city->id)
            ->set('item.hr_hotline', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($city->fresh()->hr_hotline);
    }

    public function test_the_city_hotline_cannot_be_set_without_basics_update(): void
    {
        $city = $this->city('ALP', '001');
        $viewer = $this->makeUser($city, 'employee', ['project-basics.view']);

        Livewire::actingAs($viewer)
            ->test(CityModule::class)
            ->call('edit', $city->id)
            ->assertForbidden();

        Livewire::actingAs($viewer)
            ->test(CityModule::class)
            ->set('item.id', $city->id)
            ->set('item.hr_hotline', '+963 21 555 0100')
            ->call('save')
            ->assertForbidden();

        $this->assertNull($city->fresh()->hr_hotline);
    }

    // --- Card management (A2D-Q5) --------------------------------------------

    public function test_hr_of_the_employees_city_manages_the_card(): void
    {
        $aleppo = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($aleppo, 'managed');
        $hr = $this->makeUser($aleppo, 'HR-ALP');

        Livewire::actingAs($hr)
            ->test(ManageDigitalId::class, ['employee' => $holder])
            ->assertSee(url("/ID/001007{$holder->id}-0"))
            ->assertSee(__('digital_id.actions.issue_plastic'))
            ->call('reissue', 0)
            ->assertOk()
            ->assertSee(url("/ID/001007{$holder->id}-1"))
            ->call('disable')
            ->assertOk()
            ->call('enable')
            ->assertOk();

        $holder->refresh();
        $this->assertSame(1, $holder->digital_id_card_index);
        $this->assertTrue($holder->digital_id_enabled);
    }

    public function test_the_panel_is_on_the_profile_page_for_hr_and_the_owner_only(): void
    {
        $aleppo = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($aleppo, 'profiled');
        $link = url("/ID/001007{$holder->id}-0");

        $this->actingAs($this->makeUser($aleppo, 'HR-ALP', ['employees.view']))
            ->get(route('employees.show', $holder->username))
            ->assertOk()
            ->assertSee($link)
            ->assertSee(__('digital_id.actions.issue_plastic'));

        $this->actingAs($this->makeUser($aleppo, 'employee', [], $holder))
            ->get(route('my-profile'))
            ->assertOk()
            ->assertSee($link)
            ->assertSee(__('digital_id.actions.report_lost'))
            ->assertDontSee(__('digital_id.actions.issue_plastic'));

        $this->actingAs($this->makeUser($aleppo, 'employee'))
            ->get(route('employees.show', $holder->username))
            ->assertOk()
            ->assertDontSee($link)
            ->assertDontSee(__('digital_id.panel.title'));
    }

    public function test_national_hr_and_super_admin_manage_cards_in_any_city(): void
    {
        $syria = $this->city('SYR', '000');
        $aleppo = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($aleppo, 'anywhere');

        Livewire::actingAs($this->makeUser($syria, 'HR-SYR'))
            ->test(ManageDigitalId::class, ['employee' => $holder])
            ->call('reissue', 0)
            ->assertOk();

        Livewire::actingAs($this->makeUser($syria, 'super-admin'))
            ->test(ManageDigitalId::class, ['employee' => $holder])
            ->call('reissue', 1)
            ->assertOk()
            ->call('disable')
            ->assertOk();

        $holder->refresh();
        $this->assertSame(2, $holder->digital_id_card_index);
        $this->assertFalse($holder->digital_id_enabled);
    }

    public function test_regional_hr_of_another_city_and_non_hr_roles_cannot_manage_the_card(): void
    {
        $aleppo = $this->city('ALP', '001');
        $homs = $this->city('HMS', '002');
        $holder = $this->makeCardHolder($aleppo, 'out-of-scope');

        $outsiders = [
            'HR-HMS' => $this->makeUser($homs, 'HR-HMS', ['employees.update']),
            'manager' => $this->makeUser($aleppo, 'manager', ['employees.update']),
            'general-manager' => $this->makeUser($aleppo, 'general-manager', ['employees.update']),
            'employee' => $this->makeUser($aleppo, 'employee'),
        ];

        foreach ($outsiders as $user) {
            // Not even the panel: these viewers are neither managers nor the owner.
            $this->panel($user, $holder)->assertDontSee('/ID/', false);

            $this->panel($user, $holder)->call('reissue', 0)->assertForbidden();
            $this->panel($user, $holder)->call('disable')->assertForbidden();
            $this->panel($user, $holder)->call('enable')->assertForbidden();
            $this->panel($user, $holder)->call('reportLost')->assertForbidden();
        }

        $holder->refresh();
        $this->assertSame(0, $holder->digital_id_card_index);
        $this->assertTrue($holder->digital_id_enabled);
    }

    public function test_staff_report_their_own_card_lost_and_hr_of_their_city_is_notified(): void
    {
        Notification::fake();
        $aleppo = $this->city('ALP', '001');
        $homs = $this->city('HMS', '002');
        $holder = $this->makeCardHolder($aleppo, 'reporter');
        $self = $this->makeUser($aleppo, 'employee', [], $holder);
        $aleppoHr = $this->makeUser($aleppo, 'HR-ALP');
        $homsHr = $this->makeUser($homs, 'HR-HMS');

        $this->panel($self, $holder)
            ->assertSee(__('digital_id.actions.report_lost'))
            ->assertDontSee(__('digital_id.actions.disable'))
            ->call('reportLost')
            ->assertOk()
            // A second click within the hour does not notify again.
            ->call('reportLost')
            ->assertOk();

        Notification::assertSentToTimes($aleppoHr, DigitalIdReportedLost::class, 1);
        Notification::assertNotSentTo($homsHr, DigitalIdReportedLost::class);
        Notification::assertNotSentTo($self, DigitalIdReportedLost::class);

        // The report changes nothing; only HR acts.
        $this->panel($self, $holder)->call('disable')->assertForbidden();
        $this->panel($self, $holder)->call('reissue', 0)->assertForbidden();
        $this->panel($self, $holder)->call('enable')->assertForbidden();

        $holder->refresh();
        $this->assertTrue($holder->digital_id_enabled);
        $this->assertSame(0, $holder->digital_id_card_index);
        $this->get($this->cardPath($holder))->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'description' => 'digital_id_reported_lost',
            'subject_id' => $holder->id,
            'causer_id' => $self->id,
        ]);
    }

    public function test_a_colleague_cannot_report_someone_elses_card(): void
    {
        Notification::fake();
        $aleppo = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($aleppo, 'holder');
        $colleague = $this->makeUser($aleppo, 'employee');

        Livewire::actingAs($colleague)
            ->test(ManageDigitalId::class, ['employee' => $holder])
            ->call('reportLost')
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_an_active_role_switch_is_honoured_by_the_next_action(): void
    {
        $aleppo = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($aleppo, 'switch-subject');
        $user = $this->assignInactiveRole($this->makeUser($aleppo, 'HR-ALP'), 'employee');

        $reissue = $this->panel($user, $holder)->assertSee(__('digital_id.actions.issue_plastic'));
        $disable = $this->panel($user, $holder)->assertSee(__('digital_id.actions.disable'));

        // Switched to the employee role in another tab after the panel loaded.
        $user->setActiveRole(Role::findByName('employee', 'web')->id);

        $reissue->call('reissue', 0)->assertForbidden();
        $disable->call('disable')->assertForbidden();

        $this->assertSame(0, $holder->fresh()->digital_id_card_index);
        $this->assertTrue($holder->fresh()->digital_id_enabled);
    }

    public function test_every_card_change_is_logged_to_the_security_log_with_actor_and_employee(): void
    {
        $aleppo = $this->city('ALP', '001');
        $holder = $this->makeCardHolder($aleppo, 'audited');
        $hr = $this->makeUser($aleppo, 'HR-ALP');

        Livewire::actingAs($hr)
            ->test(ManageDigitalId::class, ['employee' => $holder])
            ->call('reissue', 0)
            ->call('disable')
            ->call('enable');

        foreach (['digital_id_reissued', 'digital_id_disabled', 'digital_id_enabled'] as $event) {
            $entry = Activity::query()
                ->where('log_name', 'security')
                ->where('description', $event)
                ->sole();

            $this->assertSame($holder->id, (int) $entry->subject_id);
            $this->assertSame(Employee::class, $entry->subject_type);
            $this->assertSame($hr->id, (int) $entry->causer_id);
            $this->assertSame($holder->id, (int) $entry->properties['employee_id']);
        }

        $reissued = Activity::query()->where('description', 'digital_id_reissued')->sole();
        $this->assertSame(0, $reissued->properties['previous_index']);
        $this->assertSame(1, $reissued->properties['card_index']);

        // The retired token column is never written to the log.
        $token = $holder->fresh()->digital_id_token;
        $this->assertNotEmpty($token);

        foreach (Activity::query()->get() as $entry) {
            $this->assertStringNotContainsString($token, json_encode($entry->properties));
        }

        foreach (Activity::query()->where('log_name', 'security')->get() as $entry) {
            $this->assertStringNotContainsString('token', json_encode($entry->properties));
        }
    }

    public function test_reissue_is_refused_for_an_inactive_employee_and_without_a_link(): void
    {
        $aleppo = $this->city('ALP', '001');
        $manager = app(DigitalIdManager::class);

        $departed = $this->makeCardHolder($aleppo, 'gone');
        $departed->update(['is_active' => false]);

        try {
            $manager->reissue($departed->fresh(), 0);
            $this->fail('An inactive employee must not get a card.');
        } catch (DigitalIdException $exception) {
            $this->assertSame('digital_id.errors.inactive_employee', $exception->getMessage());
        }

        $noPosition = $this->makeEmployee($aleppo, 'no-position');

        try {
            $manager->reissue($noPosition, 0);
            $this->fail('No card without a link.');
        } catch (DigitalIdException $exception) {
            $this->assertSame('digital_id.errors.no_card_link', $exception->getMessage());
        }

        $this->assertSame(0, $departed->fresh()->digital_id_card_index);
        $this->assertSame(0, $noPosition->fresh()->digital_id_card_index);
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * A freshly mounted panel. A call answered with 403 leaves a Livewire test
     * component without a snapshot, so each refused call gets its own.
     */
    private function panel(User $user, Employee $employee): Testable
    {
        return Livewire::actingAs($user)->test(ManageDigitalId::class, ['employee' => $employee]);
    }

    private function city(string $slug, string $code, ?string $name = null): City
    {
        $city = $this->makeCity($slug, $code);

        if ($name !== null) {
            $city->forceFill(['name_en' => $name])->save();
        }

        return $city;
    }

    /** @return array<string, string> */
    private function contactDetails(): array
    {
        return [
            'first_name_en' => 'Rania',
            'last_name_en' => 'Haddad',
            'work_phone' => '+963 944 000 111',
            'telegram_work' => '@rania_hc',
            'linkedin' => 'rania-haddad',
        ];
    }

    private function withPhoto(Employee $employee): Employee
    {
        $image = imagecreatetruecolor(20, 20);
        $path = tempnam(sys_get_temp_dir(), 'av');
        imagejpeg($image, $path);
        imagedestroy($image);

        $employee->addMedia(new UploadedFile($path, 'p.jpg', null, null, true))
            ->toMediaCollection('avatar', 'local');

        return $employee->fresh();
    }
}
