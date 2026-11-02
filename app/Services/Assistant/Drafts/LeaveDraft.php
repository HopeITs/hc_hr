<?php

namespace App\Services\Assistant\Drafts;

use App\Models\User;
use App\Services\Assistant\LeaveRequestSubmitter;

/**
 * The employee's own leave request. Its tools (get_leave_options,
 * draft_leave_request) are among EmployeeTools' own; this only shows and
 * submits what they drafted.
 */
class LeaveDraft implements RecordDraft
{
    public function type(): string
    {
        return 'leave';
    }

    public function declarations(User $user): array
    {
        return [];
    }

    public function call(User $user, string $tool, array $args): array
    {
        return ['error' => "The tool {$tool} is not available."];
    }

    public function card(array $proposal): array
    {
        return [
            'title' => __('assistant.leave.title'),
            'icon' => 'calendar',
            'rows' => array_filter([
                __('assistant.leave.fields.type') => $proposal['leave_type'] ?? null,
                __('assistant.leave.fields.from') => $proposal['start'] ?? null,
                __('assistant.leave.fields.to') => $proposal['end'] ?? null,
                __('assistant.leave.fields.duration') => $proposal['duration'] ?? null,
                __('assistant.leave.fields.reason') => $proposal['reason'] ?? null,
                __('assistant.leave.fields.alternative') => $proposal['alternative_employee'] ?? null,
            ], fn ($value) => filled($value)),
        ];
    }

    public function submit(User $user, array $proposal, string $token): array
    {
        // Resolved here, so a test can stand in for it.
        app(LeaveRequestSubmitter::class)->submit($user, $proposal, $token);

        return [
            'message' => __('assistant.leave.submitted'),
            'url' => route('leaves.index'),
            'link' => __('assistant.leave.open-my-leaves'),
        ];
    }
}
