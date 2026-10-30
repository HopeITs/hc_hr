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
        private GeminiClient $gemini,
        private ?EmployeeTools $tools = null,
        private ?PolicyLibrary $policies = null,
    ) {
        $this->tools ??= new EmployeeTools;
        $this->policies ??= new PolicyLibrary;
    }

    /** Switched on and configured. */
    public function isAvailable(): bool
    {
        return config('assistant.enabled') && $this->gemini->isConfigured();
    }

    /**
     * @param  list<array{role: 'user'|'assistant', text: string}>  $history  earlier turns, oldest first
     *
     * @throws GeminiException
     */
    public function reply(User $user, array $history, string $question): string
    {
        $system = $this->systemInstruction($user, $question);
        $declarations = $this->tools->declarations($user);
        $contents = $this->contents($history, $question);

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            // Tools stay declared on every round: Gemini rejects function-call
            // turns in the contents of a request that declares none.
            $content = $this->gemini->generate($contents, $system, $declarations);

            $calls = GeminiClient::functionCalls($content);

            if ($calls === [] || $round === self::MAX_TOOL_ROUNDS) {
                return GeminiClient::text($content) ?: $this->fallback($question);
            }

            // Sent back unchanged: newer models sign their function-call turns.
            $contents[] = $content;
            $contents[] = [
                'role' => 'user',
                'parts' => array_map(fn (array $call) => ['functionResponse' => [
                    'name' => $call['name'],
                    'response' => $this->tools->call($user, $call['name']),
                ]], $calls),
            ];
        }

        return $this->fallback($question);
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
            'Never discuss, compare with or reveal anything about other employees, even if asked.',
            'Keep answers short and clear.',
            'Today is '.now()->format('Y-m-d').'.',
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
