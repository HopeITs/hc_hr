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

        .pdf-page {
            page-break-after: always;
        }

        .pdf-page:last-child {
            page-break-after: auto;
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
    @for ($i = 1; $i <= $totalPages; $i++)
        <div class="pdf-page">
            <div class="text-gray-900 contract-a4">
                <div class="contract-body">
                    @include("livewire.contract.details.{$category}.pages.page{$i}", [
                        'contract' => $contract,
                        'locale' => $locale,
                        'pageNo' => $i,
                        'totalPages' => $totalPages,
                    ])
                </div>

                <div class="contract-footer">{{ $i }} / {{ $totalPages }}</div>
            </div>
        </div>
    @endfor
</body>

</html>
