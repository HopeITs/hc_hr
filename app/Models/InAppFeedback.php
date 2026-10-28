<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note sent from the in-app feedback form (D3, DEC-08).
 *
 * Holds the typed text and exactly the context the form discloses: the page's
 * route name, the language and the active role, all derived on the server at
 * submit time. Read by the IT team only (User::canReadFeedback()) and deleted
 * after one year by `model:prune` (routes/console.php).
 *
 * Nothing is mass assignable: InAppFeedbackRecorder sets each field itself, so
 * nothing a request carries can add a column.
 */
class InAppFeedback extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $table = 'in_app_feedback';

    protected $guarded = ['*'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** DEC-08: deleted after one year. */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('feedback.retention_days', 365)));
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
