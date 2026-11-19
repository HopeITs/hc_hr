<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyDocument;
use Illuminate\Support\Collection;

/**
 * The documents a new volunteer must go through (track 7). HR marks which ones
 * are required (PolicyDocument::required_for_onboarding) and of which kind:
 *
 *  - policy: read and agreed;
 *  - review: made by another team (the Media guideline, by the Media Team) and
 *    only reviewed, not agreed to.
 *
 * Each kind is its own journey step. A step is done when every required
 * document of its kind has an acknowledgement; with none required it is a
 * problem (HR has to say which), never "done".
 *
 * The caller authorizes: acknowledge() only ever writes for the employee it is
 * given, and the page passes the signed-in user's own employee.
 */
class PolicyAcknowledgements
{
    /** @return Collection<int, PolicyDocument> */
    public function required(string $kind = PolicyDocument::KIND_POLICY): Collection
    {
        return PolicyDocument::query()
            ->where('required_for_onboarding', true)
            ->where('onboarding_kind', $kind)
            ->orderBy('title')
            ->get();
    }

    /**
     * @return array{required: Collection<int, PolicyDocument>, acknowledged: Collection<int, int>, pending: Collection<int, PolicyDocument>, complete: bool}
     */
    public function status(Employee $employee, string $kind = PolicyDocument::KIND_POLICY): array
    {
        $required = $this->required($kind);
        $acknowledged = PolicyAcknowledgement::query()
            ->where('employee_id', $employee->id)
            ->whereIn('policy_document_id', $required->pluck('id'))
            ->pluck('policy_document_id');

        return [
            'required' => $required,
            'acknowledged' => $acknowledged,
            'pending' => $required->reject(fn (PolicyDocument $document) => $acknowledged->contains($document->id))->values(),
            'complete' => $required->isNotEmpty() && $acknowledged->count() >= $required->count(),
        ];
    }

    /** False when the document is not required or was already acknowledged. */
    public function acknowledge(Employee $employee, PolicyDocument $document): bool
    {
        if (! $document->required_for_onboarding) {
            return false;
        }

        $created = PolicyAcknowledgement::query()->getQuery()->insertOrIgnore([
            'employee_id' => $employee->id,
            'policy_document_id' => $document->id,
            'acknowledged_at' => now(),
            'document_modified_at' => $document->source_modified_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($created > 0) {
            app(OnboardingJourneys::class)->settle($employee);
        }

        return $created > 0;
    }

    public function setRequired(PolicyDocument $document, bool $required): void
    {
        if ($document->required_for_onboarding === $required) {
            return;
        }

        $document->forceFill(['required_for_onboarding' => $required])->save();
    }

    public function setKind(PolicyDocument $document, string $kind): void
    {
        if (! in_array($kind, PolicyDocument::KINDS, true) || $document->onboarding_kind === $kind) {
            return;
        }

        $document->forceFill(['onboarding_kind' => $kind])->save();
    }
}
