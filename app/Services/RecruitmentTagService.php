<?php

namespace App\Services;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationTag;
use App\Models\RecruitmentTag;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way tags are written, applied or removed.
 *
 * Permission is the caller's job (the policy and the Livewire permission
 * map). This service keeps the record honest whoever calls it: who applied a
 * tag and when come from here, never from input; a tag that is deleted
 * cannot be applied; and every change leaves an audit entry.
 *
 * Scope is enforced here for bulk operations, because their ids arrive from
 * the browser as a list: each is re-resolved through visibleTo(), and one
 * the actor cannot see is skipped, not reported, so the response reveals
 * nothing about records outside their scope.
 *
 * Nothing here reads or writes an application's status.
 */
class RecruitmentTagService
{
    public const AUDIT_LOG = 'recruitment-tags';

    public function __construct(private readonly ActivityLogger $activity) {}

    // --------------------------------------------
    // The tag list
    // --------------------------------------------

    public function create(User $actor, mixed $name, mixed $color = null): RecruitmentTag
    {
        $name = $this->normalizeName($name);
        $color = $this->normalizeColor($color);

        return DB::transaction(function () use ($actor, $name, $color): RecruitmentTag {
            $existing = $this->findByName($name, withTrashed: true);

            if ($existing?->trashed()) {
                throw ValidationException::withMessages(['tagName' => __('recruitment-applicant.tags.name_deleted', ['name' => $existing->name])]);
            }

            if ($existing !== null) {
                throw ValidationException::withMessages(['tagName' => __('recruitment-applicant.tags.name_taken')]);
            }

            try {
                $tag = RecruitmentTag::query()->forceCreate([
                    'name' => $name,
                    'color' => $color,
                    'scope_key' => $this->scopeKey(),
                    'created_by_user_id' => $actor->getKey(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['tagName' => __('recruitment-applicant.tags.name_taken')]);
            }

            $this->log('recruitment_tag.created', $tag, $actor, ['name' => $name, 'color' => $color]);

            return $tag;
        });
    }

    /**
     * Renaming relabels every application carrying the tag at once — which is
     * the point, and why the old name goes into the audit entry.
     */
    public function rename(User $actor, RecruitmentTag $tag, mixed $name, mixed $color = null): RecruitmentTag
    {
        $name = $this->normalizeName($name);
        $color = $color === null ? $tag->color : $this->normalizeColor($color);

        return DB::transaction(function () use ($actor, $tag, $name, $color): RecruitmentTag {
            $locked = $this->lockTag($tag);

            $clash = $this->findByName($name, withTrashed: true);
            if ($clash !== null && ! $clash->is($locked)) {
                throw ValidationException::withMessages(['tagName' => $clash->trashed()
                    ? __('recruitment-applicant.tags.name_deleted', ['name' => $clash->name])
                    : __('recruitment-applicant.tags.name_taken')]);
            }

            $before = ['name' => $locked->name, 'color' => $locked->color];

            try {
                $locked->forceFill(['name' => $name, 'color' => $color, 'updated_by_user_id' => $actor->getKey()])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['tagName' => __('recruitment-applicant.tags.name_taken')]);
            }

            $this->log('recruitment_tag.renamed', $locked, $actor, ['before' => $before, 'after' => ['name' => $name, 'color' => $color]]);

            return $locked;
        });
    }

    /** Soft delete: its assignments stay, hidden with it, until it is restored. */
    public function delete(User $actor, RecruitmentTag $tag): void
    {
        DB::transaction(function () use ($actor, $tag): void {
            $locked = $this->lockTag($tag);

            $locked->forceFill(['deleted_by_user_id' => $actor->getKey()])->save();
            $locked->delete();

            $this->log('recruitment_tag.deleted', $locked, $actor, [
                'name' => $locked->name,
                'assignments' => $locked->assignments()->count(),
            ]);
        });
    }

    public function restore(User $actor, RecruitmentTag $tag): RecruitmentTag
    {
        return DB::transaction(function () use ($actor, $tag): RecruitmentTag {
            $locked = RecruitmentTag::withTrashed()->whereKey($tag->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->trashed()) {
                throw ValidationException::withMessages(['tagName' => __('recruitment-applicant.tags.not_deleted')]);
            }

            $locked->restore();
            $locked->forceFill(['deleted_by_user_id' => null, 'updated_by_user_id' => $actor->getKey()])->save();

            $this->log('recruitment_tag.restored', $locked, $actor, ['name' => $locked->name]);

            return $locked;
        });
    }

    // --------------------------------------------
    // Applying tags
    // --------------------------------------------

    /**
     * The caller has resolved $application through the scope. Applying a tag
     * that is already there is a no-op: the original author and date stand.
     *
     * @return bool whether a new assignment was written
     */
    public function attach(User $actor, RecruitmentApplication $application, mixed $tagId): bool
    {
        $tag = $this->resolveActiveTag($tagId);

        return DB::transaction(function () use ($actor, $application, $tag): bool {
            $this->lockActiveTag($tag);

            if (! $this->insertAssignments($actor, $tag, [(int) $application->getKey()])) {
                return false;
            }

            $this->log('recruitment_tag.attached', $application, $actor, [
                'tag_id' => $tag->getKey(),
                'tag_name' => $tag->name,
                'application_id' => $application->getKey(),
            ]);

            return true;
        });
    }

    public function detach(User $actor, RecruitmentApplication $application, mixed $tagId): bool
    {
        $tag = $this->resolveActiveTag($tagId);

        return DB::transaction(function () use ($actor, $application, $tag): bool {
            $assignment = RecruitmentApplicationTag::query()
                ->where('application_id', $application->getKey())
                ->where('tag_id', $tag->getKey())
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                return false;
            }

            $assignment->delete();

            // Who put it there and when would otherwise go with the row.
            $this->log('recruitment_tag.detached', $application, $actor, [
                'tag_id' => $tag->getKey(),
                'tag_name' => $tag->name,
                'application_id' => $application->getKey(),
                'applied_by_user_id' => $assignment->created_by_user_id,
                'applied_at' => $assignment->created_at?->toIso8601String(),
            ]);

            return true;
        });
    }

    /**
     * @param  iterable<mixed>  $applicationIds  from the browser; re-scoped here
     * @return array{changed: int, unchanged: int}
     */
    public function bulkAttach(User $actor, iterable $applicationIds, mixed $tagId): array
    {
        $tag = $this->resolveActiveTag($tagId);
        $ids = $this->visibleIds($actor, $applicationIds);

        return DB::transaction(function () use ($actor, $tag, $ids): array {
            $this->lockActiveTag($tag);

            $already = RecruitmentApplicationTag::query()
                ->where('tag_id', $tag->getKey())
                ->whereIn('application_id', $ids)
                ->pluck('application_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $new = array_values(array_diff($ids, $already));

            $this->insertAssignments($actor, $tag, $new);

            $this->log('recruitment_tag.bulk_attached', $tag, $actor, [
                'tag_id' => $tag->getKey(),
                'tag_name' => $tag->name,
                'application_ids' => $new,
                'count' => count($new),
            ]);

            return ['changed' => count($new), 'unchanged' => count($ids) - count($new)];
        });
    }

    /**
     * @param  iterable<mixed>  $applicationIds  from the browser; re-scoped here
     * @return array{changed: int, unchanged: int}
     */
    public function bulkDetach(User $actor, iterable $applicationIds, mixed $tagId): array
    {
        $tag = $this->resolveActiveTag($tagId);
        $ids = $this->visibleIds($actor, $applicationIds);

        return DB::transaction(function () use ($actor, $tag, $ids): array {
            $assignments = RecruitmentApplicationTag::query()
                ->where('tag_id', $tag->getKey())
                ->whereIn('application_id', $ids)
                ->lockForUpdate()
                ->get(['id', 'application_id', 'created_by_user_id', 'created_at']);

            RecruitmentApplicationTag::query()->whereKey($assignments->modelKeys())->delete();

            $this->log('recruitment_tag.bulk_detached', $tag, $actor, [
                'tag_id' => $tag->getKey(),
                'tag_name' => $tag->name,
                'removed' => $assignments->map(fn (RecruitmentApplicationTag $assignment): array => [
                    'application_id' => (int) $assignment->application_id,
                    'applied_by_user_id' => (int) $assignment->created_by_user_id,
                    'applied_at' => $assignment->created_at?->toIso8601String(),
                ])->values()->all(),
                'count' => $assignments->count(),
            ]);

            return ['changed' => $assignments->count(), 'unchanged' => count($ids) - $assignments->count()];
        });
    }

    // --------------------------------------------
    // Input
    // --------------------------------------------

    /**
     * Letters in any script, digits, spaces and a few separators. Markup and
     * control characters are refused rather than stripped, so what the user
     * typed is either stored as typed or not at all.
     */
    public function normalizeName(mixed $name): string
    {
        if (! is_string($name)) {
            throw ValidationException::withMessages(['tagName' => __('recruitment-applicant.tags.name_required')]);
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $min = (int) config('recruitment.tags.min_length', 2);
        $max = (int) config('recruitment.tags.max_length', 40);

        if ($name === '') {
            throw ValidationException::withMessages(['tagName' => __('recruitment-applicant.tags.name_required')]);
        }

        if (mb_strlen($name) < $min || mb_strlen($name) > $max) {
            throw ValidationException::withMessages(['tagName' => __('recruitment-applicant.tags.name_length', ['min' => $min, 'max' => $max])]);
        }

        if (! preg_match('/^[\p{L}\p{M}\p{N} _\-.&\/+#()]+$/u', $name)) {
            throw ValidationException::withMessages(['tagName' => __('recruitment-applicant.tags.name_invalid')]);
        }

        return $name;
    }

    public function normalizeColor(mixed $color): string
    {
        $palette = array_keys(config('recruitment.tags.palette', []));

        if ($color === null || $color === '') {
            return 'neutral';
        }

        if (! is_string($color) || ! in_array($color, $palette, true)) {
            throw ValidationException::withMessages(['tagColor' => __('recruitment-applicant.tags.color_invalid')]);
        }

        return $color;
    }

    public function bulkLimit(): int
    {
        return max(1, (int) config('recruitment.tags.bulk_limit', 500));
    }

    // --------------------------------------------
    // Internals
    // --------------------------------------------

    /**
     * A tag id that is not a live tag — never existed, deleted, or not an id
     * at all — is a validation error on the form, not a 500.
     */
    private function resolveActiveTag(mixed $tagId): RecruitmentTag
    {
        $id = is_scalar($tagId) ? filter_var($tagId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $tag = $id === false ? null : RecruitmentTag::query()->find($id);

        if ($tag === null) {
            throw ValidationException::withMessages(['tagId' => __('recruitment-applicant.tags.unknown_tag')]);
        }

        return $tag;
    }

    /** Re-read under lock, so a tag deleted a moment ago is not applied. */
    private function lockActiveTag(RecruitmentTag $tag): void
    {
        if (! RecruitmentTag::query()->whereKey($tag->getKey())->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['tagId' => __('recruitment-applicant.tags.unknown_tag')]);
        }
    }

    private function lockTag(RecruitmentTag $tag): RecruitmentTag
    {
        $locked = RecruitmentTag::query()->whereKey($tag->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw ValidationException::withMessages(['tagName' => __('recruitment-applicant.tags.unknown_tag')]);
        }

        return $locked;
    }

    /**
     * Case-insensitive, whatever the database collation — SQLite compares
     * case-sensitively and MySQL does not, and the answer must not depend on
     * which one is running.
     */
    private function findByName(string $name, bool $withTrashed = false): ?RecruitmentTag
    {
        return RecruitmentTag::query()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->where('scope_key', $this->scopeKey())
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    /**
     * Author and date per assignment, written here and nowhere else.
     *
     * @param  array<int, int>  $applicationIds
     */
    private function insertAssignments(User $actor, RecruitmentTag $tag, array $applicationIds): int
    {
        $now = now();
        $inserted = 0;

        foreach (array_chunk($applicationIds, 200) as $chunk) {
            $inserted += RecruitmentApplicationTag::query()->insertOrIgnore(array_map(fn (int $id): array => [
                'application_id' => $id,
                'tag_id' => $tag->getKey(),
                'created_by_user_id' => $actor->getKey(),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return $inserted;
    }

    /**
     * @param  iterable<mixed>  $applicationIds
     * @return array<int, int>
     */
    private function visibleIds(User $actor, iterable $applicationIds): array
    {
        $ids = [];
        foreach ($applicationIds as $id) {
            if (is_scalar($id) && ($int = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) !== false) {
                $ids[$int] = $int;
            }
        }

        if ($ids === []) {
            throw ValidationException::withMessages(['applicationIds' => __('recruitment-applicant.tags.bulk_empty')]);
        }

        if (count($ids) > $this->bulkLimit()) {
            throw ValidationException::withMessages(['applicationIds' => __('recruitment-applicant.tags.bulk_too_many', ['max' => $this->bulkLimit()])]);
        }

        $visible = [];
        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            array_push($visible, ...RecruitmentApplication::query()
                ->visibleTo($actor)
                ->whereKey($chunk)
                ->pluck('recruitment_applications.id')
                ->map(fn ($id): int => (int) $id)
                ->all());
        }

        if ($visible === []) {
            throw ValidationException::withMessages(['applicationIds' => __('recruitment-applicant.tags.bulk_empty')]);
        }

        return $visible;
    }

    private function scopeKey(): string
    {
        return (string) config('recruitment.tags.scope_key', 'organization');
    }

    private function log(string $event, $subject, User $actor, array $properties): void
    {
        $this->activity->log($event, subject: $subject, causer: $actor, properties: $properties + ['role' => $actor->myRole()], logName: self::AUDIT_LOG);
    }
}
