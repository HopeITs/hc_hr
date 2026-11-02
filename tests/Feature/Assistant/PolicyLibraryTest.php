<?php

namespace Tests\Feature\Assistant;

use App\Models\PolicyDocument;
use App\Services\Assistant\PolicyLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The policy text picked for a question: whole while it fits, the matching
 * passages (with their neighbours) when it does not, from a cached index
 * that follows the policies as they change.
 */
class PolicyLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['assistant.policy_char_budget' => 150000]);
    }

    public function test_everything_goes_in_whole_when_it_fits(): void
    {
        $this->policy('B policy', 'Second.');
        $this->policy('A policy', 'First.');
        $this->policy('Empty', '');

        $this->assertSame("### A policy\nFirst.\n\n### B policy\nSecond.", app(PolicyLibrary::class)->contextFor('anything'));
    }

    public function test_nothing_readable_gives_an_empty_context(): void
    {
        $this->policy('Empty', '');

        $this->assertSame('', app(PolicyLibrary::class)->contextFor('anything'));
    }

    public function test_over_budget_the_matching_passage_is_picked_with_its_neighbours(): void
    {
        $this->policy('Travel policy', "The daily travel allowance is twenty dollars.\n".$this->filler('transport'));
        $this->policy('Leave policy', $this->filler('text')."\nMaternity leave lasts ninety days.\n".$this->filler('clause'));

        config(['assistant.policy_char_budget' => 4000]);

        $context = app(PolicyLibrary::class)->contextFor("How long is the employee's maternity leave?");

        $this->assertStringStartsWith("Policies on file: Leave policy، Travel policy.\n\n", $context);
        $this->assertStringContainsString("### Leave policy\n", $context);
        $this->assertStringContainsString('Maternity leave lasts ninety days.', $context);
        $this->assertStringNotContainsString('### Travel policy', $context);
        $this->assertLessThanOrEqual(4000 + 200, mb_strlen($context));

        // The passages on either side of the clause come along, run on without a gap marker.
        $this->assertStringContainsString("text\nMaternity leave lasts ninety days.\nclause clause", $context);
    }

    public function test_the_policy_whose_title_matches_wins_over_a_stray_mention(): void
    {
        // "annual leave" appears once in the travel policy, and the leave policy is all about it.
        $this->policy('Travel policy', "A trip is not deducted from annual leave.\n".$this->filler('transport'));
        $this->policy('Annual leave policy', "Annual leave is twenty-one days a year.\n".$this->filler('clause'));

        config(['assistant.policy_char_budget' => 1200]);

        $context = app(PolicyLibrary::class)->contextFor('How many annual leave days do I get?');

        $this->assertStringContainsString('Annual leave is twenty-one days a year.', $context);
        $this->assertStringNotContainsString('A trip is not deducted', $context);
    }

    public function test_a_question_matching_nothing_gets_only_the_titles(): void
    {
        $this->policy('Travel policy', str_repeat("The daily travel allowance.\n", 400));

        config(['assistant.policy_char_budget' => 1000]);

        $this->assertSame(
            "Policies on file: Travel policy.\nNo passage matches this question.",
            app(PolicyLibrary::class)->contextFor('hello'),
        );
    }

    public function test_arabic_spelling_variants_meet_on_one_stem(): void
    {
        $this->policy('سياسة السفر', "بدل السفر اليومي عشرون دولاراً.\n".$this->filler('تنقل'));
        $this->policy('سياسة الإجازات', $this->filler('نص')."\nإجازة الأمومة مدتها تسعون يوماً.\n".$this->filler('بند'));

        config(['assistant.policy_char_budget' => 3000]);

        // The article with a joined preposition, the plural, diacritics, tatweel and alef variants.
        $context = app(PolicyLibrary::class)->contextFor('ما المقصود بالإجازات؟ كم مدة اِجـازه الامومه؟');

        $this->assertStringContainsString("### سياسة الإجازات\n", $context);
        $this->assertStringContainsString('إجازة الأمومة مدتها تسعون يوماً.', $context);
        $this->assertStringNotContainsString('### سياسة السفر', $context);
    }

    public function test_english_plurals_and_possessives_meet_on_one_stem(): void
    {
        $this->policy('Travel policy', "Travel allowances are paid monthly.\n".$this->filler('transport'));
        $this->policy('Leave policy', "Unused leaves are carried over to the next year.\n".$this->filler('clause'));

        config(['assistant.policy_char_budget' => 1200]);

        $context = app(PolicyLibrary::class)->contextFor("Is an employee's unused leave carried over?");

        $this->assertStringContainsString('Unused leaves are carried over', $context);
        $this->assertStringNotContainsString('allowances', $context);
    }

    public function test_the_index_is_built_once_and_reused_from_the_cache(): void
    {
        $this->policy('Leave policy', $this->filler('leave'));
        $this->policy('Travel policy', $this->filler('travel'));

        config(['assistant.policy_char_budget' => 2000]);

        DB::enableQueryLog();

        app(PolicyLibrary::class)->contextFor('leave');
        $first = DB::getQueryLog();
        DB::flushQueryLog();

        $second = app(PolicyLibrary::class)->contextFor('travel');
        $again = DB::getQueryLog();

        // First time: the fingerprint and the documents; afterwards only the fingerprint.
        $this->assertCount(2, $first);
        $this->assertCount(1, $again);
        $this->assertStringContainsString('length(content)', $again[0]['query']);
        $this->assertStringContainsString('travel travel', $second);
    }

    public function test_the_cache_is_dropped_when_a_policy_changes(): void
    {
        $leave = $this->policy('Leave policy', $this->filler('leave'));
        $this->policy('Travel policy', $this->filler('travel'));

        config(['assistant.policy_char_budget' => 2000]);

        $this->assertStringNotContainsString('ninety', app(PolicyLibrary::class)->contextFor('maternity'));

        $this->travel(1)->minutes();
        $leave->update(['content' => "Maternity leave lasts ninety days.\n".$this->filler('leave')]);

        $this->assertStringContainsString('Maternity leave lasts ninety days.', app(PolicyLibrary::class)->contextFor('maternity'));

        // Removing a policy drops it from the cached set too.
        $this->travel(1)->minutes();
        $leave->delete();

        $this->assertStringNotContainsString('Leave policy', app(PolicyLibrary::class)->contextFor('maternity'));
    }

    private function policy(string $title, string $content): PolicyDocument
    {
        return PolicyDocument::query()->create(['source' => 'panel', 'title' => $title, 'content' => $content]);
    }

    /** Thirty lines of one word, several passages' worth. */
    private function filler(string $word): string
    {
        return implode("\n", array_fill(0, 30, trim(str_repeat($word.' ', 60))));
    }
}
