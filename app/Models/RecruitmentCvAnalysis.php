<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The automatic reading of one CV: facts only, never the text. A reading
 * aid for the reviewer; nothing ranks or filters by it.
 */
class RecruitmentCvAnalysis extends Model
{
    public const COMPLETED = 'completed';

    /** Nothing readable came out, even after OCR (an image-only file, or OCR is off). */
    public const NO_TEXT = 'no_text';

    /** A tool this file needs (pdftotext, tesseract, antiword) is not installed. */
    public const TOOL_MISSING = 'tool_missing';

    public const UNSUPPORTED = 'unsupported';

    public const FAILED = 'failed';

    protected $table = 'recruitment_cv_analyses';

    protected $guarded = ['id'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicantFile::class, 'file_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplication::class, 'application_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    protected function casts(): array
    {
        return [
            'facts_json' => 'array',
            'comparison_json' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }
}
