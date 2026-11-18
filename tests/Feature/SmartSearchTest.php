<?php

namespace Tests\Feature;

use App\Livewire\Assistant\Chat;
use App\Livewire\Layout\GlobalSearch;
use App\Models\Employee;
use App\Models\Folder;
use App\Models\MailList;
use App\Models\MessageTemplate;
use App\Models\Position;
use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Support\Search\SmartSearch;
use App\Support\SearchText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * Smart search: more kinds of result (each through the scope of the page that lists it), people by
 * number, phone and email with the best match first and a near match for a slip, quick actions on a
 * person, kind chips, and "Ask the assistant" for a question.
 */
class SmartSearchTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private function person($city, string $username, string $first, string $last, array $employee = [], array $contract = [], ?string $email = null): Employee
    {
        $person = $this->makeEmployee($city, $username, ['first_name_en' => $first, 'last_name_en' => $last, 'first_name_ar' => $first, 'last_name_ar' => $last] + $employee);
        $this->makeContractFor($person, $contract);

        if ($email) {
            User::factory()->create(['employee_id' => $person->id, 'email' => $email]);
        }

        return $person->fresh();
    }

    public function test_positions_contracts_and_mail_lists_are_found_with_their_actions(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($aleppo, 'HR-SYR', ['positions.view', 'positions.update', 'contracts.view', 'recruitment-requests.manage']);
        $anna = $this->person($aleppo, 'anna', 'Anna', 'Alpha');
        MailList::query()->create(['name' => 'Managers', 'address' => 'managers@hcsyria.org', 'position' => 0]);

        // A position, with a free seat: "Open vacancy" and "Edit".
        Livewire::actingAs($hr)->test(GlobalSearch::class)
            ->set('q', 'Program Officer')
            ->assertSeeHtml('data-group="positions"')
            ->assertSee('Open vacancy')
            ->assertSeeHtml(htmlspecialchars(route('recruitment.requests.create', ['type' => 1, 'position_id' => Position::query()->value('id')])));

        // A contract, found by the person's name (never any pay): the contract number and the position.
        $contractId = $anna->contracts()->value('id') ?? \App\Models\Contract::query()->where('employee_id', $anna->id)->value('id');
        Livewire::actingAs($hr)->test(GlobalSearch::class)
            ->set('q', 'Alpha')
            ->assertSeeHtml('data-group="contracts"')
            ->assertSeeHtml(route('contracts.show', $contractId))
            ->assertSee('#'.$contractId);

        // A mailing list: email it or copy its address.
        Livewire::actingAs($hr)->test(GlobalSearch::class)
            ->set('q', 'managers')
            ->assertSeeHtml('data-group="lists"')
            ->assertSeeHtml('href="mailto:managers@hcsyria.org"')
            ->assertSee('Copy');
    }

    public function test_each_kind_follows_the_role_and_the_scope_of_its_own_page(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $damascus = $this->makeCity('DMS', '002');
        $this->person($aleppo, 'anna', 'Anna', 'Alpha');
        $dani = $this->person($damascus, 'dani', 'Dani', 'Delta');

        // An employee has no contracts page: no contracts, leave, hiring or applicant results at all.
        $employee = $this->makeUser($aleppo, 'employee', []);
        $types = SmartSearch::types($employee);
        $this->assertSame([], array_values(array_intersect($types, ['contracts', 'leave', 'requests', 'applicants'])));
        $this->assertContains('lists', $types);

        Livewire::actingAs($employee)->test(GlobalSearch::class)
            ->set('q', 'Delta')
            ->assertDontSeeHtml('data-group="contracts"');

        // A city HR sees the contracts of its own city only.
        $aleppoHr = $this->makeUser($aleppo, 'HR-ALP', ['contracts.view']);
        Livewire::actingAs($aleppoHr)->test(GlobalSearch::class)
            ->set('q', 'Delta')
            ->assertDontSeeHtml('data-group="contracts"');
        Livewire::actingAs($aleppoHr)->test(GlobalSearch::class)
            ->set('q', 'Alpha')
            ->assertSeeHtml('data-group="contracts"');

        // Head office sees both.
        $head = $this->makeUser($aleppo, 'HR-SYR', ['contracts.view']);
        Livewire::actingAs($head)->test(GlobalSearch::class)
            ->set('q', 'Delta')
            ->assertSeeHtml('data-group="contracts"')
            ->assertSeeHtml(route('contracts.show', \App\Models\Contract::query()->where('employee_id', $dani->id)->value('id')));
    }

    public function test_a_folder_is_found_only_by_who_made_it_or_was_given_it(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $owner = $this->makeUser($aleppo, 'employee', ['archives.view']);
        $given = $this->makeUser($aleppo, 'employee', ['archives.view']);
        $other = $this->makeUser($aleppo, 'employee', ['archives.view']);
        $folder = Folder::query()->create(['name' => 'Budget 2026', 'user_id' => $owner->id, 'is_shared' => false]);
        $folder->users()->sync([$owner->id, $given->id]);

        foreach ([$owner, $given] as $user) {
            Livewire::actingAs($user)->test(GlobalSearch::class)->set('q', 'Budget')
                ->assertSeeHtml('data-group="folders"')->assertSee('Budget 2026');
        }

        Livewire::actingAs($other)->test(GlobalSearch::class)->set('q', 'Budget')
            ->assertDontSeeHtml('data-group="folders"');
    }

    public function test_people_are_found_by_number_phone_and_email_and_the_best_name_match_comes_first(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $viewer = $this->makeUser($aleppo, 'employee', []);

        // Employee number 177, a phone with spaces, and a work email.
        $mai = Employee::query()->forceCreate([
            'id' => 177, 'city_id' => $aleppo->id, 'first_name_en' => 'Mai', 'first_name_ar' => 'مي', 'last_name_en' => 'Youssef', 'last_name_ar' => 'يوسف',
            'username' => 'mai', 'is_active' => true, 'work_phone' => '+963 944 123 456',
        ]);
        $this->makeContractFor($mai);
        User::factory()->create(['employee_id' => $mai->id, 'email' => 'mai.youssef@hcsyria.org']);

        foreach (['177', '0944123456', '944 123 456', 'mai.youssef@'] as $typed) {
            Livewire::actingAs($viewer)->test(GlobalSearch::class)
                ->set('q', $typed)
                ->assertSee('Mai Youssef');
        }

        // «sami» starts one name and only sits inside the other: the first comes first.
        $this->person($aleppo, 'rasami', 'Rasami', 'Zed');
        $this->person($aleppo, 'sami', 'Sami', 'Khoury');
        $html = Livewire::actingAs($viewer)->test(GlobalSearch::class)->set('q', 'sami')->html();

        $this->assertLessThan(strpos($html, 'Rasami Zed'), strpos($html, 'Sami Khoury'));
    }

    public function test_a_slip_in_the_typing_finds_a_near_match_and_an_unrelated_word_finds_nobody(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $viewer = $this->makeUser($aleppo, 'employee', []);
        $this->person($aleppo, 'mohammad', 'Mohammad', 'Haddad');

        // «mohamad» is not inside «Mohammad», so the plain search misses it; the near match does not.
        Livewire::actingAs($viewer)->test(GlobalSearch::class)
            ->set('q', 'mohamad')
            ->assertSee('Mohammad Haddad')
            ->assertSeeHtml('data-testid="near-match"');

        Livewire::actingAs($viewer)->test(GlobalSearch::class)
            ->set('q', 'zxqwvb')
            ->assertDontSee('Mohammad Haddad');
    }

    public function test_a_person_carries_email_with_the_manager_on_cc_whatsapp_and_templates(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $judy = $this->person($aleppo, 'judy', 'Judy', 'Manager', [], [], 'judy@hcsyria.org');
        $judyPosition = Position::query()->whereKey($judy->contracts()->value('position_id') ?? \App\Models\Contract::query()->where('employee_id', $judy->id)->value('position_id'))->first();
        $team = $this->makePosition($aleppo, ['direct_manager_id' => $judyPosition->id, 'title_en' => 'Specialist']);
        $mai = $this->person($aleppo, 'maya', 'Maya', 'Specialist', ['work_phone' => '0944111222'], ['position_id' => $team->id], 'maya@hcsyria.org');
        MessageTemplate::query()->create(['name' => 'Hello', 'channel' => 'email', 'language' => 'en', 'subject' => 'Hi {first_name}', 'body' => 'Dear {name}']);

        $hr = $this->makeUser($aleppo, 'HR-SYR', ['message-templates.use']);

        $page = Livewire::actingAs($hr)->test(GlobalSearch::class)->set('q', 'Maya');
        $page->assertSeeHtml('mailto:maya%40hcsyria.org?cc=judy%40hcsyria.org')
            ->assertSeeHtml('https://wa.me/963944111222')
            ->assertSeeHtml('data-testid="search-template-action"');

        // The template menu is filled in on request, for that person.
        $page->call('templatesFor', $mai->id)->assertReturned(fn (array $rows) => count($rows) === 1
            && $rows[0]['name'] === 'Hello'
            && str_contains($rows[0]['url'], 'mailto:maya@hcsyria.org')
            && str_contains($rows[0]['url'], 'subject=Hi%20Maya'));

        // Without message-templates.use there is no template action, and the call is refused.
        $plain = $this->makeUser($aleppo, 'employee', []);
        Livewire::actingAs($plain)->test(GlobalSearch::class)->set('q', 'Maya')
            ->assertSeeHtml('mailto:maya%40hcsyria.org')
            ->assertDontSeeHtml('data-testid="search-template-action"');
        Livewire::actingAs($plain)->test(GlobalSearch::class)->call('templatesFor', $mai->id)->assertForbidden();
    }

    public function test_chips_narrow_the_search_to_one_kind_and_an_unknown_kind_means_all(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($aleppo, 'HR-SYR', ['contracts.view', 'positions.view']);
        $this->person($aleppo, 'anna', 'Anna', 'Alpha');

        $page = Livewire::actingAs($hr)->test(GlobalSearch::class)->set('q', 'Anna')
            ->assertSeeHtml('data-testid="search-chips"')
            ->assertSeeHtml('data-chip="contracts"')
            ->assertSee('Anna Alpha')
            ->assertSeeHtml('data-group="contracts"');

        $page->call('setType', 'people')
            ->assertSee('Anna Alpha')
            ->assertDontSeeHtml('data-group="contracts"');

        $page->call('setType', 'contracts')
            ->assertSeeHtml('data-group="contracts"')
            ->assertDontSeeHtml('data-testid="search-person"');

        $page->call('setType', 'nonsense')->assertSet('type', 'all');

        // Nothing typed, no chips.
        Livewire::actingAs($hr)->test(GlobalSearch::class)->assertDontSeeHtml('data-testid="search-chips"');
    }

    public function test_ask_the_assistant_appears_for_a_question_and_only_when_it_is_on(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $viewer = $this->makeUser($aleppo, 'employee', []);

        $this->mock(Assistant::class, fn ($mock) => $mock->shouldReceive('isAvailable')->andReturn(true));

        Livewire::actingAs($viewer)->test(GlobalSearch::class)
            ->set('q', 'who is on leave tomorrow?')
            ->assertSeeHtml('data-testid="search-ask"')
            ->assertSeeHtml(route('assistant', ['q' => 'who is on leave tomorrow?']));

        // A name is not a question.
        Livewire::actingAs($viewer)->test(GlobalSearch::class)
            ->set('q', 'Mai Youssef')
            ->assertDontSeeHtml('data-testid="search-ask"');

        // The page takes the question and puts it in the box: nothing is sent by arriving.
        Livewire::actingAs($viewer)->withQueryParams(['q' => 'who is on leave tomorrow?'])->test(Chat::class)
            ->assertSet('question', 'who is on leave tomorrow?');

        // Off: no line.
        $this->mock(Assistant::class, fn ($mock) => $mock->shouldReceive('isAvailable')->andReturn(false));
        Livewire::actingAs($viewer)->test(GlobalSearch::class)
            ->set('q', 'who is on leave tomorrow?')
            ->assertDontSeeHtml('data-testid="search-ask"');
    }

    public function test_what_reads_as_a_question_and_how_alike_two_names_are(): void
    {
        foreach (['who is on leave', 'show contracts', 'contracts ending soon?', 'كم موظف في حلب', 'متى ينتهي عقد مي', 'anna alpha team lead', 'عقود حلب؟'] as $question) {
            $this->assertTrue(GlobalSearch::looksLikeQuestion($question), $question);
        }

        foreach (['anna', 'mai youssef', 'managers', 'عبد المسيح', 'hope aleppo'] as $name) {
            $this->assertFalse(GlobalSearch::looksLikeQuestion($name), $name);
        }

        $this->assertGreaterThanOrEqual(GlobalSearch::NEAR_MATCH, SearchText::similarity('mohamad', 'Mohammad'));
        $this->assertGreaterThanOrEqual(GlobalSearch::NEAR_MATCH, SearchText::similarity('عبدالمسيح', 'عبد المسيح'));
        $this->assertLessThan(GlobalSearch::NEAR_MATCH, SearchText::similarity('anna', 'carla'));
        $this->assertSame(['ahmad', 'alhaj'], SearchText::words('  Ahmad  Al_%Haj  '));
    }
}
