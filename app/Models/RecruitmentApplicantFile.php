<?php

namespace App\Models;

use App\Enums\RecruitmentFileScanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class RecruitmentApplicantFile extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const TYPE_CV = 'cv';

    protected $guarded = ['id'];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicant::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class);
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by_user_id');
    }

    public function archiveNote(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicantNote::class, 'archive_note_id')->withTrashed();
    }

    public function cvAnalysis(): HasOne
    {
        return $this->hasOne(RecruitmentCvAnalysis::class, 'file_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('document')
            ->useDisk(config('recruitment-uploads.quarantine_disk'))
            ->singleFile();
    }

    public function isScanClean(): bool
    {
        return $this->scan_status === RecruitmentFileScanStatus::Clean;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isCv(): bool
    {
        return $this->file_type === self::TYPE_CV;
    }

    /** The bytes were removed on purpose (anonymization); the row stays as history. */
    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'archived_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }
}
