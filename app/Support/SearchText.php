<?php

namespace App\Support;

/**
 * Text folded the way people type it, so a search finds what they meant
 * (prompt 10). Arabic letters typed interchangeably become one form, short
 * vowels and tatweel go, and everything is lower case:
 *
 *   «أحمد» = «احمد», «مؤمن» = «مومن», «رئيس» = «رييس», «سارة» = «ساره»,
 *   «مصطفى» = «مصطفي», «إجـازة» = «اجازه», «Leave» = «leave».
 *
 * resources/js/help-panel.js (normalizeForSearch) folds help topics the same
 * way in the browser. The header search applies FOLD to stored Arabic names in
 * SQL too (GlobalSearch::foldedColumn), so a typed name and a stored one meet.
 */
final class SearchText
{
    /** Letter => the form it is folded to: hamza forms, alef maqsura, ta marbuta, tatweel. */
    public const FOLD = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ؤ' => 'و', 'ئ' => 'ي',
        'ى' => 'ي',
        'ة' => 'ه',
        'ـ' => '',
    ];

    /**
     * Diacritics: Quranic marks (U+0610–U+061A), harakat with shadda, sukun and
     * the combining maddah / hamza above and below (U+064B–U+065F), superscript
     * alef (U+0670) and the small high marks (U+06D6–U+06ED).
     */
    private const DIACRITICS = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u';

    public static function normalize(string $text): string
    {
        $text = strtr((string) preg_replace(self::DIACRITICS, '', $text), self::FOLD);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * Every word of $term appears somewhere in $haystacks (any order, any
     * language): «new leave», «leave new» and «اجازة» all find «New leave request».
     */
    public static function matches(string $term, string ...$haystacks): bool
    {
        $words = array_filter(explode(' ', self::normalize($term)), fn (string $word) => $word !== '');
        $haystack = self::normalize(implode(' ', $haystacks));

        if ($words === []) {
            return false;
        }

        foreach ($words as $word) {
            if (! str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }
}
