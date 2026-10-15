@props([
    'contract',
    'forPdf' => false,
])

@php
    use App\Enums\Currency;
    use App\Enums\PayMode;
    use App\Enums\SalaryPer;
    use App\Enums\SessionDuration;
    use App\Support\ArabicText as AR;

    /*
     * SessionDuration::getLocalizedName() returns one string for whichever
     * app()->getLocale() happens to be active — no use here, where EN and AR
     * always render together regardless of the page's own locale (same
     * reasoning as contract-composer.js's own hardcoded DAY_NAMES_EN/AR).
     */
    $sessionDurationLabels = [
        SessionDuration::Hour => ['en' => 'one hour', 'ar' => 'ساعة واحدة'],
        SessionDuration::HourAndHalf => ['en' => 'an hour and a half', 'ar' => 'ساعة ونصف'],
        SessionDuration::TwoHours => ['en' => 'two hours', 'ar' => 'ساعتين'],
    ];

    // Same rationale as <x-contract.schedule-clause>: one data-driven place
    // for wording six hand-diverged print pages used to hardcode separately
    // — including a flat "monthly stipend" that ignored salary_per entirely,
    // and (annual/trial only) a currency hardcoded to USD regardless of what
    // the contract actually stores.
    $glyphs = fn (string $s): string => $forPdf ? AR::smartGlyphs($s) : $s;
    // Arabic-column-only — see the matching note in <x-contract.schedule-clause>.
    $digitsAr = fn (int|float|string $n): string => $forPdf ? ar_digits((string) $n) : (string) $n;

    $currency = (int) $contract->currency;
    $currencyLabels = Currency::getLocalizedName($currency);
    $currencyEn = $currencyLabels['en'];
    $currencyAr = $glyphs($currencyLabels['ar']);

    $formatAmount = fn (mixed $amount): string => $amount !== null && $amount !== ''
        ? number_format((float) $amount, 0, '.', ',')
        : '—';

    // Foreign-currency contracts carry an SYP-equivalent-in-cash clause;
    // one already denominated in SYP has nothing to convert.
    $needsSypEquivalent = in_array($currency, [Currency::USD, Currency::EUR], true);

    $rows = [];

    // The on-screen contract is read by roles that may not see pay figures
    // (super-admin, general-manager, manager); the composer withholds them from
    // those roles and so does this. The PDF never gets here for them -
    // ContractPolicy::export refuses it - and a render with nobody signed in
    // (console, export doctor) keeps the full clause.
    // @see \App\Models\User::canViewSalary()
    $payHidden = auth()->check() && ! auth()->user()->canViewSalary();

    if ($payHidden) {
        $rows[] = [
            'en' => '<em>'.e(__('general.salary-hidden-title', [], 'en')).'</em>',
            'ar' => '<em>'.e($glyphs(__('general.salary-hidden-title', [], 'ar'))).'</em>',
        ];
    } elseif ((int) $contract->pay_mode === PayMode::Flexible) {
        $tiers = $contract->payTiers;

        if ($tiers->isEmpty()) {
            $rows[] = ['en' => '—', 'ar' => '—'];
        } else {
            foreach ($tiers as $tier) {
                $amountEn = $formatAmount($tier->amount).' '.$currencyEn;
                $amountAr = $glyphs($digitsAr($formatAmount($tier->amount)).' ').$currencyAr;

                $rows[] = [
                    'en' => '- '.trim((string) $tier->condition_en).': <strong>'.$amountEn.'</strong>.',
                    'ar' => $glyphs('- '.trim((string) $tier->condition_ar).': ').'<strong>'.$amountAr.'</strong>'.$glyphs('.'),
                ];
            }
        }

        if ($needsSypEquivalent) {
            $rows[] = [
                'en' => 'Or the equivalent amount in Syrian pounds, paid in cash to the volunteer at the end of each month.',
                'ar' => $glyphs('أو ما يعادل ذلك بالليرة السورية، تُمنح نقداً للمتطوع في نهاية كل شهر.'),
            ];
        }
    } else {
        $amount = $formatAmount($contract->salary).' '.$currencyEn;
        $amountAr = $glyphs($digitsAr($formatAmount($contract->salary)).' ').$currencyAr;

        $salaryPer = (int) $contract->salary_per;

        if ($salaryPer === SalaryPer::Hour) {
            $stipendEn = 'Christian Hope Center is to give an hourly stipend of <strong>/ '.$amount.' /</strong> per hour of work.';
            $stipendAr = $glyphs('خصص الفريق الأول للفريق الثاني مكافأة بواقع').' <strong>'.$glyphs('/ ').$amountAr.$glyphs(' /').'</strong>'.$glyphs(' عن كل ساعة عمل.');
        } elseif ($salaryPer === SalaryPer::Session) {
            $durationLabel = $sessionDurationLabels[(int) $contract->session_duration] ?? ['en' => '—', 'ar' => '—'];
            $durationEn = $durationLabel['en'];
            $durationAr = $glyphs($durationLabel['ar']);

            $stipendEn = 'Christian Hope Center is to give a stipend of <strong>/ '.$amount.' /</strong> per session, each session being '.$durationEn.'.';
            $stipendAr = $glyphs('خصص الفريق الأول للفريق الثاني مكافأة وقدرها').' <strong>'.$glyphs('/ ').$amountAr.$glyphs(' /').'</strong>'.$glyphs(' عن كل جلسة، مدة الجلسة الواحدة ').$durationAr.$glyphs('.');
        } else {
            $stipendEn = 'Christian Hope Center is to give a monthly stipend of <strong>/ '.$amount.' /</strong>';
            // The English sentence wraps to two lines on its own, so the Arabic
            // figure goes on its own line to keep both columns the same height.
            $stipendAr = $glyphs('خصص الفريق الأول للفريق الثاني شهرياً مكافأة وقدرها').'<br><strong>'.$glyphs('/ ').$amountAr.$glyphs(' /').'</strong>';
        }

        $rows[] = ['en' => $stipendEn, 'ar' => $stipendAr];

        if ($needsSypEquivalent) {
            $rows[] = [
                'en' => 'Or its equivalent in Syrian pounds, to be paid in cash to the volunteer at the end of each month.',
                'ar' => $glyphs('أو ما يعادلها بالليرة السورية، تُمنح نقداً في نهاية كل شهر.'),
            ];
        }
    }
@endphp

@foreach ($rows as $row)
    <x-contract.dual class="mt-1" :en="$row['en']" :ar="$row['ar']" />
@endforeach
