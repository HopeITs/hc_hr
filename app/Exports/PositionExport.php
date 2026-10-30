<?php

namespace App\Exports;

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

class PositionExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithProperties, WithStyles
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
                'title_en' => __('general.title', [], 'en').' (EN)',
                'title_ar' => __('general.title', [], 'en').' (AR)',
                // Match both EN and AR generated codes from the table
                'city_name_en', 'city_name_ar' => trans_choice('general.city', 1, [], 'en'),
                'program_name' => trans_choice('general.program', 1, [], 'en'),
                'center_name' => trans_choice('general.center', 1, [], 'en'),
                'department_name' => trans_choice('general.department', 1, [], 'en'),
                'jobLevel_name_en', 'jobLevel_name_ar' => trans_choice('general.job-level', 1, [], 'en'),
                'directManager_title_en', 'directManager_title_ar' => __('general.direct-manager', [], 'en'),
                'is_active' => __('general.is-active', [], 'en'),
                default => $code,
            };
        }

        return $headers;
    }

    public function map($row): array
    {
        $mappedRow = [];

        foreach ($this->visibleColumnCodes as $code) {
            $mappedRow[] = match ($code) {
                'title_en' => $row->title_en,
                'title_ar' => $row->title_ar,
                'program_name' => $row->program?->name ?? '',
                'center_name' => $row->center?->name ?? '',
                'department_name' => $row->department?->name ?? '',

                // Force the English translation properties since the Excel locale is forced to English
                'city_name_en', 'city_name_ar' => $row->city?->name_en ?? '',
                'jobLevel_name_en', 'jobLevel_name_ar' => $row->jobLevel?->name_en ?? '',
                'directManager_title_en', 'directManager_title_ar' => $row->directManager?->title_en ?? '',

                'is_active' => $row->is_active ? 'Yes' : 'No',
                default => '',
            };
        }

        return $mappedRow;
    }

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $globalRange = 'A1:'.$highestColumn.$highestRow;

        $table = new Table($globalRange, 'Positions');
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
}
