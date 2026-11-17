<?php

namespace Tests\Feature;

use App\Livewire\MailLists\Index;
use App\Models\MailList;
use App\Models\MailListMember;
use App\Models\Permission;
use App\Models\User;
use App\Services\MailListImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Mail lists: the "Mail Lists" sheet's layout (three columns per list, the
 * list's address on the first row, further lists stacked below) becomes lists
 * with members; every employee reads and emails them, mail-lists.manage edits,
 * and one person (mail-lists.confirm) confirms additions made in the app.
 */
class MailListsTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    private function grid(): array
    {
        return [
            ['team@hcsyria.org', '', '', 'managers@hcsyria.org', '', ''],
            ['#', 'Emails', '', '#', 'Emails', ''],
            ['1', 'Rami@hcsyria.org', '', '1', 'sami@hcsyria.org', ''],
            ['2', 'lina@hcsyria.org', '', '2', 'not an email', ''],
            ['3', 'RAMI@hcsyria.org', '', 'it@hcsyria.org', '', ''],
            ['', '', '', '#', 'Emails', ''],
            ['', '', '', '1', 'omar@hcsyria.org', ''],
        ];
    }

    public function test_the_sheet_is_read_one_block_of_three_columns_per_list_with_lists_stacked_below(): void
    {
        $parsed = app(MailListImporter::class)->parse($this->grid());

        $this->assertSame(['team@hcsyria.org', 'managers@hcsyria.org', 'it@hcsyria.org'], array_column($parsed, 'address'));
        $this->assertSame('Team', $parsed[0]['name']);
        // Lower case, de-duplicated; a cell that is not an email is ignored.
        $this->assertSame(['rami@hcsyria.org', 'lina@hcsyria.org'], $parsed[0]['emails']);
        $this->assertSame(['sami@hcsyria.org'], $parsed[1]['emails']);
        // A list stacked under another has its own members.
        $this->assertSame(['omar@hcsyria.org'], $parsed[2]['emails']);
    }

    public function test_the_data_file_loads_and_members_loaded_are_already_in_the_group(): void
    {
        $result = app(MailListImporter::class)->importFile(database_path('data/mail-lists.json'));

        $this->assertGreaterThan(20, $result['lists']);
        $this->assertSame($result['members'], MailListMember::query()->where('status', MailListMember::ACTIVE)->count());
        $this->assertSame(0, MailListMember::query()->pending()->count());
    }

    public function test_importing_again_replaces_a_lists_members_and_keeps_other_lists(): void
    {
        $importer = app(MailListImporter::class);
        $importer->import($importer->parse($this->grid()));

        $importer->import([['address' => 'team@hcsyria.org', 'name' => 'Team', 'emails' => ['lina@hcsyria.org', 'omar@hcsyria.org']]]);

        $team = MailList::query()->where('address', 'team@hcsyria.org')->sole();
        $this->assertEqualsCanonicalizing(['lina@hcsyria.org', 'omar@hcsyria.org'], $team->members()->pluck('email')->all());
        $this->assertSame(['sami@hcsyria.org'], MailList::query()->where('address', 'managers@hcsyria.org')->sole()->members()->pluck('email')->all());
    }

    public function test_every_employee_sees_and_emails_a_list_but_only_hr_edits(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $importer = app(MailListImporter::class);
        $importer->import($importer->parse($this->grid()));
        $employee = $this->makeUser($aleppo, 'employee', []);
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['mail-lists.manage']);

        Livewire::actingAs($employee)->test(Index::class)
            ->assertSee('managers@hcsyria.org')
            ->assertSeeHtml('href="mailto:team@hcsyria.org"')
            ->call('deleteList', MailList::query()->first()->id)
            ->assertForbidden();

        $this->assertSame(3, MailList::query()->count());

        Livewire::actingAs($hr)->test(Index::class)
            ->call('deleteList', MailList::query()->where('address', 'team@hcsyria.org')->value('id'));

        $this->assertSame(2, MailList::query()->count());
    }

    public function test_a_member_added_here_is_pending_and_only_the_confirmer_sees_and_clears_it(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $importer = app(MailListImporter::class);
        $importer->import($importer->parse($this->grid()));
        $list = MailList::query()->where('address', 'team@hcsyria.org')->sole();

        $hr = $this->makeUser($aleppo, 'HR-ALP', ['mail-lists.manage']);
        // The person who holds the control panel: the permission is hers, not her role's.
        $mai = $this->makeUser($aleppo, 'employee', []);
        $mai->givePermissionTo(Permission::findOrCreate('mail-lists.confirm', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Livewire::actingAs($hr)->test(Index::class)
            ->set("newEmail.{$list->id}", 'New.Hire@hcsyria.org')
            ->call('addMember', $list->id)
            ->assertDontSeeHtml('data-testid="mail-lists-pending"');

        $member = $list->members()->where('email', 'new.hire@hcsyria.org')->sole();
        $this->assertSame(MailListMember::PENDING, $member->status);
        $this->assertSame($hr->id, $member->added_by_user_id);

        // HR manages the lists but cannot confirm.
        Livewire::actingAs($hr)->test(Index::class)->call('confirmMember', $member->id)->assertForbidden();
        $this->assertTrue($member->fresh()->isPending());

        Livewire::actingAs($mai)->test(Index::class)
            ->assertSeeHtml('data-testid="mail-lists-pending"')
            ->assertSee('new.hire@hcsyria.org')
            ->call('confirmEmail', 'new.hire@hcsyria.org');

        $member->refresh();
        $this->assertSame([MailListMember::ACTIVE, $mai->id], [$member->status, $member->confirmed_by_user_id]);
        $this->assertNotNull($member->confirmed_at);
    }

    public function test_a_new_account_on_the_organisation_domain_joins_the_all_staff_list_as_pending(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $team = MailList::query()->create(['name' => 'Team', 'address' => MailList::ALL_STAFF_ADDRESS, 'position' => 0]);
        $employee = $this->makeEmployee($aleppo, 'newcomer');

        User::factory()->create(['employee_id' => $employee->id, 'email' => 'new.comer@hcsyria.org']);
        User::factory()->create(['email' => 'someone@gmail.com']);

        $this->assertSame(['new.comer@hcsyria.org'], $team->members()->pluck('email')->all());
        $this->assertTrue($team->members()->sole()->isPending());
    }

    public function test_search_finds_a_list_by_name_or_by_a_person_on_it_and_groups_narrow_the_page(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $importer = app(MailListImporter::class);
        $importer->import($importer->parse($this->grid()));
        $employee = $this->makeUser($aleppo, 'employee', []);

        // A person: only the lists they are on, with who matched.
        Livewire::actingAs($employee)->test(Index::class)
            ->set('search', 'sami@')
            ->assertSee('managers@hcsyria.org')
            ->assertDontSee('team@hcsyria.org')
            ->assertSee('sami@hcsyria.org');

        // A list by its name.
        Livewire::actingAs($employee)->test(Index::class)
            ->set('search', 'team')
            ->assertSee('team@hcsyria.org')
            ->assertDontSee('managers@hcsyria.org');

        // A group: team and managers are organisation-wide, it@ is a function.
        Livewire::actingAs($employee)->test(Index::class)
            ->call('setCategory', 'functions')
            ->assertSee('it@hcsyria.org')
            ->assertDontSee('managers@hcsyria.org')
            ->call('setCategory', 'nonsense')
            ->assertSee('managers@hcsyria.org');

        $this->assertSame('cities', MailList::categoryOf('hope.aleppo@hcsyria.org'));
        $this->assertSame('projects', MailList::categoryOf('micro.me@hcsyria.org'));
        $this->assertSame('other', MailList::categoryOf('brand.new@hcsyria.org'));
    }

    public function test_the_members_panel_opens_one_list_and_hides_external_addresses_from_staff(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $importer = app(MailListImporter::class);
        $importer->import($importer->parse($this->grid()));
        MailList::query()->where('address', 'managers@hcsyria.org')->sole()->members()->create(['email' => 'parent@gmail.com']);
        $managersId = MailList::query()->where('address', 'managers@hcsyria.org')->value('id');
        $employee = $this->makeUser($aleppo, 'employee', []);
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['mail-lists.manage']);

        Livewire::actingAs($employee)->test(Index::class)
            ->assertDontSeeHtml('data-testid="mail-list-panel"')
            ->call('openList', $managersId)
            ->assertSeeHtml('data-testid="mail-list-panel"')
            ->assertSee('sami@hcsyria.org')
            ->assertDontSee('parent@gmail.com')
            ->call('closeList')
            ->assertDontSeeHtml('data-testid="mail-list-panel"')
            ->call('openList', 999999)
            ->assertDontSeeHtml('data-testid="mail-list-panel"');

        // HR who manage the lists see the address, to be able to remove it.
        Livewire::actingAs($hr)->test(Index::class)->call('openList', $managersId)->assertSee('parent@gmail.com');
    }

    public function test_a_list_resolves_to_the_accounts_of_its_members(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $employee = $this->makeEmployee($aleppo, 'rami');
        User::factory()->create(['employee_id' => $employee->id, 'email' => 'rami@hcsyria.org']);
        $importer = app(MailListImporter::class);
        $importer->import($importer->parse($this->grid()));

        $ids = MailList::query()->where('address', 'team@hcsyria.org')->sole()->users()->pluck('id')->all();

        $this->assertSame([User::query()->where('email', 'rami@hcsyria.org')->value('id')], $ids);
    }
}
