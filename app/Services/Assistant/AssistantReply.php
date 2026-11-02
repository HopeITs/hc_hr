<?php

namespace App\Services\Assistant;

/**
 * One answer of the assistant, and the record it drafted, if any.
 *
 * A draft is never created by the model: the page shows it with a confirm
 * button, and only the user's click creates it, through the RecordDraft of
 * its type (App\Services\Assistant\Drafts).
 */
final readonly class AssistantReply
{
    /**
     * @param  array<string, mixed>|null  $draft  what the draft tool returned as its proposal
     * @param  string  $draftType  the RecordDraft::type() that submits it
     */
    public function __construct(
        public string $text,
        public ?array $draft = null,
        public string $draftType = 'leave',
    ) {}
}
