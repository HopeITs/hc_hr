<?php

namespace App\Support\Search;

use App\Support\SearchText;

/**
 * Marks typed words inside a text, for the policy page a search result opens. The words match
 * the way the search matched them: Arabic letters typed interchangeably (أ إ آ = ا, ة = ه,
 * ى ئ = ي, ؤ = و) and short vowels or tatweel inside the text do not stop a match.
 *
 * The result is HTML that is safe to print unescaped: the text is escaped piece by piece and
 * only <mark> is added.
 */
final class Highlight
{
    /** Folded letter => every letter that folds to it. */
    private const VARIANTS = ['ا' => 'اأإآٱ', 'و' => 'وؤ', 'ي' => 'يئى', 'ه' => 'هة'];

    /** The rest of a word on either side of what matched. */
    private const WORD = '[\p{L}\p{M}\x{0640}]*';

    /** Diacritics and tatweel that may sit between the letters of a word. */
    private const MARKS = '[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]*';

    /** @param  list<string>  $terms */
    public static function html(string $text, array $terms): string
    {
        $patterns = [];

        foreach ($terms as $term) {
            $folded = SearchText::normalize($term);

            if (mb_strlen($folded) < 3) {
                continue;
            }

            $pattern = '';

            foreach (preg_split('//u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $letter) {
                $pattern .= (isset(self::VARIANTS[$letter]) ? '['.self::VARIANTS[$letter].']' : preg_quote($letter, '/')).self::MARKS;
            }

            // The whole word that holds it: «كترون» marks «الإلكترونية», «leave» marks «Leaves».
            $patterns[self::WORD.$pattern.self::WORD] = mb_strlen($folded);
        }

        if ($patterns === []) {
            return e($text);
        }

        // The longest first, so a word is marked whole before its own stem is tried.
        arsort($patterns);

        $parts = preg_split('/('.implode('|', array_keys($patterns)).')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return e($text);
        }

        $html = '';

        foreach ($parts as $i => $part) {
            $html .= $i % 2 === 1 ? '<mark class="policy-mark">'.e($part).'</mark>' : e($part);
        }

        return $html;
    }
}
