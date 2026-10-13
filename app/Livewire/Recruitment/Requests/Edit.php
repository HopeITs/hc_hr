<?php

namespace App\Livewire\Recruitment\Requests;

use App\Enums\RecruitmentEventType;
use App\Enums\RecruitmentRequestMode;
use App\Models\Position;
use App\Models\RecruitmentRequest;
use App\Models\User;
use App\Services\RecruitmentEditableFields;
use App\Services\RecruitmentRequestDraftService;
use App\Services\RecruitmentScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Jantinnerezo\LivewireAlert\Facades\LivewireAlert;
use Livewire\Attributes\Locked;

/**
 * Editing a draft (T2) or a request sent back for changes (T10).
 *
 * The form is the create form: the same fields, the same narrowed pickers and
 * the same per-type shape, because an edit is the same statement made again.
 * Only the starting values and where the save goes are different, which is why
 * this extends Create rather than restating it.
 */
class Edit extends Create
{
    #[Locked]
    public int $requestId;

    public function mount(int $requestId): void
    {
        $this->requestId = $requestId;

        $request = $this->request();

        // The draft payload is what the requester last typed, so the form comes
        // back exactly as it was left rather than re-derived from the columns.
        $this->data = $this->withInitializedRequestSelects(array_merge(
            ['request_type' => (int) $request->request_type],
            (array) ($request->draft_payload_json ?? []),
        ));
    }

    /** The type is fixed once the request exists, so it cannot be swapped here. */
    public function updatedDataRequestType(): void
    {
        $this->data['request_type'] = (int) $this->request()->request_type;
    }

    public function save(RecruitmentRequestDraftService $drafts)
    {
        $this->resetValidation();
        $drafts->update(auth()->user(), $this->request(), $this->data);
        $this->toast(__('recruitment.saved'));

        return $this->redirectRoute('recruitment.requests.show', ['requestId' => $this->requestId]);
    }

    public function render()
    {
        $changeRequest = $this->latestChangeRequestContext();

        $view = parent::render();
        $view->with([
            'editing' => true,
            'changeRequest' => $changeRequest,
            'requestedFields' => collect($changeRequest['fields'] ?? [])->pluck('key')->all(),
        ]);

        return $view;
    }

    protected function authorizeScreen(): void
    {
        // Editing is authorized against the request, not against the right to
        // open a brand new one.
        if (isset($this->requestId)) {
            $this->request();
        }
    }

    /**
     * Everything the approver did not ask about.
     *
     * The service refuses these on save either way, but a form that accepts
     * typing and then rejects it teaches people to distrust the form. Shown
     * greyed and unusable, the request reads as what it is: a document with
     * two fields open in it.
     *
     * @return array<int, string>
     */
    protected function lockedFields(): array
    {
        $editable = app(RecruitmentEditableFields::class);
        $permitted = $editable->for($this->request());

        if ($permitted === RecruitmentEditableFields::ALL) {
            return [];
        }

        return array_values(array_diff(
            array_keys((array) ($this->request()->draft_payload_json ?? [])),
            $permitted,
        ));
    }

    /**
     * The latest approver ask shown above the edit form.
     *
     * @return array{comment: string|null, fields: array<int, array{key: string, label: string}>}|null
     */
    protected function latestChangeRequestContext(): ?array
    {
        $event = $this->request()->events()
            ->where('event_type', RecruitmentEventType::ChangesRequested)
            ->latest('id')
            ->first();

        if (! $event) {
            return null;
        }

        $fields = $event->meta['requested_fields'] ?? [];

        if (! is_array($fields) || $fields === []) {
            return null;
        }

        return [
            'comment' => $event->comment,
            'fields' => collect($fields)
                ->filter(fn ($field): bool => is_string($field) && $field !== '')
                ->map(fn (string $field): array => [
                    'key' => $field,
                    'label' => __('recruitment.'.$field),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * A request HR opened directly is scoped to their governorate, the same
     * boundary the direct-opening screen offered — HR does not manage the
     * posts they recruit for, so the requester's (empty) management subtree
     * would leave the position picker blank.
     *
     * @return Builder<Position>
     */
    protected function targetablePositions(User $user): Builder
    {
        if ($this->isDirectOpen()) {
            return app(RecruitmentScopeResolver::class)->scopeGovernoratePositions(Position::query(), $user);
        }

        return parent::targetablePositions($user);
    }

    /** @return Builder<Position> */
    protected function proposableManagerPositions(User $user): Builder
    {
        if ($this->isDirectOpen()) {
            return app(RecruitmentScopeResolver::class)->scopeGovernoratePositions(Position::query(), $user);
        }

        return parent::proposableManagerPositions($user);
    }

    private function isDirectOpen(): bool
    {
        return (int) $this->request()->mode === RecruitmentRequestMode::DirectHr;
    }

    /** Resolved through the visibility boundary on every request, never cached. */
    private function request(): RecruitmentRequest
    {
        $request = RecruitmentRequest::query()->visibleTo(auth()->user())->findOrFail($this->requestId);
        Gate::authorize('update', $request);

        return $request;
    }

    /**
     * Say what happened, the way the rest of the application says it.
     *
     * A toast rather than a coloured panel: a red or green block across a form
     * reads as part of the form rather than as a remark about it.
     */
    private function toast(string $message, bool $success = true): void
    {
        $alert = LivewireAlert::title($message);

        ($success ? $alert->success() : $alert->error())
            ->toast()
            ->position('top-start')
            ->show();
    }
}
