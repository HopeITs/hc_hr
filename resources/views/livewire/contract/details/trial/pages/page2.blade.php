@php
   use App\Support\ArabicText as AR;
    use Carbon\Carbon;

    $forPdf = $forPdf ?? false;
    $Glyphs = fn($s) => $forPdf ? AR::glyphs((string) $s) : (string) $s;
    $Digits = fn($s) => $forPdf ? ar_digits((string) $s) : (string) $s;

    $pos = $contract->position;
    $req = $pos?->requiredPosition;
    $positionTitleEn = $req?->title_en ?? ($pos?->title_en ?? '—');
    $positionTitleAr = $req?->title_ar ?? ($pos?->title_ar ?? '—');

    $startStr = $contract->start_date ? Carbon::parse($contract->start_date)->format('d/m/Y') : '—';
    $endStr = $contract->end_date ? Carbon::parse($contract->end_date)->format('d/m/Y') : '—';
    $startStrAr = $Digits($startStr);
    $endStrAr   = $Digits($endStr);
    $annualLeaveDays = $contract->leaveBalances->first()?->int_total ?? '—';

    $esc = fn($v) => e((string) ($v ?: '—'));
    $escAr = fn($v) => e((string) ($v ? $Glyphs($v) : '—'));
    $LB = fn($t) => '<span class="h-label-bold">' . $t . '</span>';
    $wrapLtr = fn ($v) => '<span dir="ltr">'.$v.'</span>';
    // 
$programId = $contract->position?->program_id;

  
    $isStudyZone = ($programId == \App\Models\Program::STUDY_ZONE_ID);
    $isGeneralProject = !$isStudyZone;
  
@endphp

<div class="avoid-break mt-4">
    <x-contract.dual 
        :en="$LB('4. Job description:')" 
        :ar="$LB($Glyphs('4. وصف العمل:'))" />

    <x-contract.dual class="mt-1"
        :en="'<strong>' . $esc($positionTitleEn) . '</strong>'" 
        :ar="'<strong>' . $escAr($positionTitleAr) . '</strong>'" />
</div>
<div class="avoid-break mt-4">
    <x-contract.dual 
        :en="$LB('5. Duration of the contract:')" 
        :ar="$LB($Glyphs('5. مدة العقد (الفترة التجريبية):'))" />

    <x-contract.dual class="mt-1"
        :en="'Start date: <strong>' . $esc($startStr) . '</strong>'"
        :ar="$Glyphs('تبدأ من تاريخ:') . ' <strong>' . $wrapLtr($esc($startStrAr)) . '</strong>'" />

    <x-contract.dual 
        :en="'End date: <strong>' . $esc($endStr) . '</strong>'" 
        :ar="$Glyphs('وتنتهي إلى تاريخ:') . ' <strong>' . $wrapLtr($esc($endStrAr)) . '</strong>'" />

       <x-contract.dual class="mt-1"
        :en="'It can be extended by mutual agreement between the two parties, based on performance evaluation, provided that the trial period does not exceed six months. The trial period ends with either the confirmation of the second team with a voluntary contract or their dismissal, based on a clear performance evaluation.'"
        :ar="$Glyphs('وهي قابلة للتمديد بالاتفاق بين الفريقين ووفقاً لتقييم الأداء، على ألا تتجاوز مدة الفترة التجريبية ستة أشهر. تنتهي الفترة التجريبية بتثبيت الفريق الثاني بعقد تطوّع أو الاعتذار منه وذلك وفق تقييم أداء واضح.')" />

</div> 


<div class="avoid-break mt-4">
    <x-contract.dual :en="$LB('6. Volunteer Work Hours:')" :ar="$LB($Glyphs('6. ساعات العمل التطوعّي:'))" />

    <x-contract.schedule-clause :contract="$contract" :for-pdf="$forPdf" />

        @if($isGeneralProject)
            <x-contract.dual class="mt-1"
                :en="'Saturdays are considered working days when necessary and not treated as extra hours.'"
                :ar="$Glyphs('يعتبر يوم السبت يوم عمل في حال رأت الإدارة الحاجة لذلك، ولا يعتبر دوام إضافي.')"/>
        @endif

        @if($isStudyZone)
            <x-contract.weekend-clause :contract="$contract" :for-pdf="$forPdf" />

            <x-contract.dual class="mt-1"
                :en="'Volunteers are entitled to a second day off in the week, to be determined in coordination with management and based on work requirements. It is permitted to adjust working hours and holidays in months that require opening the center 7 days, in coordination with the direct manager and the HR officer.'"
                :ar="$Glyphs('يحق للمتطوع يوم عطلة ثان ضمن الأسبوع، يتم تحديده بالتنسيق مع الإدارة وما يتطلبه العمل. يسمح بتعديل أوقات الدوام والعطل في الأشهر التي تتطلب فتح المركز 7 أيام، وذلك بالتنسيق مع المدير المباشر ومسؤول الموارد البشرية.')"/>
        @endif


</div>

<div class="avoid-break mt-4">
    <x-contract.dual :en="$LB('7. Stipend and Benefits:')" :ar="$LB($Glyphs('7. المكافأة المالية والمزايا:'))" />

    <x-contract.pay-clause :contract="$contract" :for-pdf="$forPdf" />
</div>

<div class="avoid-break mt-4">
    <x-contract.dual :en="$LB('8. Duties:')" :ar="$LB($Glyphs('8. المسؤوليات:'))" />
    <x-contract.dual class="mt-1"
        :en="'The second party commits to the description of the volunteer work approved by the first team. Therefore, their signature on this contract is an acknowledgment of their full commitment to it.'" 
        :ar="$Glyphs('يلتزم الفريق الثاني بتوصيف العمل التطوّعي الذي أقرّه الفريق الأول، وعليه فإن توقيعه على هذا العقد هو إقرار منه بالتزامه الكامل بها.')" />
</div>

