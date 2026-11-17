<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RecruitmentWorkflowDefinition extends Model
{
    protected $guarded = ['id'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecruitmentWorkflowDefinitionVersion::class, 'definition_id');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(RecruitmentWorkflowDefinitionVersion::class, 'definition_id')
            ->ofMany('version_no', 'max');
    }

    protected function casts(): array
    {
        return [
            'request_type' => 'integer',
            'is_active' => 'boolean'];
    }
}
