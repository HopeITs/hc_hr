<?php

namespace App\Support\Status;

/**
 * The five status shapes of the Hope Center identity. A status is told apart
 * by icon + words + this fill shape, never by colour (DESIGN.md, "Status").
 * Rendered by <x-status>; the CSS lives in resources/css/components.css.
 */
enum StatusTone: string
{
    /** In progress: solid ink fill, on-ink text. */
    case Work = 'work';

    /** Done: quiet surface fill. */
    case Done = 'done';

    /** Needs you: ink outline. */
    case Attn = 'attn';

    /** Stopped: dashed secondary outline. */
    case Stop = 'stop';

    /** Waiting: card fill, line outline. */
    case Wait = 'wait';

    /** The icon a status of this tone shows when it names none of its own. */
    public function defaultIcon(): string
    {
        return match ($this) {
            self::Work => 'play',
            self::Done => 'check',
            self::Attn => 'alert',
            self::Stop => 'ban',
            self::Wait => 'clock',
        };
    }
}
