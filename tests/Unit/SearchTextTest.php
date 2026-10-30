<?php

namespace Tests\Unit;

use App\Support\SearchText;
use PHPUnit\Framework\TestCase;

/** Prompt 10: the search normaliser shared by pages, people and commands. */
class SearchTextTest extends TestCase
{
    public function test_hamza_forms_fold_to_their_base_letter(): void
    {
        $this->assertSame('احمد', SearchText::normalize('أحمد'));
        $this->assertSame('اجازه', SearchText::normalize('إجازة'));
        $this->assertSame('امال', SearchText::normalize('آمال'));
        $this->assertSame('الله', SearchText::normalize('ٱلله'));
        $this->assertSame('مومن', SearchText::normalize('مؤمن'));
        $this->assertSame('رييس', SearchText::normalize('رئيس'));
    }

    public function test_ta_marbuta_and_alef_maqsura_fold_to_ha_and_ya(): void
    {
        $this->assertSame('ساره', SearchText::normalize('سارة'));
        $this->assertSame('مصطفي', SearchText::normalize('مصطفى'));
        $this->assertSame(SearchText::normalize('مستشفى المدينة'), SearchText::normalize('مستشفي المدينه'));
    }

    public function test_tatweel_and_diacritics_are_dropped(): void
    {
        $this->assertSame('اجازه', SearchText::normalize('إجـــازة'));
        // Fatha, kasra, damma, sukun, shadda, tanween.
        $this->assertSame('اجازه', SearchText::normalize('إِجَازَةٌ'));
        $this->assertSame('محمد', SearchText::normalize('مُحَمَّدْ'));
        $this->assertSame('كتابا', SearchText::normalize('كتابًا'));
        // Superscript alef, and a hamza written as a combining mark over a bare alef.
        $this->assertSame('رحمن', SearchText::normalize('رحمٰن'));
        $this->assertSame('احمد', SearchText::normalize("\u{0627}\u{0654}حمد"));
    }

    public function test_latin_text_is_lower_cased_and_spaces_are_collapsed(): void
    {
        $this->assertSame('new leave request', SearchText::normalize("  New   Leave\tRequest "));
        $this->assertSame('', SearchText::normalize('   '));
    }

    public function test_every_word_must_match_in_any_order_and_any_haystack(): void
    {
        $this->assertTrue(SearchText::matches('new leave', 'New leave request', 'طلب إجازة جديد'));
        $this->assertTrue(SearchText::matches('leave new', 'New leave request'));
        $this->assertTrue(SearchText::matches('اجازة', 'New leave request', 'طلب إجازة جديد'));
        $this->assertTrue(SearchText::matches('إجازه جديد', 'طلب إجازة جديد'));
        $this->assertTrue(SearchText::matches('مؤمن', 'مومن'));
        $this->assertTrue(SearchText::matches('LEAVE', 'New leave request'));

        $this->assertFalse(SearchText::matches('new contract', 'New leave request'));
        $this->assertFalse(SearchText::matches('', 'New leave request'));
        $this->assertFalse(SearchText::matches('   ', 'New leave request'));
    }
}
