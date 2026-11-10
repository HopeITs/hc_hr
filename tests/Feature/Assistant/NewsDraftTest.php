<?php

namespace Tests\Feature\Assistant;

use App\Livewire\News\NewsCreate;
use App\Models\City;
use App\Models\News;
use App\Models\User;
use App\Services\Assistant\Drafts\DraftRegistry;
use App\Services\Assistant\Drafts\NewsDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * A news item asked for in the chat: the model only drafts it through the
 * News page's own form, and only the user's confirmation writes it, exactly
 * as the page would.
 */
class NewsDraftTest extends TestCase
{
    use CreatesHrFixtures;
    use RefreshDatabase;

    private City $city;

    private City $closedCity;

    private User $hr;

    private NewsDraft $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));

        $this->city = $this->makeCity('ALP', '001');
        $this->closedCity = $this->makeCity('HMS', '002');
        $this->closedCity->forceFill(['is_active' => false])->save();

        $this->hr = $this->makeUser($this->city, 'HR-ALP', ['news.view', 'news.create', 'news.update']);
        $this->draft = app(NewsDraft::class);
    }

    public function test_the_tools_are_declared_only_to_roles_that_may_create_news(): void
    {
        $this->assertSame(['get_news_options', 'draft_news'], array_column($this->draft->declarations($this->hr), 'name'));
        $this->assertSame('news', app(DraftRegistry::class)->owner($this->hr, 'draft_news')?->type());

        // No permission.
        $plain = $this->makeUser($this->city, 'HR-HMS', ['news.view']);
        $this->assertSame([], $this->draft->declarations($plain));
        $this->assertNull(app(DraftRegistry::class)->owner($plain, 'draft_news'));

        // The permission, but a role the create screen refuses.
        $employee = $this->makeUser($this->city, 'employee', ['news.create']);
        $this->assertSame([], $this->draft->declarations($employee));
        $this->assertArrayHasKey('error', $this->draft->call($employee, 'draft_news', $this->valid()));
    }

    public function test_options_list_only_what_the_page_offers(): void
    {
        $this->makeEmployee($this->city, 'rana', ['first_name_en' => 'Rana', 'last_name_en' => 'Haddad']);
        $this->makeEmployee($this->city, 'gone', ['first_name_en' => 'Rami', 'last_name_en' => 'Gone', 'is_active' => false]);

        $options = $this->draft->call($this->hr, 'get_news_options', []);

        $this->assertSame([$this->city->id], array_column($options['cities'], 'id'));
        $this->assertArrayNotHasKey('employees', $options);

        $found = $this->draft->call($this->hr, 'get_news_options', ['employee_search' => 'rana']);
        $this->assertSame(['Rana Haddad'], array_map(fn ($e) => trim($e['name']), $found['employees']));
    }

    public function test_drafting_reports_missing_and_invalid_values_and_writes_nothing(): void
    {
        $result = $this->draft->call($this->hr, 'draft_news', [
            'title_en' => 'Only English',
            'event_date' => 'not a date',
            'target_type' => 'galaxy',
        ]);

        $this->assertSame('invalid', $result['status']);
        $this->assertCount(5, $result['errors']); // title_ar, description_ar, event_date, target type and its id
        $this->assertStringContainsString(__('assistant-news.fields.title_ar'), implode(' ', $result['errors']));

        // A target type without its id.
        $result = $this->draft->call($this->hr, 'draft_news', $this->valid(['target_id' => null]));
        $this->assertSame('invalid', $result['status']);

        $this->assertSame(0, News::query()->count());
    }

    public function test_targets_the_page_does_not_offer_are_refused(): void
    {
        foreach ([$this->closedCity->id, 999999] as $id) {
            $result = $this->draft->call($this->hr, 'draft_news', $this->valid(['target_id' => $id]));

            $this->assertSame('invalid', $result['status']);
            $this->assertSame([__('assistant-news.errors.target')], $result['errors']);
        }

        $inactive = $this->makeEmployee($this->city, 'inactive', ['is_active' => false]);
        $result = $this->draft->call($this->hr, 'draft_news', $this->valid(['target_type' => 'employee', 'target_id' => $inactive->id]));
        $this->assertSame('invalid', $result['status']);

        $this->assertSame(0, News::query()->count());
    }

    public function test_a_valid_draft_writes_nothing_and_shows_a_card(): void
    {
        $proposal = $this->drafted();

        $this->assertSame(0, News::query()->count());
        $this->assertSame('city', $proposal['target_type']);
        $this->assertSame($this->city->id, $proposal['target_id']);

        $card = $this->draft->card($proposal);
        $this->assertSame(__('assistant-news.title'), $card['title']);
        $this->assertSame('افتتاح المركز', $card['rows'][__('assistant-news.fields.title_ar')]);
        $this->assertStringContainsString('ALP', $card['rows'][__('assistant-news.fields.target')]);

        // Everyone, with no optional values: empty rows are left out.
        $proposal = $this->drafted(['target_type' => 'all', 'title_en' => '', 'description_en' => '', 'display_until' => '']);
        $this->assertNull($proposal['target_id']);
        $this->assertArrayNotHasKey(__('assistant-news.fields.title_en'), $this->draft->card($proposal)['rows']);
    }

    public function test_submit_creates_what_the_news_page_creates(): void
    {
        $this->actingAs($this->hr);
        Livewire::test(NewsCreate::class)
            ->set('form.title_ar', 'افتتاح المركز')
            ->set('form.title_en', 'Center opening')
            ->set('form.description_ar', 'يسرنا الإعلان عن افتتاح المركز الجديد.')
            ->set('form.description_en', 'We are glad to announce the new center.')
            ->set('form.newsable_type', City::class)
            ->set('form.newsable_id', $this->city->id)
            ->set('form.event_date', '2026-11-10')
            ->set('form.display_until', '2026-11-30')
            ->call('save')
            ->assertHasNoErrors();
        auth()->forgetGuards();

        $result = $this->draft->submit($this->hr, $this->drafted(), 'token-1');

        $this->assertSame(2, News::query()->count());
        [$byPage, $byChat] = News::query()->orderBy('id')->get()->all();

        $columns = ['title_ar', 'title_en', 'description_ar', 'description_en', 'newsable_type', 'newsable_id', 'display_until', 'event_date', 'created_by', 'updated_by', 'published_at'];
        $this->assertEquals($byPage->only($columns), $byChat->only($columns));
        $this->assertSame($this->hr->id, (int) $byChat->created_by);

        $this->assertSame(__('assistant-news.created'), $result['message']);
        $this->assertSame(route('news.edit', $byChat), $result['url']);

        $this->assertTrue(Activity::query()->where('log_name', 'news')->where('subject_id', $byChat->id)->exists());
        $this->assertNull(auth()->user());
    }

    public function test_submitting_the_same_draft_twice_creates_one_item(): void
    {
        $proposal = $this->drafted();

        $this->draft->submit($this->hr, $proposal, 'token-1');
        $this->draft->submit($this->hr, $proposal, 'token-1');

        $this->assertSame(1, News::query()->count());

        $this->draft->submit($this->hr, $proposal, 'token-2');
        $this->assertSame(2, News::query()->count());
    }

    public function test_submit_is_refused_once_the_permission_is_lost(): void
    {
        $proposal = $this->drafted();

        Role::findByName('HR-ALP', 'web')->revokePermissionTo('news.create');
        $hr = User::query()->find($this->hr->id);

        try {
            $this->draft->submit($hr, $proposal, 'token-1');
            $this->fail('A user without news.create created news.');
        } catch (ValidationException $e) {
            $this->assertSame([__('assistant.draft.forbidden')], $e->errors()['draft']);
        }

        $this->assertSame(0, News::query()->count());
    }

    public function test_submit_validates_again(): void
    {
        $proposal = $this->drafted();

        // The target was closed between the draft and the confirmation.
        $this->city->forceFill(['is_active' => false])->save();

        $this->expectException(ValidationException::class);

        try {
            $this->draft->submit($this->hr, $proposal, 'token-1');
        } finally {
            $this->assertSame(0, News::query()->count());
        }
    }

    public function test_a_tampered_proposal_is_validated_like_a_draft(): void
    {
        $proposal = $this->drafted();
        $proposal['title_ar'] = '';

        $this->expectException(ValidationException::class);

        try {
            $this->draft->submit($this->hr, $proposal, 'token-1');
        } finally {
            $this->assertSame(0, News::query()->count());
        }
    }

    private function valid(array $overrides = []): array
    {
        return array_merge([
            'title_ar' => 'افتتاح المركز',
            'title_en' => 'Center opening',
            'description_ar' => 'يسرنا الإعلان عن افتتاح المركز الجديد.',
            'description_en' => 'We are glad to announce the new center.',
            'event_date' => '2026-11-10',
            'display_until' => '2026-11-30',
            'target_type' => 'city',
            'target_id' => $this->city->id,
        ], $overrides);
    }

    private function drafted(array $overrides = []): array
    {
        $result = $this->draft->call($this->hr, 'draft_news', $this->valid($overrides));
        $this->assertSame('drafted', $result['status'], json_encode($result));

        return $result['proposal'];
    }
}
