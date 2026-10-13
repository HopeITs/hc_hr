{{-- <style>
    .contract-a4{
        width: 210mm;
        height: 300mm;
        max-width: 100%;
        margin: 0 auto;
        background: #fff url('{{ asset('assets/img/Header.svg') }}') no-repeat top center;
        background-size:93%;
        padding: 35mm 10mm 10mm 10mm ;
        box-shadow: 0 8px 24px rgba(88,89,91,.06);
        background-position: center 8mm;
        border-radius: 16px;
        position: relative;
        direction: ltr;
        display: flex;
        flex-direction: column;
    }

        .contract-title-center{
        width: 100%;
        text-align: center;
        margin-top: 4px;
        margin-bottom: 20px;
    }

    .contract-title-center .dual-row{
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
        gap: 10px !important;
        grid-template-columns: unset !important;
    }

    .contract-title-center .dual-en,
    .contract-title-center .dual-ar{
        width: auto !important;
        flex: unset !important;
        font-family: 'somar','NotoNaskhArabic','DejaVu Sans',sans-serif !important;
        font-weight: 700 !important;
        font-size: 16pt !important;
        line-height: 1.2 !important;
        text-align: center !important;
    }

    .contract-title-separator{
        font-family: 'somar','NotoNaskhArabic','DejaVu Sans',sans-serif;
        font-weight: 700;
        font-size: 16pt;
    }

    .contract-body{
        margin-top: 2mm;

        flex: 1;
        overflow: hidden;
    }

    .contract-footer{
        position: absolute;
        right: 18mm;
        bottom: 10mm;
        font-size: 12px;
        opacity: .7;
    }

    .h-label { color:#be1f24; font-weight:700; }
    .tight p{ margin:0 0 .45rem 0; }

    .dual-row{
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
        align-items: start;
        margin-top: .35rem;
    }
    .dual-col{ line-height: 1.65; }
    .dual-en{ text-align: left;  direction: ltr; }
    .dual-ar{
        text-align: right; direction: rtl;
        unicode-bidi: plaintext;
    }

    @media print{
        @page { size: A4 portrait; margin: 0; }
        .no-print{ display:none!important; }
        .contract-a4{ box-shadow: none; border-radius: 0; }
        .page-break{ page-break-after: always; }
    }
</style> --}}


{{-- WAS GOOD --}}
@php
    // Browsershot renders this HTML from a file:// temp path, so every asset()
    // URL below becomes a real HTTP request back to this app's own web server.
    // Locally that's instant; on the production host the loopback to our own
    // domain never completes, so Chrome never reaches networkidle0 and the
    // render dies on Puppeteer's navigation timeout. Inlining the bytes as
    // data URIs removes the network hop entirely.
    // Only done for the PDF path ($inlineAssets) — on-screen pages keep using
    // cheap asset() URLs so the browser can cache them normally.
    $inlineAssets ??= false;

    $dataUri = function (string $path, string $mime) use ($inlineAssets) {
        // Fall back to the URL if the file is missing, so a stray asset
        // degrades to a broken image instead of a 500 on the whole export.
        if (! $inlineAssets || ! is_file(public_path($path))) {
            return asset($path);
        }

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents(public_path($path)));
    };

    $fontSrc = fn (string $path) => $dataUri($path, 'font/truetype;charset=utf-8');
    $imgSrc = fn (string $path) => $dataUri(
        $path,
        str_ends_with(strtolower($path), '.svg') ? 'image/svg+xml' : 'image/png',
    );
@endphp
 <style>
    /* ==========================================
       FONTS
    ========================================== */
@font-face {
    font-family: 'somar-regular';
    src: url('{{ $fontSrc("fonts/SomarSans/SomarSans-Regular.ttf") }}') format('truetype');
     font-weight:400; font-style:normal;
}

@font-face {
    font-family: 'somar-bold';
    src: url('{{ $fontSrc("fonts/SomarSans/SOMARSANS-BOLD.ttf") }}') format('truetype');
                font-weight: 700;
            font-style: normal;
}

@font-face {
    font-family: 'somar-black';
    src: url('{{ $fontSrc("fonts/SomarSans/SOMARSANS-BLACK.ttf") }}') format('truetype');
                font-weight: 900;
            font-style: normal;
}
        @font-face {
            font-family: 'NotoNaskhArabic';
            src: url('{{ $fontSrc("fonts/NotoNaskh/NotoNaskhArabic-Regular.ttf") }}') format('truetype');
            font-weight: 400;
            font-style: normal;
        }

        @font-face {
            font-family: 'NotoNaskhArabic';
            src: url('{{ $fontSrc("fonts/NotoNaskh/NotoNaskhArabic-Bold.ttf") }}') format('truetype');
            font-weight: 700;
            font-style: normal;
        }

    :root{
        --main:#58595b;
        --red:#be1f24;
    }

    .contract-a4{
        width:210mm;
        height:300mm;
        max-width:100%;
        margin:0 auto;
        /* border-box: padding must stay inside height, otherwise the box
           grows past one physical page and the tail (footer/page number)
           spills onto a blank following page when printed. */
        box-sizing:border-box;
        background-color:#fff;
        background-image:url('{{ $imgSrc("assets/img/Header.svg") }}'), url('{{ $imgSrc("assets/img/Footer.png") }}');
        background-repeat:no-repeat, no-repeat;
        background-position:center 12mm, center calc(100% - 12mm);
        background-size:93% auto, 93% auto;
        padding: 41mm 10mm 20mm 10mm;
        box-shadow:0 8px 24px rgba(88,89,91,.06);
        border-radius:16px;
        position:relative;
        direction:ltr;
        display:flex;
        flex-direction:column;
    }

    .contract-body{
        margin-top:2mm;
        flex:1;
        overflow:hidden;
    display: flex;
    justify-content: space-between;
    overflow: hidden;
    flex-direction: column;
    padding-bottom: 120px;
    }

    .contract-footer{
        position:absolute;
        right:18mm;
        bottom:20mm;
        font-family:'Somar Sans';
        font-weight:700;
        font-size:7pt;
        color:var(--main);
    }





/* Updated Page Index (1/4) */
.contract-footer {
    position: absolute;
    left: 0;
    right: 0;
    text-align: center;
    bottom: 30mm;
    font-family: 'somar-bold';
    font-weight: 700;
    font-size: 7pt;
    color: var(--main);
    z-index: 2;
}

    /* ==========================================
       TITLE
       Voluntary Contract | عقد تطوع
    ========================================== */
    .contract-title-center{
        width:100%;
        text-align:center;
        margin-top:5px;
        margin-bottom:30px;
    }

    .contract-title-center .dual-row{
        display:flex !important;
        justify-content:center !important;
        align-items:center !important;
        gap:10px !important;
        grid-template-columns:unset !important;
    }

    .contract-title-center .dual-en,
    .contract-title-center .dual-ar,
    .contract-title-separator{
        width:auto !important;
        flex:unset !important;
        font-family:'somar-bold';
        font-weight:700;
        font-size:16pt;
        line-height:1.2;
        color:var(--main);
        text-align:center !important;
    }

    /* ==========================================
       BODY LAYOUT
    ========================================== */
    .dual-row{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:2rem;
        align-items:start;
        margin-top:.35rem;
    }

    .dual-col{
        line-height:1.65;
    }

    /* ==========================================
       NORMAL TEXT
    ========================================== */
    .dual-en{
        text-align:left;
        direction:ltr;
        font-family:'somar-regular';
        font-weight:400;
        font-size:10.5pt;
        /* line-height:20pt; */
        line-height: 1.35;
        color:var(--main);
    }

    .dual-ar{
        text-align:right;
        direction:rtl;
        unicode-bidi:plaintext;
        font-family:'somar-regular';
        font-weight:400;
        font-size:11pt;
        /* line-height:16pt; */
        line-height: 1.35;
        color:var(--main);
    }

    /* ==========================================
       NORMAL BOLD
       Between / بين
    ========================================== */
    .fw-bold .dual-en{
        font-family:'somar-bold';
        font-weight:700;
        font-size:10.5pt;
        color:var(--main);
    }

    .fw-bold .dual-ar{
        font-family:'somar-bold';
        font-weight:700;
        font-size:11pt;
        color:var(--main);
    }

    /* ==========================================
       LABELS RED
       First Party / الفريق الأول
    ========================================== */
    .h-label{
        color:var(--red);
        font-family:'somar-black';
        font-weight:900;
    }

    .h-label .dual-en,
    .dual-en .h-label{
        font-size:10.5pt;
    }

    .h-label .dual-ar,
    .dual-ar .h-label{
        font-size:11pt;
    }

    .h-label-bold{
    color: var(--red);
    font-family:'somar-bold';
    font-weight:700;
}

.h-label-bold .dual-en,
.dual-en .h-label-bold{
    font-size:10.5pt;
}

.h-label-bold .dual-ar,
.dual-ar .h-label-bold{
    font-size:11pt;
}

    /* ==========================================
       BLACK TEXT
       names, numbers, positions
    ========================================== */
    strong{
        font-family:'somar-bold';
        font-weight:900;
        color:var(--main);
    }

    /* ==========================================
       SPACING
    ========================================== */
    .tight p{
        margin:0 0 .45rem 0;
    }

    .mt-1{margin-top:4px;}
    .mt-2{margin-top:8px;}
    .mt-3{margin-top:12px;}
    .mt-4{margin-top:16px;}
    .mt-6{margin-top:24px;}

    .avoid-break{
        page-break-inside:avoid;
    }

    .mt-tight-neg{
        margin-top:-3px;
    }

    .list-plain{
        margin:0;
        padding:0;
    }

    .list-plain > div{
        margin-top:2px;
    }

    /* ==========================================
       SIGNATURE SECTION
       Last page of every contract type
    ========================================== */
    .contract-signature{
        position:relative;
        margin-top:45px;
        min-height:220px;
        padding-bottom:40px;
    }

    .signature-party{
        position:absolute;
        text-align:center;
        width:48%;
    }

    .signature-party-second{
        left:0;
    }

    .signature-party-first{
        right:0;
    }

    .signature-role{
        margin-bottom:12px;
    }

    .signature-caption{
        margin-bottom:15px;
        font-family: 'somar-regular';
        font-size: 14px;
    }

    .signature-name{
        line-height:1.6;
        margin-bottom:25px;
    }

    .signature-date{
        font-size:14px;
        font-family: 'somar-regular';
        margin-top: 25%;
    }

    /* ==========================================
       PRINT
    ========================================== */
    @media print{
        @page{
            size:A4 portrait;
            margin:0;
        }

        .no-print{
            display:none !important;
        }

        .contract-a4{
            box-shadow:none;
            border-radius:0;
        }

        .page-break{
            page-break-after:always;
        }
    }
</style>

