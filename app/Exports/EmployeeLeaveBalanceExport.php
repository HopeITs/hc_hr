<?php

namespace App\Exports;

use App\Enums\LeaveTypeCases;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class EmployeeLeaveBalanceExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithProperties, WithStyles
{
    public function __construct(
        protected Collection $collection,
        protected array $visibleColumnCodes,
    ) {}

    public function properties(): array
    {
        return [
            'creator' => 'Hope HR',
            'lastModifiedBy' => ' ',
            'title' => ' ',
            'company' => 'Hope Center',
        ];
    }

    public function collection(): Collection
    {
        return $this->collection;
    }

    public function headings(): array
    {
        $headers = [];

        foreach ($this->visibleColumnCodes as $code) {
            $headers[] = match ($code) {
                'employee' => __('general.name', [], 'en'),
                'years_of_service_formatted' => __('general.years-of-service', [], 'en'),
                'deserved' => __('general.deserved', [], 'en'),
                'used' => __('general.used', [], 'en'),
                'remaining' => __('general.remaining', [], 'en'),
                default => $code,
            };
        }

        return $headers;
    }

    public function map($row): array
    {
        // MICRO-SANDWICH: Ensure accessors resolve to English safely!
        $orig = app()->getLocale();
        app()->setLocale('en');

        $mappedRow = [];

        foreach ($this->visibleColumnCodes as $code) {
            $mappedRow[] = match ($code) {
                'employee' => $row->name,
                'years_of_service_formatted' => $row->years_of_service_formatted,

                // FIX: Strip HTML tags to make the Excel sheet clean!
                'deserved' => strip_tags($this->annualBalance($row)?->deserved_formatted ?? '0'),
                'used' => strip_tags($this->annualBalance($row)?->used_formatted ?? '0'),
                'remaining' => strip_tags($this->annualBalance($row)?->remaining ?? '0'),

                default => '',
            };
        }

        // Restore original language immediately
        app()->setLocale($orig);

        return $mappedRow;
    }

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $globalRange = 'A1:'.$highestColumn.$highestRow;

        $table = new Table($globalRange, 'LeaveBalances');
        $tableStyle = new TableStyle;
        $tableStyle->setTheme(TableStyle::TABLE_STYLE_LIGHT1);
        $tableStyle->setShowRowStripes(true);
        $table->setStyle($tableStyle);

        $sheet->addTable($table);

        return [
            $globalRange => [
                'font' => [
                    'name' => 'Somar Sans',
                    'size' => 11,
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    ],
                ],
            ],
            1 => [
                'font' => [
                    'name' => 'Somar Sans',
                    'bold' => true,
                    'size' => 12,
                    'color' => ['argb' => 'FFFFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFBE1F24'],
                ],
            ],
        ];
    }

    private function annualBalance($employee): ?\App\Models\LeaveBalance
    {
        $contract = $employee->latestActiveContract();

        return $contract?->leaveBalances
            ->firstWhere('type_id', LeaveTypeCases::Annual)
            ?->setRelation('contract', $contract);
    }
}
