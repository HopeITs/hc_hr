<?php

/*

namespace App\Exports;

use App\Enums\ContractPlace;
use App\Enums\ContractType as ContractTypeEnum;
use App\Models\ContractType;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\Console\Helper\TableStyle;

class ContractTypeExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(
        protected Collection $collection
    ) {}

    public function collection(): Collection
    {
        return $this->collection;
    }

    public function headings(): array
    {
        return [
            'ID',
            __('general.name') . ' (AR)',
            __('general.name') . ' (EN)',
            __('general.place'),
            __('general.type'),
            __('general.created-at'),
        ];
    }

    public function map($row): array
    {
        return [
            $row->id,
            $row->name_ar,
            $row->name_en,
            ContractPlace::getLocalizedName($row->place)['value'] ?? '',
            ContractTypeEnum::getLocalizedName($row->type)['value'] ?? '',
            $row->created_at ? $row->created_at->format('Y-m-d H:i') : '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }


}
*/

// namespace App\Exports;

// use App\Models\ContractType;
// use Maatwebsite\Excel\Concerns\FromCollection;
// use Maatwebsite\Excel\Concerns\WithHeadings;
// use Maatwebsite\Excel\Concerns\WithMapping;
// use Maatwebsite\Excel\Concerns\ShouldAutoSize;
// use Maatwebsite\Excel\Concerns\WithStyles;
// use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
// use Illuminate\Support\Collection;
// use App\Enums\ContractPlace;
// use App\Enums\ContractType as ContractTypeEnum;

// class ContractTypeExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
// {
//     public function __construct(
//         protected Collection $collection,
//         protected array $visibleColumns // <-- ADDED: Accepts the dynamic columns from the table
//     ) {}

//     public function collection(): Collection
//     {
//         return $this->collection;
//     }

//     public function headings(): array
//     {
//         // Return exactly the column labels that are currently visible on the frontend
//         return $this->visibleColumns;
//     }

//     public function map($row): array
//     {
//         // 1. Map all potential data to their exact translated column labels
//         $allData = [
//             'ID' => $row->id,
//             __('general.name') . ' (AR)' => $row->name_ar,
//             __('general.name') . ' (EN)' => $row->name_en,
//             __('general.place') => ContractPlace::getLocalizedName($row->place)['value'] ?? '',
//             __('general.type') => ContractTypeEnum::getLocalizedName($row->type)['value'] ?? '',
//             __('general.actions') => '', // Usually hidden, but prevents errors if passed
//         ];

//         // 2. Loop through ONLY the visible columns and build the row in that exact order
//         $mappedRow = [];
//         foreach ($this->visibleColumns as $columnLabel) {
//             $mappedRow[] = $allData[$columnLabel] ?? '';
//         }

//         return $mappedRow;
//     }

//     public function styles(Worksheet $sheet): array
//     {
//         // Make the first row (headers) bold
//         return [
//             1 => ['font' => ['bold' => true]],
//         ];
//     }
// }

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ContractTypeExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithProperties, WithStyles
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
                'name_ar' => __('general.name', [], 'en').' (AR)',
                'name_en' => __('general.name', [], 'en').' (EN)',
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
                'name_ar' => $row->name_ar,
                'name_en' => $row->name_en,
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
        $table = new Table($globalRange, 'ContractTypes');

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
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
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
