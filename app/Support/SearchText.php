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
     * SQL for a stored Arabic column with FOLD's letters folded (diacritics are
     * left: REPLACE cannot match a range). Works on MySQL and SQLite.
     */
    public static function foldedColumn(string $column): string
    {
        $sql = $column;

        foreach (self::FOLD as $from => $to) {
            $sql = "REPLACE({$sql}, '{$from}', '{$to}')";
        }

        return $sql;
    }

    /**
     * The words of a typed term, ready for LIKE: folded, at most $max, and with the
     * characters LIKE treats as wildcards removed (names never contain them).
     *
     * @return list<string>
     */
    public static function words(string $term, int $max = 4): array
    {
        $words = preg_split('/\s+/u', str_replace(['%', '_', '\\'], '', self::normalize($term))) ?: [];

        return array_slice(array_values(array_filter($words, fn ($word) => $word !== '')), 0, $max);
    }

    /**
     * How alike two texts are, from 0 to 1, after folding (1 = the same). A letter more or
     * less, or swapped, still scores high: «mohamad» and «mohammad», «عبدالمسيح» and «عبد المسيح».
     */
    public static function similarity(string $a, string $b): float
    {
        $a = preg_split('//u', self::normalize($a), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('//u', self::normalize($b), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $la = count($a);
        $lb = count($b);

        if ($la === 0 || $lb === 0) {
            return 0.0;
        }

        $previous = range(0, $lb);

        for ($i = 1; $i <= $la; $i++) {
            $row = [$i];

            for ($j = 1; $j <= $lb; $j++) {
                $row[$j] = min($previous[$j] + 1, $row[$j - 1] + 1, $previous[$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1));
            }

            $previous = $row;
        }

        return 1 - $previous[$lb] / max($la, $lb);
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
