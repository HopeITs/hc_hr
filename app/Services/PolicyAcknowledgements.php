<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyDocument;
use App\Models\User;
use App\Notifications\PolicyUpdated;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

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
 * An agreement is to one version (document_modified_at). When a policy is
 * replaced and its new text is in, an older agreement no longer counts: the
 * document is pending again for that employee, who is told (PolicyUpdated).
 *
 * Only a document with text counts (PolicyDocument::hasText): a file that was
 * never read cannot be required nor agreed to, so nobody agrees to an empty
 * page. A replaced file that fails to read keeps its earlier text, and that
 * version stays required.
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
            ->readable()
            ->orderBy('title')
            ->get();
    }

    /**
     * @return array{required: Collection<int, PolicyDocument>, acknowledged: Collection<int, int>, pending: Collection<int, PolicyDocument>, complete: bool}
     */
    public function status(Employee $employee, string $kind = PolicyDocument::KIND_POLICY): array
    {
        $required = $this->required($kind);
        $acknowledged = $this->current($employee, $required)->keys();

        return [
            'required' => $required,
            'acknowledged' => $acknowledged,
            'pending' => $required->reject(fn (PolicyDocument $document) => $acknowledged->contains($document->id))->values(),
            'complete' => $required->isNotEmpty() && $acknowledged->count() >= $required->count(),
        ];
    }

    /** False when the document is not required, has no text, or was already acknowledged. */
    public function acknowledge(Employee $employee, PolicyDocument $document): bool
    {
        if (! $document->required_for_onboarding || ! $document->hasText()) {
            return false;
        }

        if ($this->current($employee, collect([$document]))->isNotEmpty()) {
            return false;
        }

        (PolicyAcknowledgement::query()->where('employee_id', $employee->id)->where('policy_document_id', $document->id)->first() ?? new PolicyAcknowledgement)
            ->forceFill([
                'employee_id' => $employee->id,
                'policy_document_id' => $document->id,
                'acknowledged_at' => now(),
                'document_modified_at' => $document->source_modified_at,
            ])
            ->save();

        app(OnboardingJourneys::class)->settle($employee);

        return true;
    }

    /**
     * Where the employee stands with one policy: 'agreed' (this version),
     * 'outdated' (an earlier version: a new one waits), or null (never agreed).
     */
    public function standing(Employee $employee, PolicyDocument $document): ?string
    {
        $ack = PolicyAcknowledgement::query()
            ->where('employee_id', $employee->id)
            ->where('policy_document_id', $document->id)
            ->first();

        if ($ack === null) {
            return null;
        }

        return $this->isCurrent($ack, $document) ? 'agreed' : 'outdated';
    }

    /**
     * The ids of the policies with a new version this employee agreed to an
     * earlier one of: the Policies page marks them "updated".
     *
     * @return Collection<int, int>
     */
    public function outdatedFor(Employee $employee): Collection
    {
        return PolicyAcknowledgement::query()
            ->join('policy_documents', 'policy_documents.id', '=', 'policy_acknowledgements.policy_document_id')
            ->where('policy_acknowledgements.employee_id', $employee->id)
            ->where('policy_documents.required_for_onboarding', true)
            ->whereNotNull('policy_documents.source_modified_at')
            ->where(fn ($q) => $q->whereNull('policy_acknowledgements.document_modified_at')
                ->orWhereColumn('policy_acknowledgements.document_modified_at', '<', 'policy_documents.source_modified_at'))
            ->pluck('policy_documents.id');
    }

    /** Tells everyone who agreed to an earlier version of a required policy. */
    public function notifyNewVersion(PolicyDocument $document): void
    {
        if (! $document->required_for_onboarding) {
            return;
        }

        $employeeIds = PolicyAcknowledgement::query()
            ->where('policy_document_id', $document->id)
            ->where(fn ($q) => $q->whereNull('document_modified_at')->orWhere('document_modified_at', '<', $document->source_modified_at))
            ->pluck('employee_id');

        $users = User::query()->whereIn('employee_id', $employeeIds)->get();

        if ($users->isNotEmpty()) {
            Notification::send($users, new PolicyUpdated($document));
        }
    }

    /** False when a document with no text is to be made required: it is left as it is. */
    public function setRequired(PolicyDocument $document, bool $required): bool
    {
        if ($required && ! $document->hasText()) {
            return false;
        }

        if ($document->required_for_onboarding !== $required) {
            $document->forceFill(['required_for_onboarding' => $required])->save();
        }

        return true;
    }

    public function setKind(PolicyDocument $document, string $kind): void
    {
        if (! in_array($kind, PolicyDocument::KINDS, true) || $document->onboarding_kind === $kind) {
            return;
        }

        $document->forceFill(['onboarding_kind' => $kind])->save();
    }

    /**
     * The employee's agreements to the given documents that are to their
     * current version, keyed by document id.
     *
     * @param  Collection<int, PolicyDocument>  $documents
     * @return Collection<int, PolicyAcknowledgement>
     */
    private function current(Employee $employee, Collection $documents): Collection
    {
        $byId = $documents->keyBy('id');

        return PolicyAcknowledgement::query()
            ->where('employee_id', $employee->id)
            ->whereIn('policy_document_id', $byId->keys())
            ->get()
            ->filter(fn (PolicyAcknowledgement $ack) => $this->isCurrent($ack, $byId[$ack->policy_document_id]))
            ->keyBy('policy_document_id');
    }

    private function isCurrent(PolicyAcknowledgement $ack, PolicyDocument $document): bool
    {
        if ($document->source_modified_at === null) {
            return true;
        }

        return $ack->document_modified_at !== null
            && $ack->document_modified_at->greaterThanOrEqualTo($document->source_modified_at);
    }
}
