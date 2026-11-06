<?php

namespace App\Enums\News;

/** What kind of content an item is. Only an event has an event date. */
enum NewsType: string
{
    case News = 'news';
    case Announcement = 'announcement';
    case Event = 'event';

    public function label(): string
    {
        return __('news.types.'.$this->value);
    }

    public function hint(): string
    {
        return __('news.types_hint.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::News => 'file',
            self::Announcement => 'bell',
            self::Event => 'calendar',
        };
    }

    public function isEvent(): bool
    {
        return $this === self::Event;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
