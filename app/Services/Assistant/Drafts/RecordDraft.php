<?php

namespace App\Services\Assistant\Drafts;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * One kind of record the assistant can prepare from the chat (a leave
 * request, a news item, an employee, a contract, ...).
 *
 * The model only ever drafts: its draft tool checks the values with the
 * create screen's own rules and returns them as a proposal, which the chat
 * shows as a card. Only the user's Confirm click creates the record, through
 * submit(), which checks the permission and validates everything again.
 */
interface RecordDraft
{
    /** Stored with each proposal: which RecordDraft submits it. */
    public function type(): string;

    /**
     * The tools this user may call for this record (Gemini function
     * declarations); none when the user may not create it.
     *
     * @return list<array{name: string, description: string, parameters?: array<string, mixed>}>
     */
    public function declarations(User $user): array;

    /**
     * Runs one of its tools with the model's (untrusted) arguments. A draft
     * that passed every check returns ['status' => 'drafted', 'proposal' => [...]].
     *
     * @return array<string, mixed>
     */
    public function call(User $user, string $tool, array $args): array;

    /**
     * What the card shows for a proposal: a title, an <x-ic> icon and the
     * labelled values.
     *
     * @return array{title: string, icon: string, rows: array<string, string>}
     */
    public function card(array $proposal): array;

    /**
     * Creates the record the user confirmed. The token is one per shown
     * draft, so a repeat may return the first result instead of a copy.
     *
     * @return array{message: string, url?: ?string, link?: ?string} the line added to the chat, and where to see the record
     *
     * @throws ValidationException when it may no longer be created as drafted
     */
    public function submit(User $user, array $proposal, string $token): array;
}
