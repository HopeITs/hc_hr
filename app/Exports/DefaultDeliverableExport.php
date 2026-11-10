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

class DefaultDeliverableExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithProperties, WithStyles
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
                'id' => 'ID',
                'employee_id' => trans_choice('general.employee', 1, [], 'en'),
                'city_name_en', 'city_name_ar' => trans_choice('general.city', 1, [], 'en'),
                'item_name' => __('general.item', [], 'en'),
                default => $code,
            };
        }

        return $headers;
    }

    public function map($row): array
    {

        $orig = app()->getLocale();
        app()->setLocale('en');

        $mappedRow = [];

        foreach ($this->visibleColumnCodes as $code) {
            $mappedRow[] = match ($code) {
                'id' => $row->id,
                'employee_id' => $row->employee?->name ?? '—',
                'city_name_en', 'city_name_ar' => $row->city?->name_en ?? '—',
                'item_name' => $row->item?->name ?? '—',
                default => '',
            };
        }

        app()->setLocale($orig);

        return $mappedRow;
    }

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $globalRange = 'A1:'.$highestColumn.$highestRow;

        $table = new Table($globalRange, 'DefaultDeliverables');
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
