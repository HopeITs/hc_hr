<?php

namespace App\Services\Recruitment\Cv;

use App\Services\RecruitmentIndicatorRules;
use Illuminate\Support\Str;

/**
 * Reads facts out of a CV's text, in Arabic and English, by rule — no model,
 * no network. Every fact carries the evidence it was read from, so the
 * reviewer can check it against the CV in one glance.
 *
 * Arabic is normalized before any comparison (hamza forms, taa marbuta,
 * alef maqsura, diacritics, tatweel, Arabic-Indic digits), so "إكسل",
 * "اكسل" and "اكسِل" are the same word.
 *
 * Pure: text in, array out. Bump VERSION whenever a rule changes, so stored
 * readings can be told apart and re-run.
 */
class CvAnalyzer
{
    public const VERSION = '1';

    private const WORD_NUMBERS = [
        'سنه' => 1, 'سنتين' => 2, 'سنتان' => 2, 'ثلاث' => 3, 'ثلاثه' => 3, 'اربع' => 4, 'اربعه' => 4,
        'خمس' => 5, 'خمسه' => 5, 'ست' => 6, 'سته' => 6, 'سبع' => 7, 'سبعه' => 7, 'ثماني' => 8, 'ثمان' => 8,
        'تسع' => 9, 'تسعه' => 9, 'عشر' => 10, 'عشره' => 10,
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
        'eight' => 8, 'nine' => 9, 'ten' => 10,
    ];

    private const STOPWORDS = [
        'and', 'or', 'the', 'of', 'in', 'with', 'for', 'to', 'a', 'an', 'on', 'at', 'good', 'strong', 'excellent', 'ability', 'skills', 'skill', 'knowledge',
        // Already in normalized form (ى→ي, ة→ه, no hamza on alef).
        'في', 'من', 'علي', 'الي', 'مع', 'عن', 'او', 'جيد', 'جيده', 'ممتاز', 'ممتازه', 'مهارات', 'مهاره', 'القدره', 'قدره', 'معرفه',
    ];

    /** Null (the unit tests) reads the shipped config; the container passes the saved rules. */
    public function __construct(private readonly ?RecruitmentIndicatorRules $rules = null) {}

    /**
     * @return array{
     *     experience: array{years: ?int, stated: ?int, from_dates: ?int, ranges: array<int, array{0: int, 1: int}>},
     *     education: ?array{level: string, keyword: string},
     *     languages: array<int, string>,
     *     skills: array<int, string>,
     * }
     */
    public function facts(string $text): array
    {
        $normalized = $this->normalize($text);

        return [
            'experience' => $this->experience($normalized),
            'education' => $this->highestEducation($normalized),
            'languages' => $this->languages($normalized),
            'skills' => $this->knownSkills($normalized),
        ];
    }

    /**
     * Sets the facts against what the recruitment request asked for.
     *
     * @param  array<string, mixed>  $facts
     * @return array{experience: array<string, mixed>, education: array<string, mixed>, skills: array<string, mixed>}
     */
    public function compare(string $text, array $facts, ?int $requiredYears, ?string $requiredQualifications, ?string $requiredSkills): array
    {
        return [
            'experience' => $this->compareExperience($facts['experience']['years'] ?? null, $requiredYears),
            'education' => $this->compareEducation($facts['education'] ?? null, $requiredQualifications),
            'skills' => $this->compareSkills($this->normalize($text), $requiredSkills),
        ];
    }

    public function normalize(string $text): string
    {
        $text = en_digits($text);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي', '–' => '-', '—' => '-']);

        return mb_strtolower($text);
    }

    private function rule(string $key): array
    {
        return $this->rules?->get($key, []) ?? config('recruitment.indicators.'.$key, []);
    }

    // ------------------------------------------------------------------
    // Experience
    // ------------------------------------------------------------------

    /** @return array{years: ?int, stated: ?int, from_dates: ?int, ranges: array<int, array{0: int, 1: int}>} */
    private function experience(string $text): array
    {
        $stated = $this->statedYears($text);
        $ranges = $this->workRanges($text);
        $fromDates = $ranges === [] ? null : $this->unionYears($ranges);

        $years = match (true) {
            $stated === null => $fromDates,
            $fromDates === null => $stated,
            default => max($stated, $fromDates),
        };

        return ['years' => $years, 'stated' => $stated, 'from_dates' => $fromDates, 'ranges' => $ranges];
    }

    /** "5 years of experience", "خبرة 5 سنوات", "خبرة ثلاث سنوات" — on a line that talks about experience. */
    private function statedYears(string $text): ?int
    {
        $best = null;
        $numberWords = implode('|', array_map(fn (string $word): string => preg_quote($word, '/'), array_keys(self::WORD_NUMBERS)));

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (! preg_match('/experience|خبره|خبرات/u', $line)) {
                continue;
            }

            if (preg_match('/(\d{1,2})\s*\+?\s*(?:years?|yrs)\b/u', $line, $m)
                || preg_match('/(\d{1,2})\s*\+?\s*(?:سنوات|سنين|سنه|اعوام|عام)/u', $line, $m)) {
                $years = (int) $m[1];
            } elseif (preg_match('/(?:^|\s)('.$numberWords.')\s+(?:years?|سنوات|سنين|اعوام)/u', $line, $m)
                || preg_match('/(?:^|\s)(سنتين|سنتان|سنه)(?:\s|$)/u', $line, $m)) {
                $years = self::WORD_NUMBERS[$m[1]] ?? null;
            } else {
                continue;
            }

            if ($years !== null && $years > 0 && $years <= 50) {
                $best = max($best ?? 0, $years);
            }
        }

        return $best;
    }

    /**
     * Year ranges such as "2018 - 2022", "03/2019 – present", "2020 حتى الآن".
     * A range on a line that names a school or a degree is education, not
     * work, and is left out.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function workRanges(string $text): array
    {
        $current = (int) now()->format('Y');
        // Written naturally and normalized here, so they match normalized text.
        $open = $this->normalize('present|current|now|today|till now|to date|حتى الآن|الآن|حالياً|لتاريخه|لغاية الآن|مستمر');
        $to = $this->normalize('-|to|till|until|إلى|الى|حتى|لغاية');
        $pattern = '/(?:\d{1,2}\s*[\/.\-]\s*)?((?:19|20)\d{2})\s*(?:'.$to.')\s*(?:\d{1,2}\s*[\/.\-]\s*)?((?:19|20)\d{2}|'.$open.')/u';
        $schoolWords = array_map(fn (string $word): string => $this->normalize($word), config('recruitment.cv_analysis.education_line_words', []));

        $ranges = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (! preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
                continue;
            }

            if (Str::contains($line, $schoolWords)) {
                continue;
            }

            foreach ($matches as $match) {
                $start = (int) $match[1];
                $end = ctype_digit($match[2]) ? (int) $match[2] : $current;

                if ($start >= 1960 && $start <= $end && $end <= $current) {
                    $ranges[] = [$start, $end];
                }
            }
        }

        usort($ranges, fn (array $a, array $b): int => $a <=> $b);

        return array_values(array_unique($ranges, SORT_REGULAR));
    }

    /** Overlapping jobs are counted once. @param array<int, array{0: int, 1: int}> $ranges */
    private function unionYears(array $ranges): int
    {
        $total = 0;
        [$start, $end] = $ranges[0];

        foreach (array_slice($ranges, 1) as [$nextStart, $nextEnd]) {
            if ($nextStart <= $end) {
                $end = max($end, $nextEnd);

                continue;
            }
            $total += $end - $start;
            [$start, $end] = [$nextStart, $nextEnd];
        }

        return min(50, $total + ($end - $start));
    }

    // ------------------------------------------------------------------
    // Education, languages, skills
    // ------------------------------------------------------------------

    /** The highest level the CV names, read with the same words the indicators use. */
    private function highestEducation(string $text): ?array
    {
        $found = $this->levels($text);

        return $found === [] ? null : end($found);
    }

    /**
     * Every level named in the text, lowest first.
     *
     * @return array<int, array{level: string, keyword: string}>
     */
    private function levels(string $text): array
    {
        $ranks = $this->rule('education_ranks');
        $found = [];

        foreach ($this->rule('qualification_keywords') as $level => $keywords) {
            if (! isset($ranks[$level])) {
                continue;
            }
            foreach ((array) $keywords as $keyword) {
                if ($this->mentions($text, $this->normalize((string) $keyword))) {
                    $found[$level] = ['level' => $level, 'keyword' => (string) $keyword];

                    break;
                }
            }
        }

        uasort($found, fn (array $a, array $b): int => $ranks[$a['level']] <=> $ranks[$b['level']]);

        return array_values($found);
    }

    /** @return array<int, string> */
    private function languages(string $text): array
    {
        $found = [];
        foreach (config('recruitment.cv_analysis.languages', []) as $language => $words) {
            foreach ((array) $words as $word) {
                if ($this->mentions($text, $this->normalize((string) $word))) {
                    $found[] = (string) $language;

                    break;
                }
            }
        }

        return $found;
    }

    /** The first name of each dictionary skill the CV mentions. @return array<int, string> */
    private function knownSkills(string $text): array
    {
        $found = [];
        foreach (config('recruitment.cv_analysis.skill_synonyms', []) as $group) {
            foreach ((array) $group as $term) {
                if ($this->mentions($text, $this->normalize((string) $term))) {
                    $found[] = (string) $group[0];

                    break;
                }
            }
        }

        return $found;
    }

    // ------------------------------------------------------------------
    // Comparison
    // ------------------------------------------------------------------

    private function compareExperience(?int $years, ?int $required): array
    {
        return match (true) {
            $required === null => ['outcome' => 'not_applicable'],
            $required <= 0 => ['outcome' => 'met', 'required' => 0, 'found' => $years],
            $years === null => ['outcome' => 'unknown', 'required' => $required],
            $years >= $required => ['outcome' => 'met', 'required' => $required, 'found' => $years],
            default => ['outcome' => 'not_met', 'required' => $required, 'found' => $years],
        };
    }

    private function compareEducation(?array $education, ?string $requiredText): array
    {
        $requiredText = trim((string) $requiredText);
        if ($requiredText === '') {
            return ['outcome' => 'not_applicable'];
        }

        // The lowest level a request names is its minimum, as in the indicators.
        $required = $this->levels($this->normalize($requiredText))[0] ?? null;
        if ($required === null) {
            return ['outcome' => 'unknown', 'requirement' => Str::limit($requiredText, 160)];
        }
        if ($education === null) {
            return ['outcome' => 'unknown', 'required' => $required['level']];
        }

        $ranks = $this->rule('education_ranks');

        return [
            'outcome' => $ranks[$education['level']] >= $ranks[$required['level']] ? 'met' : 'not_met',
            'required' => $required['level'],
            'found' => $education['level'],
        ];
    }

    /**
     * Each skill the request lists, and whether the CV mentions it — directly,
     * through a synonym in either language, or (for a phrase) through most of
     * its meaningful words.
     */
    private function compareSkills(string $cv, ?string $requiredSkills): array
    {
        $terms = $this->skillTerms((string) $requiredSkills);
        if ($terms === []) {
            return ['outcome' => 'not_applicable', 'items' => []];
        }

        $items = [];
        foreach ($terms as $term) {
            $items[] = ['term' => $term] + $this->findSkill($cv, $term);
        }

        $matched = count(array_filter($items, fn (array $item): bool => $item['matched']));

        return ['outcome' => 'assessed', 'matched' => $matched, 'total' => count($items), 'items' => $items];
    }

    /** @return array{matched: bool, evidence: ?string} */
    private function findSkill(string $cv, string $term): array
    {
        $normalized = $this->normalize($term);

        foreach ($this->variants($normalized) as $variant) {
            if ($this->mentions($cv, $variant)) {
                return ['matched' => true, 'evidence' => $variant];
            }
        }

        // A phrase ("experience in managing field teams"): most of its words.
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}+#&]+/u', $normalized) ?: [],
            fn (string $word): bool => mb_strlen($word) >= 3 && ! in_array($word, self::STOPWORDS, true),
        ));

        if (count($words) >= 3) {
            $hits = array_filter($words, fn (string $word): bool => $this->mentions($cv, $word));
            if (count($hits) / count($words) >= 0.6) {
                return ['matched' => true, 'evidence' => implode(', ', $hits)];
            }
        }

        return ['matched' => false, 'evidence' => null];
    }

    /** The term itself plus every synonym from a group it belongs to. @return array<int, string> */
    private function variants(string $term): array
    {
        $variants = [$term];

        foreach (config('recruitment.cv_analysis.skill_synonyms', []) as $group) {
            $normalizedGroup = array_map(fn (string $item): string => $this->normalize($item), (array) $group);
            foreach ($normalizedGroup as $item) {
                if ($item === $term || $this->mentions($term, $item)) {
                    array_push($variants, ...$normalizedGroup);

                    break;
                }
            }
        }

        return array_values(array_unique($variants));
    }

    /** @return array<int, string> */
    private function skillTerms(string $text): array
    {
        $parts = preg_split('/[\n,،;؛•\*\|\/]+|\s+-\s+|\s+(?:and|و)\s+/u', $text) ?: [];

        return collect($parts)
            ->map(fn (string $part): string => trim(preg_replace('/^[\s\-–\d.)]+/u', '', $part) ?? $part))
            ->filter(fn (string $part): bool => mb_strlen($part) >= 2 && mb_strlen($part) <= 120)
            ->unique()
            ->take(30)
            ->values()
            ->all();
    }

    /**
     * Word-bounded for Latin script. Arabic attaches prefixes (و، ب، ل، ال) and
     * suffixes to words, so there a contained match is the honest test.
     */
    private function mentions(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return false;
        }

        if (preg_match('/\p{Arabic}/u', $needle)) {
            return str_contains($haystack, $needle);
        }

        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1;
    }
}
