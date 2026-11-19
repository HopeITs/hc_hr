<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyDocument;
use Illuminate\Support\Collection;

/**
 * The policies a new volunteer must read and agree to (track 7). HR marks the
 * required documents (PolicyDocument::required_for_onboarding); the volunteer
 * agrees to each from their own account. The journey's step is done when every
 * required document has an acknowledgement; with no required document it is a
 * problem (HR has to say which), never "done".
 *
 * The caller authorizes: acknowledge() only ever writes for the employee it is
 * given, and the page passes the signed-in user's own employee.
 */
class PolicyAcknowledgements
{
    /** @return Collection<int, PolicyDocument> */
    public function required(): Collection
    {
        return PolicyDocument::query()->where('required_for_onboarding', true)->orderBy('title')->get();
    }

    /**
     * @return array{required: Collection<int, PolicyDocument>, acknowledged: Collection<int, int>, pending: Collection<int, PolicyDocument>, complete: bool}
     */
    public function status(Employee $employee): array
    {
        $required = $this->required();
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
}
