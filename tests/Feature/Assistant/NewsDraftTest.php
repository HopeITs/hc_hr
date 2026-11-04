<?php

namespace Tests\Feature\Assistant;

use App\Enums\News\AudienceType;
use App\Enums\News\NewsStatus;
use App\Enums\News\NewsType;
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
 * A News & Events item asked for in the chat: the model only drafts it
 * through the page's own NewsForm, and only the user's confirmation writes
 * it, exactly as the page's Publish button would. Tags (what it is about,
 * free text) and the audience (who sees it) stay two separate choices.
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

        // The schema tells the subject and the audience apart.
        $properties = $this->draft->declarations($this->hr)[1]['parameters']['properties'];
        $this->assertArrayHasKey('tags', $properties);
        $this->assertSame('array', $properties['tags']['type']);
        $this->assertArrayNotHasKey('related_to', $properties);
        $this->assertArrayNotHasKey('related_id', $properties);
        $this->assertArrayHasKey('audience_type', $properties);
        $this->assertSame(NewsType::values(), $properties['type']['enum']);

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
        $this->assertArrayHasKey('departments', $options);
        $this->assertArrayNotHasKey('related_to', $options);
        $this->assertContains('all', $options['audience_types']);
        // Tag suggestions: tags in use and active city names; never a closed city.
        $this->assertContains('ALP', $options['tag_suggestions']);
        $this->assertNotContains('HMS', $options['tag_suggestions']);
        $this->assertArrayNotHasKey('employees', $options);

        $found = $this->draft->call($this->hr, 'get_news_options', ['employee_search' => 'rana']);
        $this->assertSame(['Rana Haddad'], array_map(fn ($e) => trim($e['name']), $found['employees']));
    }

    public function test_drafting_reports_missing_and_invalid_values_and_writes_nothing(): void
    {
        $result = $this->draft->call($this->hr, 'draft_news', [
            'type' => 'event',
            'title_en' => 'Only English',
            'audience_type' => 'cities',
        ]);

        $this->assertSame('invalid', $result['status']);
        // title_ar, description_ar, the event's start, and an audience without members.
        $this->assertCount(4, $result['errors']);
        $this->assertStringContainsString(__('assistant-news.fields.title_ar'), implode(' ', $result['errors']));
        $this->assertContains(__('news.form.errors.event_start_required'), $result['errors']);
        $this->assertContains(__('news.form.errors.audience_required'), $result['errors']);

        // Too many tags, and a tag that is too long.
        $result = $this->draft->call($this->hr, 'draft_news', $this->valid(['tags' => array_map(fn ($i) => 'Tag '.$i, range(1, 11))]));
        $this->assertSame('invalid', $result['status']);
        $this->assertSame([__('news.form.errors.tags_max', ['max' => 10])], $result['errors']);

        $result = $this->draft->call($this->hr, 'draft_news', $this->valid(['tags' => [str_repeat('x', 61)]]));
        $this->assertSame('invalid', $result['status']);
        $this->assertSame([__('news.form.errors.tag_length', ['max' => 60])], $result['errors']);

        $this->assertSame(0, News::query()->count());
    }

    public function test_ids_the_page_does_not_offer_are_refused(): void
    {
        foreach ([$this->closedCity->id, 999999] as $id) {
            $result = $this->draft->call($this->hr, 'draft_news', $this->valid(['audience_type' => 'cities', 'audience_ids' => [$this->city->id, $id]]));

            $this->assertSame('invalid', $result['status']);
            $this->assertSame([__('news.form.errors.audience_offered')], $result['errors']);
        }

        $inactive = $this->makeEmployee($this->city, 'inactive', ['is_active' => false]);
        $result = $this->draft->call($this->hr, 'draft_news', $this->valid(['audience_type' => 'employees', 'audience_ids' => [$inactive->id]]));
        $this->assertSame('invalid', $result['status']);
        $this->assertSame([__('news.form.errors.audience_offered')], $result['errors']);

        $this->assertSame(0, News::query()->count());
    }

    public function test_event_dates_follow_the_page_rules(): void
    {
        // Display until before the event starts.
        $result = $this->draft->call($this->hr, 'draft_news', $this->valid([
            'type' => 'event',
            'event_starts_at' => '2026-11-10 10:00',
            'display_until' => '2026-11-05',
        ]));
        $this->assertSame([__('news.form.errors.display_until_after_event')], $result['errors']);

        // An end before the start.
        $result = $this->draft->call($this->hr, 'draft_news', $this->valid([
            'type' => 'event',
            'event_starts_at' => '2026-11-10 10:00',
            'event_ends_at' => '2026-11-10 09:00',
        ]));
        $this->assertSame([__('news.form.errors.event_end_after_start')], $result['errors']);

        // The old event_date name still reads as the start.
        $proposal = $this->drafted(['type' => 'event', 'event_date' => '2026-11-10']);
        $this->assertSame('2026-11-10 00:00', $proposal['event_starts_at']);

        // A non-event drops event dates instead of storing them.
        $proposal = $this->drafted(['event_starts_at' => '2026-11-10 10:00']);
        $this->assertNull($proposal['event_starts_at']);
    }

    public function test_a_valid_draft_writes_nothing_and_shows_a_card(): void
    {
        $proposal = $this->drafted();

        $this->assertSame(0, News::query()->count());
        $this->assertSame(['Aleppo', 'Study Zone'], $proposal['tags']);
        $this->assertArrayNotHasKey('related_to', $proposal);
        $this->assertSame('all', $proposal['audience_type']);

        $card = $this->draft->card($proposal);
        $this->assertSame(__('assistant-news.title'), $card['title']);
        $this->assertSame('افتتاح المركز', $card['rows'][__('assistant-news.fields.title_ar')]);
        $this->assertSame('Aleppo, Study Zone', $card['rows'][__('assistant-news.fields.tags')]);
        $this->assertSame(AudienceType::All->label(), $card['rows'][__('assistant-news.fields.audience')]);

        // Tags are normalized: trimmed, spaces collapsed, case-insensitive duplicates dropped.
        $proposal = $this->drafted(['tags' => ['  Study   Zone ', 'study zone', '', 'Training']]);
        $this->assertSame(['Study Zone', 'Training'], $proposal['tags']);

        // A comma-separated string is read as a list.
        $this->assertSame(['Aleppo', 'Training'], $this->drafted(['tags' => 'Aleppo, Training'])['tags']);

        // No tags and no optional values: empty rows are left out.
        $proposal = $this->drafted(['tags' => [], 'title_en' => '', 'description_en' => '', 'display_until' => '']);
        $this->assertSame([], $proposal['tags']);
        $rows = $this->draft->card($proposal)['rows'];
        $this->assertArrayNotHasKey(__('assistant-news.fields.title_en'), $rows);
        $this->assertArrayNotHasKey(__('assistant-news.fields.tags'), $rows);
    }

    public function test_submit_creates_what_the_news_page_publishes(): void
    {
        $this->actingAs($this->hr);
        Livewire::test(NewsCreate::class)
            ->set('form.type', 'announcement')
            ->set('form.title_ar', 'افتتاح المركز')
            ->set('form.title_en', 'Center opening')
            ->set('form.description_ar', 'يسرنا الإعلان عن افتتاح المركز الجديد.')
            ->set('form.description_en', 'We are glad to announce the new center.')
            ->set('form.tags', ['Aleppo', 'Study Zone'])
            ->set('form.audience_type', 'cities')
            ->set('form.audience_ids', [(string) $this->city->id])
            ->set('form.display_until', '2026-11-30')
            ->call('publish')
            ->assertHasNoErrors();
        auth()->forgetGuards();

        $result = $this->draft->submit($this->hr, $this->drafted([
            'type' => 'announcement',
            'audience_type' => 'cities',
            'audience_ids' => [$this->city->id],
        ]), 'token-1');

        $this->assertSame(2, News::query()->count());
        [$byPage, $byChat] = News::query()->orderBy('id')->get()->all();

        $columns = ['type', 'status', 'title_ar', 'title_en', 'description_ar', 'description_en', 'newsable_type', 'newsable_id', 'audience_type', 'display_until', 'event_date', 'event_starts_at', 'created_by', 'updated_by', 'published_at'];
        $this->assertEquals($byPage->only($columns), $byChat->only($columns));
        $this->assertSame(NewsStatus::Published, $byChat->status);
        $this->assertSame($this->hr->id, (int) $byChat->created_by);
        $this->assertSame([$this->city->id], $byChat->audienceTargets()->pluck('target_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['Aleppo', 'Study Zone'], $byChat->tagNames());
        $this->assertSame($byPage->tagNames(), $byChat->tagNames());
        // The legacy Related To is no longer written.
        $this->assertNull($byChat->newsable_type);
        $this->assertNull($byChat->newsable_id);

        $this->assertSame(__('assistant-news.created'), $result['message']);
        $this->assertSame(route('news.show', $byChat), $result['url']);

        $this->assertTrue(Activity::query()->where('log_name', 'news')->where('subject_id', $byChat->id)->exists());
        $this->assertNull(auth()->user());
    }

    public function test_the_page_saves_drafts_and_schedules_and_its_preview_writes_nothing(): void
    {
        $this->actingAs($this->hr);

        $page = Livewire::test(NewsCreate::class)
            ->set('form.title_ar', 'خبر')
            ->set('form.description_ar', 'نص')
            ->call('openPreview')
            ->assertHasNoErrors()
            ->assertSet('previewing', true);
        $this->assertSame(0, News::query()->count());

        // A schedule in the past is refused.
        $page->set('form.publish_mode', 'schedule')
            ->set('form.publish_at', '2026-11-01T09:00')
            ->call('publish')
            ->assertHasErrors(['form.publish_at']);

        $page->set('form.publish_at', '2026-11-05T09:00')->call('saveDraft')->assertHasNoErrors();
        $draft = News::query()->sole();
        $this->assertSame(NewsStatus::Draft, $draft->status);
        $this->assertSame('2026-11-05 09:00', $draft->published_at->format('Y-m-d H:i'));

        Livewire::test(NewsCreate::class, ['news' => $draft])
            ->assertSet('form.publish_mode', 'schedule')
            ->call('publish')
            ->assertHasNoErrors();
        $this->assertSame('scheduled', $draft->fresh()->state()->value);
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
        $proposal = $this->drafted(['audience_type' => 'cities', 'audience_ids' => [$this->city->id]]);

        // The audience's city was closed between the draft and the confirmation.
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
            'display_until' => '2026-11-30',
            'tags' => ['Aleppo', 'Study Zone'],
            'audience_type' => 'all',
        ], $overrides);
    }

    private function drafted(array $overrides = []): array
    {
        $result = $this->draft->call($this->hr, 'draft_news', $this->valid($overrides));
        $this->assertSame('drafted', $result['status'], json_encode($result));

        return $result['proposal'];
    }
}
