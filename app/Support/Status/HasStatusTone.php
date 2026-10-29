<?php

namespace App\Support\Status;

/**
 * Every status enum implements this, so one component (<x-status :status="…">)
 * renders every status in the app the same way: icon + words + fill shape.
 */
interface HasStatusTone
{
    public function tone(): StatusTone;

    /** An <x-ic> icon name. */
    public function icon(): string;

    /** The translated words shown in the chip. */
    public function label(): string;
}
