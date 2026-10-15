@php
    use Carbon\Carbon;
    use App\Support\ArabicText as AR;

    $forPdf = $forPdf ?? false;

    $Glyphs = fn($s) => $forPdf ? AR::glyphs((string) $s) : (string) $s;
    $Digits = fn($s) => $forPdf ? ar_digits((string) $s) : (string) $s;

    $loc = $locale ?? app()->getLocale();
    $emp = $contract->employee;
    $position = $contract->position;
    $program = $position?->program;

    $nameEn = trim(($emp?->first_name_en ?? '') . ' ' . ($emp?->father_name_en ?? '') . ' ' . ($emp?->last_name_en ?? ''));
    $nameEn = $nameEn !== '' ? $nameEn : '—';


    $nameAr = trim(($emp?->first_name_ar ?? '') . ' ' . ($emp?->father_name_ar ?? '') . ' ' . ($emp?->last_name_ar ?? ''));
    $nameAr = $nameAr !== '' ? $nameAr : '—';

    $addressEn = $emp?->address_en ?? '—';
    $addressAr = $emp?->address_ar ?? '—';

    // DOB
    $dob = $emp?->dob;
    $dobStr = $dob ? Carbon::parse($dob)->format('d/m/Y') : '—';
    $dobStrAr = $Digits($dobStr);
    // Other info
    $pobEn = trim((string) ($emp?->pob_en ?? '—'));
    $pobAr = trim((string) ($emp?->pob_ar ?? '—'));
    $nationalId = $emp?->national_number;

    $nationalityRaw = (string) ($emp?->nationality ?? '');
    $natKey = strtoupper(trim($nationalityRaw));
    $NATIONALITY_MAP = [
        '1' => ['en' => 'Syrian', 'ar' => 'سوري'],
        'SY' => ['en' => 'Syrian', 'ar' => 'سوري'],
        'SYR' => ['en' => 'Syrian', 'ar' => 'سوري'],
    ];
    $natEn = $NATIONALITY_MAP[$natKey]['en'] ?? ($nationalityRaw ?: '—');
    $natAr = $Glyphs($NATIONALITY_MAP[$natKey]['ar'] ?? ($nationalityRaw ?: '—'));
    // Position and program
    $req = $position?->requiredPosition;
    $positionEN = $req?->title_en ?? ($position?->title_en ?? '—');
    $positionAR = $req?->title_ar ?? ($position?->title_ar ?? '—');
    $program = $program?->name ?? '—';
    //Center and City Name
    $centerNameEn = $position?->center?->name_en ?? $position?->center?->name
        ?? (($position?->program?->name ?? '') . ' - ' . ($position?->city?->name_en ?? '—'));
    $centerNameAr = $position?->center?->name_ar ?? $position?->center?->name
        ?? (($position?->program?->name ?? '') . ' - ' . ($position?->city?->name_ar ?? '—'));

    $cityNameEn = $position?->city?->name_en ?? '—';
    $cityNameAr = $position?->city?->name_ar ?? '—';

    $workPlaceEn = $position?->center_id ? ($cityNameEn . ' - ' . $centerNameEn) : $centerNameEn;
    $workPlaceAr = $position?->center_id ? ($cityNameAr . ' - ' . $centerNameAr) : $centerNameAr;
    $isOnlineAgreement = (int) ($contract->contractType?->place ?? 0) === \App\Enums\ContractPlace::Online
        || str_contains(strtolower((string) ($contract->contractType?->name_en ?? '')), 'online');
    if ($isOnlineAgreement) {
        $workPlaceEn = 'online';
        $workPlaceAr = 'اونلاين';
    }
    // Escaping
    $esc = fn($v) => e((string) ($v === '' || $v === null ? '—' : $v));
    $escAr = fn($v) => e((string) ($v === '' || $v === null ? '—' : $Glyphs($v)));

    $L = fn(string $t) => '<span class="h-label">' . $t . '</span>';
    $LB = fn(string $t) => '<span class="h-label-bold">' . $t . '</span>';
@endphp

<div class="avoid-break contract-title-center">
    <div class="dual-row dual-tight">
        <div class="dual-en fw-title">
            Voluntary Service Contract
        </div>

        <div class="contract-title-separator">|</div>

        <div class="dual-ar fw-title">
            {{ $Glyphs('عقد تقديم خدمة تطوعيّّة محددة') }}
        </div>
    </div>
</div>

<div class="avoid-break mt-4">
    <x-contract.dual rowClass="fw-bold" :en="'Between'" :ar="$Glyphs('بين')" />
    <x-contract.dual rowClass="mt-1" :en="$L('First Party:')" :ar="$L($Glyphs('الفريق الأول:'))" />
    <x-contract.dual :en="'<strong>Christian Hope Center - Syria</strong><br>Represented by the Vice chairman of the board of trustees<br><strong>Mr. Fredy Youssef</strong>'"
        :ar="$Glyphs(
            '<strong>مؤسسة مركز الرجاء المسيحي - سوريا</strong><br>ينوب عنها بالتوقيع نائب رئيس مجلس الأمناء<br><strong>السيد فريدي يوسف</strong>')" />
</div>

<div class="avoid-break mt-1">
    <x-contract.dual rowClass="mt-1 fw-bold" :en="'And'" :ar="$Glyphs('و')" />
    <x-contract.dual rowClass="mt-1" :en="$L('Second Party:')" :ar="$L($Glyphs('الفريق الثاني:'))" />
    <x-contract.dual :en="'Ms./Mrs.: <strong>' . $esc($nameEn) . '</strong>'" :ar="$Glyphs('الاسم الثلاثي:').
    ' <strong>'.$escAr($nameAr).
    '</strong>'" />

    <x-contract.dual :en="'Date and Place of Birth: <strong>' . $esc($pobEn) . ', ' . $esc($dobStr) . '</strong>'"
        :ar="$Glyphs('مكان وتاريخ الولادة:').
        ' <strong>'.$escAr($pobAr).
        '، <span dir=\'ltr\'>'.$esc($dobStrAr).'</span>'.
        '</strong>'" />

    <x-contract.dual rowClass="mt-3" :en="'National ID number: <strong>' . $esc($nationalId) . '</strong>'"
        :ar="$Glyphs('الرقم الوطني:').
        ' <strong>'.$esc($Digits($nationalId ?? '')).
        '</strong>'" />

    <x-contract.dual :en="'Nationality: <strong>' . $esc($natEn) . '</strong>'" :ar="$Glyphs('الجنسية:').
    ' <strong>'.$escAr($natAr).
    '</strong>'" />

    <x-contract.dual :en="'Address: <strong>' . $esc($addressEn) . '</strong>'" :ar="$Glyphs('العنوان:').
    ' <strong>'.$escAr($addressAr).
    '</strong>'" />
</div>

<div class="avoid-break mt-4">
    <x-contract.dual rowClass="fw-bold" :en="'Preamble:'" :ar="$Glyphs('مقدمة العقد:')" />
    <x-contract.dual rowClass="mt-tight-neg" :en="'Since the first party oversees the implementation of programs in Syria and the nature of the program requires a volunteer in the capacity of <strong>' .
        $esc($positionEN) .
        '</strong>'"
        :ar="$Glyphs('لما كان الفريق الأول يشرف على تنفيذ البرامج في سوريا، وطبيعة البرنامج تحتاج إلى متطوع بصفة ').
        '<strong>'.$escAr($positionAR).
        '</strong>'" />

    <x-contract.dual :en="'to carry out the tasks mentioned in the attached job description,'" :ar="$Glyphs('لتنفيذ المهام المذكورة في التوصيف الوظيفي المرفق،')" />

    <x-contract.dual :en="'And since the second team has shown willingness to undertake this voluntary work starting from the date of signing this contract,'" :ar="$Glyphs(
        'ولما كان الفريق الثاني قد أبدى استعداده للقيام بهذا العمل التطوعي ابتداءً من تاريخ توقيع هذا العقد،',
    )" />

    <x-contract.dual :en="'The contract has been made between the two parties with mutual agreement of the following:'" :ar="$Glyphs('تم العقد بين الطرفين والقبول المشترك لما يلي:')" />
</div>


<div class="avoid-break mt-3">

    <x-contract.dual :en="$LB('1. Contract Preamble:') . ' an integral part of it.'" :ar="$LB($Glyphs('1. مقدمة العقد:')) . ' ' . $Glyphs('جزء لا يتجزأ منه.')" />

    <x-contract.dual :en="$LB('2. Contract type:') . ' <strong>Voluntary Service Contract.</strong>'"
        :ar="$LB($Glyphs('2. نوع العقد:')).
        ' <strong>'.$Glyphs('عقد تقديم خدمة تطوعيّّة محددة.').
        '</strong>'" />

    <x-contract.dual :en="$LB('3. Place of Work:') . ' <strong>' . $esc($workPlaceEn) . '</strong>'"
        :ar="$LB($Glyphs('3. مكان العمل:')).
        ' <strong>'.$escAr($workPlaceAr).
        '</strong>'" />

</div>
