<?php

namespace Tests\Feature;

use App\Livewire\MailLists\Index;
use App\Models\MailList;
use App\Models\User;
use App\Services\MailListImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Mail lists: the CSV of the "Mail Lists" sheet (three columns per list, the
 * list's address on the first row) becomes lists with members; every employee
 * reads and emails them, only mail-lists.manage edits.
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
            ['3', 'RAMI@hcsyria.org', '', '', '', ''],
        ];
    }

    public function test_the_sheet_is_read_one_block_of_three_columns_per_list(): void
    {
        $parsed = app(MailListImporter::class)->parse($this->grid());

        $this->assertSame(['team@hcsyria.org', 'managers@hcsyria.org'], array_column($parsed, 'address'));
        $this->assertSame('Team', $parsed[0]['name']);
        // Lower case, de-duplicated; a cell that is not an email is ignored.
        $this->assertSame(['rami@hcsyria.org', 'lina@hcsyria.org'], $parsed[0]['emails']);
        $this->assertSame(['sami@hcsyria.org'], $parsed[1]['emails']);
    }

    public function test_importing_again_replaces_a_lists_members_and_keeps_other_lists(): void
    {
        $importer = app(MailListImporter::class);
        $importer->import($importer->parse($this->grid()));

        $this->assertSame(2, MailList::query()->count());

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
            ->assertDontSeeHtml('data-testid="mail-lists-import"')
            ->call('deleteList', MailList::query()->first()->id)
            ->assertForbidden();

        $this->assertSame(2, MailList::query()->count());

        Livewire::actingAs($hr)->test(Index::class)
            ->assertSeeHtml('data-testid="mail-lists-import"')
            ->call('deleteList', MailList::query()->where('address', 'team@hcsyria.org')->value('id'));

        $this->assertSame(1, MailList::query()->count());
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
