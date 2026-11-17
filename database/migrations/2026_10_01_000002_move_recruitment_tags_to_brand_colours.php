<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The tag palette used Tailwind hues (red, amber, green, blue, purple). The
 * visual identity allows only the brand red and the brand greys, so the
 * palette in config/recruitment.php is now brand tones; this moves every
 * stored tag onto the tone that replaced its old hue.
 */
return new class extends Migration
{
    private const MAP = [
        'red' => 'primary',
        'amber' => 'outline',
        'green' => 'dark',
        'blue' => 'medium',
        'purple' => 'soft',
    ];

    public function up(): void
    {
        foreach (self::MAP as $from => $to) {
            DB::table('recruitment_tags')->where('color', $from)->update(['color' => $to]);
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $from => $to) {
            DB::table('recruitment_tags')->where('color', $to)->update(['color' => $from]);
        }
    }
};
