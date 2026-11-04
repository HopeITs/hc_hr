<?php

namespace App\Services;

/**
 * What Post Gen read off one ID card or contract. Every field is a string,
 * '' when the document does not show it. Nothing here is checked: it only
 * fills a form that a person reviews before saving.
 */
final class ExtractedDocument
{
    /** @param  array<string, string>  $fields */
    public function __construct(
        public readonly string $kind,
        public readonly string $docType,
        public readonly array $fields,
        public readonly string $text,
        // the main model was busy and the lighter one read it: less exact, digits above all
        public readonly bool $lite,
    ) {}

    public function get(string $field): ?string
    {
        $value = trim($this->fields[$field] ?? '');

        return $value === '' ? null : $value;
    }
}
