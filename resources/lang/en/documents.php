<?php

/*
 * Reading a national ID card from a scan (ReadsScannedDocuments,
 * <x-document-reader>). What is read only fills the form.
 */

return [
    'reader' => [
        'files' => 'Scanned files',
        'read' => 'Read the file',
        'uploading' => 'Uploading…',
        'document' => 'the document',
        'id-title' => 'Fill from an ID card',
        'id-hint' => 'Attach a photo or scan of the ID card (front and back, up to 4 files, JPEG, PNG or PDF). Only empty fields are filled.',
        'filled' => '{0} Nothing new was read from :doc.|{1} One field was filled from :doc.|[2,*] :count fields were filled from :doc.',
        'check-before-saving' => 'Check every filled field against the paper before saving, the numbers above all.',
        'lite' => 'Read by the lighter model: check the digits',
        'too-many' => 'Too many files read in the last hour. Try again in :minutes minutes.',
    ],
];
