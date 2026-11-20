<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** How the chat writes dates and times: short, like Telegram. */
class ChatTime
{
    /** In the chats list: the time today, "Yesterday", the weekday this week, else the date. */
    public static function listLabel(CarbonInterface $at): string
    {
        $at = $at->copy()->locale(app()->getLocale());

        return match (true) {
            $at->isToday() => $at->format('H:i'),
            $at->isYesterday() => __('chat.ui.yesterday'),
            $at->gt(now()->subDays(6)->startOfDay()) => $at->translatedFormat('l'),
            default => $at->format('j/n/y'),
        };
    }

    /** The date chip between days in a conversation. */
    public static function dayLabel(CarbonInterface $at): string
    {
        $at = $at->copy()->locale(app()->getLocale());

        return match (true) {
            $at->isToday() => __('chat.ui.today'),
            $at->isYesterday() => __('chat.ui.yesterday'),
            $at->isSameYear(now()) => $at->translatedFormat('j F'),
            default => $at->translatedFormat('j F Y'),
        };
    }

    public static function time(CarbonInterface $at): string
    {
        return $at->format('H:i');
    }

    /**
     * "last seen …" for someone who is not online now, from users.last_seen_at
     * (RecordPresence stamps it at most once a minute).
     */
    public static function lastSeen(CarbonInterface|string|null $at): string
    {
        if ($at === null || $at === '') {
            return __('chat.ui.last_seen_long_ago');
        }

        $at = \Illuminate\Support\Carbon::parse($at)->locale(app()->getLocale());
        $minutes = (int) $at->diffInMinutes(now());

        return match (true) {
            $minutes < 1 => __('chat.ui.last_seen_just_now'),
            $minutes < 60 => trans_choice('chat.ui.last_seen_minutes', $minutes, ['count' => $minutes]),
            $at->isToday() => __('chat.ui.last_seen_today', ['time' => $at->format('H:i')]),
            $at->isYesterday() => __('chat.ui.last_seen_yesterday', ['time' => $at->format('H:i')]),
            default => __('chat.ui.last_seen_on', ['date' => $at->translatedFormat('j F')]),
        };
    }
}
