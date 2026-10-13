<?php

namespace App\Services\Recruitment\Handlers;

use App\Models\RecruitmentRequest;

/**
 * What one request type does to the organisation once it is approved.
 *
 * A handler is given the approved snapshot rather than the request's live
 * draft payload, because the draft can be edited after submission and what was
 * approved is what must be applied. It runs inside a transaction the
 * application service opens and holds, so a handler never opens its own and
 * never catches its own failures: throwing is how it refuses, and the service
 * turns that into `failed_application` with the reason recorded.
 *
 * Handlers return the events they caused rather than writing them, so the
 * service can record them in one place, in order, after the effect is known to
 * have succeeded.
 */
interface AppliesRecruitmentRequest
{
    /** The request type this handler answers for. */
    public function type(): int;

    /**
     * Carry out the effect and describe it.
     *
     * @param  array<string, mixed>  $snapshot  the approved version's payload
     * @return array<int, array{event_type: int, meta?: array<string, mixed>}>
     */
    public function apply(RecruitmentRequest $request, array $snapshot): array;
}
