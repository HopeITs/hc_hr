<?php

namespace App\Services;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationFlag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecruitmentApplicationFlagService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function create(User $actor, RecruitmentApplication $application, mixed $type, mixed $category, mixed $reason, mixed $note = null): RecruitmentApplicationFlag
    {
        $type = $this->value($type, RecruitmentApplicationFlag::types(), 'type');
        $reasons = RecruitmentApplicationFlag::reasons();
        $category = $this->value($category, array_keys($reasons), 'category');
        $reason = $this->value($reason, array_keys($reasons[$category]), 'reason');
        $note = is_string($note) ? trim($note) : '';

        if (mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['flagNote' => __('recruitment-applicant.flags.note_length')]);
        }

        return DB::transaction(function () use ($actor, $application, $type, $category, $reason, $note): RecruitmentApplicationFlag {
            $flag = RecruitmentApplicationFlag::query()->create([
                'application_id' => $application->getKey(),
                'type' => $type,
                'category' => $category,
                'reason' => $reason,
                'note' => $note !== '' ? $note : null,
                'created_by_user_id' => $actor->getKey(),
            ]);

            $this->activity->log('recruitment_flag.created', subject: $application, causer: $actor, properties: [
                'flag_id' => $flag->getKey(), 'type' => $type, 'category' => $category, 'reason' => $reason,
            ], logName: 'recruitment-flags');

            return $flag;
        });
    }

    public function discard(User $actor, RecruitmentApplicationFlag $flag): void
    {
        DB::transaction(function () use ($actor, $flag): void {
            $flag->delete();
            $this->activity->log('recruitment_flag.discarded', subject: $flag->application, causer: $actor, properties: [
                'flag_id' => $flag->getKey(), 'type' => $flag->type, 'category' => $flag->category, 'reason' => $flag->reason,
            ], logName: 'recruitment-flags');
        });
    }

    private function value(mixed $value, array $allowed, string $field): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages(['flag'.ucfirst($field) => __('recruitment-applicant.flags.invalid')]);
        }

        return $value;
    }
}
