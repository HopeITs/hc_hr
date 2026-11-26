<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One numbered part of the minutes (أولاً، ثانياً…): a title and what the secretary wrote under it. */
class BoardMeetingSection extends Model
{
    protected $fillable = ['board_meeting_id', 'title', 'body', 'sort'];
}
