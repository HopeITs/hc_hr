<?php

namespace Tests\Concerns;

/**
 * The application form asks for eight names (RecruitmentApplicationFormService::NAME_FIELDS)
 * and the applicant record keeps the Arabic first and last name as its single name.
 * Tests name people in Latin letters ("Rami Haddad"); these turn such a name into
 * the eight answers and into the Arabic name the record will hold. The Arabic is a
 * fixed letter-for-letter mapping, so two different test names stay different.
 */
final class ApplicantNames
{
    private const LETTERS = [
        'a' => 'ا', 'b' => 'ب', 'c' => 'ك', 'd' => 'د', 'e' => 'ي', 'f' => 'ف', 'g' => 'غ',
        'h' => 'ه', 'i' => 'ي', 'j' => 'ج', 'k' => 'ك', 'l' => 'ل', 'm' => 'م', 'n' => 'ن',
        'o' => 'و', 'p' => 'ب', 'q' => 'ق', 'r' => 'ر', 's' => 'س', 't' => 'ت', 'u' => 'و',
        'v' => 'ف', 'w' => 'و', 'x' => 'كس', 'y' => 'ي', 'z' => 'ز',
    ];

    /** @return array<string, string> the eight name answers for "First Last …" */
    public static function answers(string $latin): array
    {
        [$first, $last] = self::split($latin);

        return [
            'first_name_ar' => self::arabic($first),
            'last_name_ar' => self::arabic($last),
            'father_name_ar' => 'جورج',
            'mother_name_ar' => 'ماري',
            'first_name_en' => $first,
            'last_name_en' => $last,
            'father_name_en' => 'George',
            'mother_name_en' => 'Mary',
        ];
    }

    /** The name the applicant record holds for answers($latin): Arabic first and last name. */
    public static function fullName(string $latin): string
    {
        [$first, $last] = self::split($latin);

        return self::arabic($first).' '.self::arabic($last);
    }

    /** The form fields of the eight names, as a fixture form version lists them. */
    public static function fields(): array
    {
        return array_map(fn (string $key): array => ['key' => $key], \App\Services\RecruitmentApplicationFormService::NAME_FIELDS);
    }

    public static function arabic(string $latin): string
    {
        return strtr(mb_strtolower($latin), self::LETTERS + [' ' => ' ', '.' => '', "'" => '', '-' => '']);
    }

    /** @return array{0: string, 1: string} first word, then the rest (a one-word name doubles as its own last name) */
    private static function split(string $latin): array
    {
        $parts = preg_split('/\s+/', trim($latin), 2) ?: [];

        return [$parts[0] ?? 'Applicant', $parts[1] ?? ($parts[0] ?? 'Applicant')];
    }
}
