<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailListMember extends Model
{
    protected $fillable = ['mail_list_id', 'email'];

    public function mailList(): BelongsTo
    {
        return $this->belongsTo(MailList::class);
    }
}
