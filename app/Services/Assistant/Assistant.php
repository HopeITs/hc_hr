<?php

namespace App\Services\Assistant;

use App\Models\User;

/**
 * Answers one employee's question from the policies and their own record.
 */
class Assistant
{
    // Rounds of tool calls before the model must answer with what it has.
    private const MAX_TOOL_ROUNDS = 5;

    public function __construct(
        private ChatModel $model,
        private ?EmployeeTools $tools = null,
        private ?PolicyLibrary $policies = null,
    ) {
        $this->tools ??= new EmployeeTools;
        $this->policies ??= new PolicyLibrary;
    }

    /** Switched on, with at least one provider configured or Puter in the browser. */
    public function isAvailable(): bool
    {
        return config('assistant.enabled') && ($this->model->isConfigured() || $this->usesBrowser());
    }

    /** At least one provider this server calls itself. */
    public function hasServerModel(): bool
    {
        return $this->model->isConfigured();
    }

    /** Puter.js answers from the employee's browser when no server provider can. */
    public function usesBrowser(): bool
    {
        return (bool) config('assistant.puter.enabled');
    }

    /**
     * @param  list<array{role: 'user'|'assistant', text: string}>  $history  earlier turns, oldest first
     *
     * The text, and the last leave request the model drafted in this reply
     * (never submitted: the page asks the employee to confirm it).
     *
     * @throws GeminiException
     */
    public function reply(User $user, array $history, string $question): AssistantReply
    {
        $system = $this->systemInstruction($user, $question);
        $declarations = $this->tools->declarations($user);
        $contents = $this->contents($history, $question);
        $proposal = null;
        // One provider answers every round of this reply unless it fails.
        $model = $this->model instanceof ModelRouter ? $this->model->conversation() : $this->model;

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            // Tools stay declared on every round: Gemini rejects function-call
            // turns in the contents of a request that declares none.
            $content = $model->generate($contents, $system, $declarations);

            $calls = GeminiClient::functionCalls($content);

            if ($calls === [] || $round === self::MAX_TOOL_ROUNDS) {
                return $this->answer(GeminiClient::text($content) ?: $this->fallback($question), $proposal);
            }

            // Sent back unchanged: newer models sign their function-call turns.
            $contents[] = $content;
            $contents[] = ['role' => 'user', 'parts' => $this->runTools($user, $calls, $proposal)];
        }

        return $this->answer($this->fallback($question), $proposal);
    }

    /**
     * The same conversation with the model asked from the browser (Puter.js)
     * instead of from here, one round at a time. The state stays on the
     * server; the browser is given each request and sends back the model's
     * message, and every tool still runs here, as the signed-in user. A
     * forged message can only make the model "say" something to the
     * employee who forged it, or call a tool they may call anyway.
     *
     * @param  list<array{role: 'user'|'assistant', text: string}>  $history
     * @return array{0: array<string, mixed>, 1: array{messages: list<array<string, mixed>>, tools: list<array<string, mixed>>}} the state, and the request
     */
    public function begin(User $user, array $history, string $question): array
    {
        $state = [
            'question' => $question,
            'contents' => $this->contents($history, $question),
            'proposal' => null,
            'round' => 0,
        ];

        return [$state, $this->browserRequest($user, $state)];
    }

    /**
     * One answer of the browser's model (a chat-completions message): either
     * the next request, after running the tools it called, or the reply.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $message
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>, 2: ?AssistantReply}
     */
    public function advance(User $user, array $state, array $message): array
    {
        $content = OpenAiCompatibleClient::turn($message);
        $calls = GeminiClient::functionCalls($content);
        $proposal = $state['proposal'] ?? null;

        if ($calls === [] || ($state['round'] ?? 0) >= self::MAX_TOOL_ROUNDS) {
            return [$state, null, $this->answer(GeminiClient::text($content) ?: $this->fallback($state['question']), $proposal)];
        }

        $state['contents'][] = $content;
        $state['contents'][] = ['role' => 'user', 'parts' => $this->runTools($user, $calls, $proposal)];
        $state['proposal'] = $proposal;
        $state['round'] = ($state['round'] ?? 0) + 1;

        return [$state, $this->browserRequest($user, $state), null];
    }

    /**
     * @param  list<array{name: string, args: mixed}>  $calls
     * @return list<array<string, mixed>> the function responses
     */
    private function runTools(User $user, array $calls, ?array &$proposal): array
    {
        $parts = [];

        foreach ($calls as $call) {
            $response = $this->tools->call($user, $call['name'], is_array($call['args']) ? $call['args'] : []);

            // Only a draft a tool checked, never anything the model wrote itself.
            if (($response['status'] ?? null) === 'drafted' && is_array($response['proposal'] ?? null)) {
                $proposal = ['type' => $response['draft_type'] ?? 'leave', 'data' => $response['proposal']];
            }

            // The chat-or-page buttons; a draft made in the same reply wins.
            if (($response['status'] ?? null) === 'choice' && ($proposal === null || $proposal['type'] === 'choice')) {
                $proposal = ['type' => 'choice', 'data' => $response['proposal']];
            }

            $parts[] = ['functionResponse' => ['name' => $call['name'], 'response' => $response]];
        }

        return $parts;
    }

    /** @param  array{type: string, data: array<string, mixed>}|null  $proposal */
    private function answer(string $text, ?array $proposal): AssistantReply
    {
        return $proposal === null
            ? new AssistantReply($text)
            : new AssistantReply($text, $proposal['data'], $proposal['type']);
    }

    /** @return array{messages: list<array<string, mixed>>, tools: list<array<string, mixed>>} */
    private function browserRequest(User $user, array $state): array
    {
        $declarations = $this->tools->declarations($user);

        return [
            'messages' => OpenAiCompatibleClient::messages($state['contents'], $this->systemInstruction($user, $state['question'])),
            'tools' => $declarations ? OpenAiCompatibleClient::tools($declarations) : [],
        ];
    }

    /** @return list<array{role: string, parts: list<array<string, string>>}> */
    private function contents(array $history, string $question): array
    {
        $keep = max(0, (int) config('assistant.history_turns', 10));
        $turns = $keep === 0 ? [] : array_slice(array_values($history), -$keep);

        $contents = [];

        foreach ($turns as $turn) {
            $text = trim((string) ($turn['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $contents[] = [
                'role' => ($turn['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $text]],
            ];
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];

        return $contents;
    }

    private function systemInstruction(User $user, string $question): string
    {
        $name = $user->profile?->nameForLocale(app()->getLocale()) ?: 'not linked to an employee record';
        $policies = $this->policies->contextFor($question);

        $lines = [
            'You are the HR assistant of Christian Hope Center, a Syrian non-profit organisation.',
            'Answer in the language the question is written in: a question in Arabic gets an answer in Arabic, one in English an answer in English.',
            'Answer questions about policies only from the policy texts below, and name the policy (its title) you used.',
            "For the employee's own data (profile, contract, leave balances, leave requests, pay, files) call the tools; never answer it from memory or from earlier messages alone.",
            "Never guess. If neither the policies nor the tools hold the answer, say you don't know and suggest contacting HR.",
            'Never discuss, compare with or reveal anything about other employees, even if asked. The one exception: who is on approved leave and when, only as get_team_absences returns it (name, position, dates, leave type), never more.',
            'Keep answers short and clear.',
            'Whenever the user asks to add or create anything new (including their own leave request), first call offer_create_choice with what they want to add and wait for their choice; skip it only if they already chose to continue here.',
            'When the employee wants to take a leave and chose to continue here, help them draft the request: call get_leave_options, then ask for whatever is still missing (the leave type, the dates or for an hourly leave the day and times, the reason), one short question at a time. Read relative dates ("tomorrow", "next Sunday", "for 5 days from the 3rd") against today\'s date below and state the exact dates you understood. Then call draft_leave_request.',
            'If draft_leave_request returns errors, explain them simply and ask what to change. If it returns a draft, summarise it (type, dates, duration, reason) and tell the employee to review it and press the Confirm button below your answer.',
            'You cannot submit a leave request and must never say it was submitted, sent or approved: only the employee\'s Confirm click submits it. Without the draft_leave_request tool, tell the employee to use the My leaves page.',
            'Other records (a leave request for another employee, a news item, a new employee, a contract) are created the same way, only with the tools you are given for them: first call that record\'s options tool when there is one, ask for each missing required value one short question at a time (never invent one), then call its draft_ tool. If it returns errors, explain them and ask what to change; if it returns a draft, summarise it and ask the user to press Confirm below your answer. You can never create, save or send a record yourself and must never say one was created: only the Confirm click does that. Without a tool for a record, tell the user to use its page in the app.',
            'Today is '.now()->format('l, Y-m-d').'.',
            "The employee you are talking to is {$name}.",
        ];

        $lines[] = $policies === ''
            ? "\nNo policy documents are available yet: for any policy question say you don't know and suggest contacting HR."
            : "\nPolicy texts:\n\n".$policies;

        return implode("\n", $lines);
    }

    /** In the question's language, like the answer it stands in for. */
    private function fallback(string $question): string
    {
        return preg_match('/\p{Arabic}/u', $question)
            ? 'عذراً، لم أجد جواباً. يرجى التواصل مع قسم الموارد البشرية.'
            : "Sorry, I couldn't find an answer. Please contact HR.";
    }
}
