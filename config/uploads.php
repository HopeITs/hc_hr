<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Image upload profiles
    |--------------------------------------------------------------------------
    |
    | Every user-supplied image in the application is processed by
    | App\Services\SecureImageUploadService using one of these profiles. The
    | service decodes and re-encodes each upload, so the stored bytes are
    | always produced by the server: a polyglot file that is simultaneously a
    | valid image and a valid PHP script cannot survive the round trip, and the
    | client's original filename and extension are never used.
    |
    | max_bytes            Hard byte ceiling, checked before decoding.
    | max_width/max_height Per-axis pixel ceiling.
    | max_pixels           Total pixel ceiling. This is the decompression-bomb
    |                      guard: a 40 KB PNG can declare 30000x30000 pixels,
    |                      which would allocate gigabytes when decoded.
    | max_aspect_ratio     Longest side divided by shortest side.
    | output               Storage format. "preserve" keeps JPEG/PNG/WebP as-is
    |                      (so logo transparency survives); a concrete format
    |                      name forces every upload into that format.
    | quality              Encoder quality for lossy output formats.
    |
    */

    'images' => [

        'avatar' => [
            'max_bytes' => 4 * 1024 * 1024,
            'max_width' => 4000,
            'max_height' => 4000,
            'max_pixels' => 12_000_000,
            'max_aspect_ratio' => 3.0,
            'output' => 'jpeg',
            'quality' => 85,
        ],

        'logo' => [
            'max_bytes' => 2 * 1024 * 1024,
            'max_width' => 2000,
            'max_height' => 2000,
            'max_pixels' => 4_000_000,
            'max_aspect_ratio' => 4.0,
            'output' => 'preserve',
            'quality' => 90,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed image types
    |--------------------------------------------------------------------------
    |
    | Deliberately narrow. SVG is excluded because it is an XML document that
    | can carry script; GIF is excluded because of animated-frame decoding
    | surface and because nothing in the product needs it. Everything not
    | listed here — archives, documents, BMP, TIFF, ICO, PHP — is rejected.
    |
    */

    'allowed_image_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed avatar links for public digital ID cards
    |--------------------------------------------------------------------------
    |
    | Employee photos live on the private disk. The public digital-ID page is
    | authorized by an unguessable per-employee token rather than a session, so
    | it receives a separate short-lived signed avatar URL instead of the
    | authenticated internal endpoint.
    |
    */

    'digital_id_avatar_ttl' => 10, // minutes

    'office' => [
        // Conversion invokes a large native parser. Keep it disabled on the
        // web node unless it is moved to an isolated, low-privilege worker.
        'conversion_enabled' => env('OFFICE_PREVIEW_CONVERSION_ENABLED', false),
        'max_entries' => 1000,
        'max_total_uncompressed_bytes' => 100 * 1024 * 1024,
        'max_entry_uncompressed_bytes' => 20 * 1024 * 1024,
        'max_compression_ratio' => 50,
        'max_preview_xml_bytes' => 10 * 1024 * 1024,
    ],

];
