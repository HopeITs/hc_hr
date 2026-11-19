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
}
