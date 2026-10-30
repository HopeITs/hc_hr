<?php

namespace App\Services\Assistant;

/**
 * One answer of the assistant, and the leave request it drafted, if any.
 *
 * A draft is never submitted by the model: the page shows it with a confirm
 * button, and only the employee's click sends it to LeaveRequestSubmitter.
 */
final readonly class AssistantReply
{
    /**
     * @param  array{
     *     leave_balance_id: int,
     *     leave_type: string,
     *     type: 'day'|'hour',
     *     start: string,
     *     end: string,
     *     duration: string,
     *     reason: ?string,
     *     alternative_employee_id: ?int,
     *     alternative_employee: ?string,
     * }|null  $leaveProposal  start/end are Y-m-d for days, Y-m-d H:i for hours
     */
    public function __construct(
        public string $text,
        public ?array $leaveProposal = null,
    ) {}
}
