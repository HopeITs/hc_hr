@php
    use App\Support\ArabicText as AR;

    $forPdf = $forPdf ?? false;
    $Glyphs = fn($s) => $forPdf ? AR::glyphs((string) $s) : (string) $s;
    $LB     = fn($t) => '<span class="h-label-bold">' . $t . '</span>';
    $annualLeaveDays = $contract->leaveBalances->first()?->int_total ?? '—';
  $Digits = fn($s) => $forPdf ? ar_digits((string) $s) : (string) $s;
    $asList = function(array $lines) {
        return '<div class="list-plain">'
            . implode('', array_map(fn($l) => '<div>'.$l.'</div>', $lines))
            . '</div>';
    };
@endphp

<div class="avoid-break mt-1">
    <x-contract.dual :en="$LB('9. Annual Leave:')" :ar="$LB($Glyphs('9. إجازات العمل التطوعّي:'))" />
    
    <x-contract.dual class="mt-1" 
        :en="'The second team is entitled to <strong>' . $annualLeaveDays . '</strong> days of working days leave during the validity period of the contract, with it being proportional to the duration of the contract.'" 
        :ar="$Glyphs('يحق للفريق الثاني ') . '<strong>' . $Digits($annualLeaveDays) . '</strong>' . $Glyphs(' يوم إجازة سنوية خلال فترة سريان العقد، وتكون متناسبة مع مدة العقد.')" />

    <x-contract.dual class="mt-1" 
        :en="'The second team is entitled to <strong>24</strong> days of sick leave in cases of operations and infectious diseases, <strong>12</strong> days paid and <strong>12</strong> days paid at half the value of the monthly stipend, provided that a medical report is submitted to justify the leave.'"
        :ar="$Glyphs('يحق للفريق الثاني <strong>24</strong> يوم إجازة مرضية عند الضرورة فقط، أي في حالات العمليات والأمراض المُعديَة، <strong>12</strong> يوم مدفوع و<strong>12</strong> يوم مدفوع بنصف قيمة المكافأة المالية الشهرية وذلك بشرط تقديم تقرير طبي لتبرير الإجازة.')" />

    <x-contract.dual class="mt-1" 
        :en="'- In case the second team consumes all annual and sickness leave days, they will be given 12 days of leave with the deduction of the number of leave days from the monthly stipend allowance.'" 
        :ar="$Glyphs('- في حال تجاوز الفريق الثاني أيام الاجازات المحددة له والتي تتناسب مع العقد سواءً إجازات عادية أو مرضية، يتم منحه إجازة لا تتجاوز مدتها 12 يوم يتم خصم قيمتها من المكافأة المالية الشهرية المخصصة له.')" />
</div>


<div class="avoid-break mt-1">
    <x-contract.dual 
        :en="$LB('10. Internal Rules and Regulations:')" 
        :ar="$LB($Glyphs('10. القوانين والأنظمة الداخلية:'))" />
    
    <x-contract.dual class="mt-1"
        :en="'The second party acknowledges and pledges to commit to the following attached documents:'"
        :ar="$Glyphs('الفريق الثاني يُقر ويتعهد بالاطلاع والالتزام بالمستندات المرفقة التالية:')" />

    @php
        $EN_RULES = ['- Job description.', '- Code of Conduct.', '- Christian Hope Center Policies.'];
        $AR_RULES = [$Glyphs('- توصيف العمل التطوّعي.'), $Glyphs('- القواعد الأخلاقية للتطوّع.'), $Glyphs('- سياسات مؤسسة مركز الرجاء المسيحي.')];
    @endphp
    <x-contract.dual class="mt-1" 
        :en="$asList($EN_RULES)" 
        :ar="$asList($AR_RULES)" />
</div>

<div class="avoid-break mt-1">
    <x-contract.dual 
        :en="$LB('11. Expiration of the Contract:')" 
        :ar="$LB($Glyphs('11. انتهاء العقد:'))" />
    
    <x-contract.dual class="mt-1"
        :en="'This contract is considered to be terminated in case of:'"
        :ar="$Glyphs('يعتبر هذا العقد منتهياً في الحالات التالية:')" />

    @php
        $EN_TERMINATION = [
            '- The closure of the Christian Hope Center in Syria.',
            '- Project expiration.',
            '- Expiration of the agreement.',
            '- Breaching of this agreement and the attached job description by the second team after notice and warning.',
            '- Breaching the Code of Conduct after notice and warning.',
            '- Breaching the Christian Hope Center policies and bylaws after notice and warning.',
            '- Unsatisfactory results of job performance evaluation.'
        ];
        $AR_TERMINATION = [
            $Glyphs('- إغلاق مؤسسة مركز الرجاء المسيحي في سوريا.'),
            $Glyphs('- انتهاء المشروع.'),
            $Glyphs('- انتهاء مدة العقد.'),
            $Glyphs('- في حال عدم التقيّد ببنود هذا العقد وتوصيف العمل التطوّعي المرفق من قبل الفريق الثاني بعد التنبيه والانذار.'),
            $Glyphs('- في حال المخالفة لمدونة القواعد الأخلاقية للتطوّع بعد التنبيه والانذار.'),
            $Glyphs('- في حال مخالفة سياسات مؤسسة مركز الرجاء المسيحي بعد التنبيه والانذار.'),
            $Glyphs('- نتائج تقييم الأداء غير المُرضيّة.')
        ];
    @endphp
    <x-contract.dual class="mt-1" 
        :en="$asList($EN_TERMINATION)" 
        :ar="$asList($AR_TERMINATION)" />
</div>
