@php
    use App\Enums\ContractType as ContractTypeEnum;
    use App\Enums\Gender;
    use App\Models\ContractAmendment;

    /**
     * The amendment sheet (ملحق عقد): one A4 page on the contract's letterhead,
     * listing only what changed, then the clause that keeps the rest of the
     * contract in force and the two signatures.
     *
     * The new schedule and pay are worded by <x-contract.schedule-clause> and
     * <x-contract.pay-clause> themselves, fed the contract as it becomes
     * ($after), so the sheet never words a term differently from the contract.
     *
     * @var \App\Models\ContractAmendment $amendment
     * @var \App\Models\Contract $contract  the contract as it stands
     * @var \App\Models\Contract $after     the same contract with the amendment's terms, never saved
     */
    $changed = $amendment->changedFields();
    $emp = $contract->employee;

    $nameEn = trim(($emp?->first_name_en ?? '').' '.($emp?->father_name_en ?? '').' '.($emp?->last_name_en ?? '')) ?: '—';
    $nameAr = trim(($emp?->first_name_ar ?? '').' '.($emp?->father_name_ar ?? '').' '.($emp?->last_name_ar ?? '')) ?: '—';
    $female = (int) $emp?->gender === Gender::Female;

    $LB = fn (string $t) => '<span class="h-label-bold">'.$t.'</span>';

    $scheduleFields = array_diff(ContractAmendment::AMENDABLE_FIELDS, ['type', 'pay_mode', 'session_duration', 'currency', 'salary_per']);
    $scheduleChanged = array_intersect($scheduleFields, $changed) !== [];
    $payChanged = array_intersect(ContractAmendment::PAY_FIELDS, $changed) !== [];
    $typeChanged = in_array('type', $changed, true);

    $typeWords = [
        ContractTypeEnum::FullTime => ['en' => 'full time', 'ar' => 'دواماً كاملاً'],
        ContractTypeEnum::PartTime => ['en' => 'part time', 'ar' => 'دواماً جزئياً'],
        ContractTypeEnum::Freelance => ['en' => 'freelance', 'ar' => 'دواماً حراً'],
    ][(int) $after->type] ?? ['en' => '—', 'ar' => '—'];

    $effective = $amendment->effective_from->format('d/m/Y');

    // Numbered here, not with ++ inside the component attributes below: Blade
    // evaluates those expressions more than once, which skipped numbers.
    $number = 0;
    $typeNo = $typeChanged ? ++$number : null;
    $scheduleNo = $scheduleChanged ? ++$number : null;
    $payNo = $payChanged ? ++$number : null;
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">

<head>
    <meta charset="utf-8">
    <title>{{ $fileTitle }}</title>
    @include('livewire.contract.details._styles', ['inlineAssets' => $inlineAssets ?? false])
    <style>
        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .contract-a4 {
            height: 297mm;
            margin: 0 auto;
            box-shadow: none;
            border-radius: 0;
        }

        @page {
            size: A4 portrait;
            margin: 0;
        }
    </style>
</head>

<body>
    <div class="text-gray-900 contract-a4">
        <div class="contract-body">
            <div class="avoid-break contract-title-center">
                <div class="dual-row dual-tight">
                    <div class="dual-en fw-title">Contract Amendment</div>
                    <div class="contract-title-separator">|</div>
                    <div class="dual-ar fw-title">تعديل عقد</div>
                </div>
                {{-- Which amendment this is, so a contract amended twice has two
                     sheets that cannot be mistaken for one another. --}}
                <div class="dual-row dual-tight">
                    <div class="dual-en">No. {{ $amendment->number }}</div>
                    <div class="contract-title-separator">|</div>
                    <div class="dual-ar">رقم {{ $amendment->number }}</div>
                </div>
            </div>

            <div class="avoid-break mt-4">
                <x-contract.dual
                    :en="'The following amendments were made to the contract of the volunteer <strong>'.e($nameEn).'</strong>:'"
                    :ar="'تم إجراء التعديلات التالية في عقد '.($female ? 'المتطوعة' : 'المتطوع').' <strong>'.e($nameAr).'</strong>:'" />
            </div>

            @if($typeChanged)
                <div class="avoid-break mt-4">
                    <x-contract.dual
                        :en="$LB($typeNo.'. Type of work:').' to become <strong>'.$typeWords['en'].'</strong>.'"
                        :ar="$LB($typeNo.'. نوع الدوام:').' ليصبح <strong>'.$typeWords['ar'].'</strong>.'" />
                </div>
            @endif

            @if($scheduleChanged)
                <div class="avoid-break mt-4">
                    <x-contract.dual
                        :en="$LB($scheduleNo.'. Volunteer Work Hours,').' to become:'"
                        :ar="$LB($scheduleNo.'. ساعات العمل التطوعّي،').' لتصبح:'" />
                    <x-contract.schedule-clause :contract="$after" />
                </div>
            @endif

            @if($payChanged)
                <div class="avoid-break mt-4">
                    <x-contract.dual
                        :en="$LB($payNo.'. Stipend,').' to become:'"
                        :ar="$LB($payNo.'. المكافأة المالية،').' لتصبح:'" />
                    <x-contract.pay-clause :contract="$after" />
                </div>
            @endif

            <div class="avoid-break mt-6">
                <x-contract.dual
                    :en="'All other clauses of the original contract remain in force, except for the amendments above, starting from <strong>'.$effective.'</strong>.'"
                    :ar="'يحافظ على كافة بنود العقد الأساسي عدا التعديلات السابقة، وذلك ابتداءً من تاريخ <strong><span dir=\'ltr\'>'.$effective.'</span></strong>.'" />
            </div>

            <div class="avoid-break contract-signature">
                <div class="signature-party signature-party-second">
                    <div class="signature-role">{!! $LB('Second Party') !!} / {!! $LB('الفريق الثاني') !!}</div>
                    <div class="signature-caption">Name and Signature / الاسم والتوقيع</div>
                    <div class="signature-name">
                        <strong>{{ $nameEn }}</strong>
                        <br>
                        <strong>{{ $nameAr }}</strong>
                    </div>
                    <div class="signature-date">Date / التاريخ</div>
                </div>

                <div class="signature-party signature-party-first">
                    <div class="signature-role">{!! $LB('First Party') !!} / {!! $LB('الفريق الأول') !!}</div>
                    <div class="signature-caption">Name and Signature / الاسم والتوقيع</div>
                    <div class="signature-name">
                        <strong>Fredy Georges Youssef</strong>
                        <br>
                        <strong>فريدي جرجس يوسف</strong>
                    </div>
                    <div class="signature-date">Date / التاريخ</div>
                </div>
            </div>
        </div>

        <div class="contract-footer">1 / 1</div>
    </div>
</body>

</html>
