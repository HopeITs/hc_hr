<?php

namespace Tests\Feature;

use App\Livewire\Layout\GlobalSearch;
use App\Livewire\Policies\Index;
use App\Models\PolicyDocument;
use App\Services\Assistant\PolicyLibrary;
use App\Support\Search\Highlight;
use App\Support\Search\SmartSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * The policies in the header search: the passage that answers the typed words appears under the
 * policy's title, for every employee, and opens the policy's page at that passage with the
 * words marked.
 */
class PolicySearchTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private PolicyDocument $security;

    private PolicyDocument $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->security = PolicyDocument::query()->create([
            'title' => 'سياسة الأمن المعلوماتي',
            'content' => "تُحفظ كلمات المرور في مكان آمن ولا تُشارك مع أحد.\nيُمنع نقل الأجهزة الإلكترونية خارج مقر العمل إلا بموافقة خطية من المدير المباشر.\nيجب تسجيل كل جهاز في سجل الأصول.",
        ]);

        $this->finance = PolicyDocument::query()->create([
            'title' => 'السياسة المالية',
            'content' => "تُصرف النفقات بعد موافقة المحاسب المسؤول.\nتُرفق الفواتير الأصلية بكل طلب صرف.",
        ]);

        PolicyDocument::query()->create(['title' => 'Leave policy', 'content' => 'Annual leave is requested through the system and approved by the direct manager.']);
        PolicyDocument::query()->create(['title' => 'Empty draft', 'content' => null]);
    }

    public function test_a_typed_sentence_finds_the_passage_that_answers_it(): void
    {
        $hits = app(PolicyLibrary::class)->search('بدي سياسة نقل الكترونيات');

        $this->assertCount(1, $hits);
        $this->assertSame($this->security->id, $hits[0]['id']);
        // The three lines are one passage of the policy: the excerpt is the sentence that answers.
        $this->assertSame(0, $hits[0]['position']);
        $this->assertStringContainsString('نقل الأجهزة الإلكترونية', $hits[0]['snippet']);
        $this->assertStringNotContainsString('كلمات المرور', $hits[0]['snippet']);
    }

    public function test_accounting_and_english_are_found_too_and_unknown_words_find_nothing(): void
    {
        $library = app(PolicyLibrary::class);

        $this->assertSame($this->finance->id, $library->search('الفواتير الأصلية')[0]['id']);
        $this->assertSame($this->finance->id, $library->search('المحاسب')[0]['id']);
        $this->assertSame('Leave policy', $library->search('annual leaves')[0]['title']);
        $this->assertSame([], $library->search('زرافة فضائية'));
        $this->assertSame([], $library->search('ا'));
    }

    public function test_every_employee_sees_the_policies_group_in_the_header_search(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($city, 'employee', []);

        $this->assertContains('policies', SmartSearch::types($employee));

        $expected = route('policies.show', ['document' => $this->security->id, 'q' => 'نقل الكترونيات', 'p' => 0]);

        Livewire::actingAs($employee)->test(GlobalSearch::class)
            ->set('q', 'نقل الكترونيات')
            ->assertSeeHtml('data-group="policies"')
            ->assertSee('سياسة الأمن المعلوماتي')
            ->assertSee('نقل الأجهزة الإلكترونية')
            ->assertSeeHtml(htmlspecialchars($expected));

        // The chip narrows the box to the policies alone.
        Livewire::actingAs($employee)->test(GlobalSearch::class)
            ->set('q', 'نقل الكترونيات')
            ->call('setType', 'policies')
            ->assertSeeHtml('data-group="policies"');
    }

    public function test_the_policy_page_marks_the_words_and_points_at_the_passage(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($city, 'employee', []);

        $this->actingAs($employee)
            ->get(route('policies.show', ['document' => $this->security->id, 'q' => 'نقل الكترونيات', 'p' => 0]))
            ->assertOk()
            ->assertSee('سياسة الأمن المعلوماتي')
            ->assertSeeHtml('<mark class="policy-mark">نقل</mark>')
            ->assertSeeHtml('<mark class="policy-mark">الإلكترونية</mark>')
            ->assertSeeHtml('id="policy-passage-0"')
            ->assertSeeHtml('data-testid="policy-target"');
    }

    public function test_a_policy_without_text_or_a_missing_one_is_not_found(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($city, 'employee', []);

        $this->actingAs($employee)->get(route('policies.show', ['document' => PolicyDocument::query()->where('title', 'Empty draft')->value('id')]))->assertNotFound();
        $this->actingAs($employee)->get(route('policies.show', ['document' => 99999]))->assertNotFound();
    }

    public function test_the_list_page_searches_and_lists_only_readable_policies(): void
    {
        $city = $this->makeCity('ALP', '001');
        $employee = $this->makeUser($city, 'employee', []);

        $this->actingAs($employee)->get(route('policies.index'))
            ->assertOk()
            ->assertSee('السياسة المالية')
            ->assertDontSee('Empty draft');

        Livewire::actingAs($employee)->test(Index::class)
            ->set('q', 'نقل الكترونيات')
            ->assertSeeHtml('data-testid="policy-hit"')
            ->assertSee('نقل الأجهزة الإلكترونية')
            ->set('q', 'زرافة فضائية')
            ->assertSee('زرافة فضائية');
    }

    public function test_highlight_escapes_the_text_and_ignores_spelling_variants(): void
    {
        $html = Highlight::html('<b>الإلكترونيّة</b> & الألكترونية', ['الكترونيه']);

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertSame(2, substr_count($html, '<mark'));
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertSame('a &amp; b', Highlight::html('a & b', []));
    }
}
