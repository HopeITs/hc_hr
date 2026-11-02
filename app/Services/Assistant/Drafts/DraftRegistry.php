<?php

namespace App\Services\Assistant\Drafts;

use App\Models\User;
use App\Support\Navigation;

/**
 * Every kind of record the assistant can draft (RecordDraft), and which of
 * their tools a given user may call.
 */
class DraftRegistry
{
    /** Asked before any new record: continue in the chat, or open its page. */
    public const CHOICE_TOOL = 'offer_create_choice';

    /** @var list<class-string<RecordDraft>> */
    private const DRAFTS = [
        LeaveDraft::class,
        LeaveForEmployeeDraft::class,
        NewsDraft::class,
        EmployeeDraft::class,
        ContractDraft::class,
    ];

    /** Each kind's entry in the header's New menu (Navigation::newActions). */
    private const PAGES = [
        'leave' => 'new.leave',
        'leave_for_employee' => 'new.leave-for-employee',
        'employee' => 'new.employee',
        'contract' => 'new.contract',
        'news' => 'new.news',
    ];

    /** @return list<RecordDraft> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::DRAFTS);
    }

    public function for(string $type): ?RecordDraft
    {
        foreach ($this->all() as $draft) {
            if ($draft->type() === $type) {
                return $draft;
            }
        }

        return null;
    }

    /**
     * The tools of every kind this user may draft, and the choice offered
     * before any of them (offerChoice()).
     *
     * @return list<array<string, mixed>>
     */
    public function declarations(User $user): array
    {
        $pages = $this->pages($user);

        $choice = $pages === [] ? [] : [[
            'name' => self::CHOICE_TOOL,
            'description' => 'Call this FIRST whenever the user asks to add, create or request something new (a leave request, a leave for an employee, an employee, a contract, a news item), before asking for any detail. Two buttons are then shown under your answer: continue here in the chat, or open the page that adds it. Answer in one short line that they can choose, and wait. Only when they choose to continue here, go on with that record\'s own tools.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => array_keys($pages), 'description' => 'What the user wants to add.'],
                ],
                'required' => ['kind'],
            ],
        ]];

        return array_merge($choice, ...array_map(fn (RecordDraft $draft) => $draft->declarations($user), $this->all()));
    }

    /**
     * The choice shown before a new record: continue in the chat (when this
     * user can draft that kind here) or open its page. The link is the
     * header's New menu entry for it (Navigation::newActions), so it follows
     * the same permissions and is never taken from the model.
     *
     * @return array<string, mixed>
     */
    public function offerChoice(User $user, array $args): array
    {
        $kind = is_string($args['kind'] ?? null) ? $args['kind'] : '';
        $page = $this->pages($user)[$kind] ?? null;

        if ($page === null) {
            return ['error' => 'This user cannot add that here; tell them to ask HR.'];
        }

        $chat = $kind === 'leave'
            ? $user->profile !== null && $user->hasActivePermission('leaves.create')
            : ($this->for($kind)?->declarations($user) ?? []) !== [];

        return [
            'status' => 'choice',
            'draft_type' => 'choice',
            'proposal' => ['kind' => $kind, 'label' => $page['label'], 'url' => $page['url'], 'chat' => $chat],
            'note' => $chat
                ? 'Buttons are shown under your answer. Say in one short line that they can continue here or open the page, and wait for their choice.'
                : 'This cannot be filled in from the chat for this user: a button to open the page is shown under your answer. Say so in one short line.',
        ];
    }

    /**
     * Kinds this user may add, with the page that adds each.
     *
     * @return array<string, array{label: string, url: string}>
     */
    public function pages(User $user): array
    {
        $actions = collect(Navigation::newActions($user))->keyBy('id');
        $pages = [];

        foreach (self::PAGES as $type => $id) {
            if ($action = $actions->get($id)) {
                $pages[$type] = ['label' => $action['label'], 'url' => route($action['route'], $action['params'])];
            }
        }

        return $pages;
    }

    /** The draft owning a tool this user may call, if any. */
    public function owner(User $user, string $tool): ?RecordDraft
    {
        foreach ($this->all() as $draft) {
            if (in_array($tool, array_column($draft->declarations($user), 'name'), true)) {
                return $draft;
            }
        }

        return null;
    }
}
