<?php

namespace App\Services\Chat;

use Throwable;

/**
 * A small preview of a chat photo, for the bubble. Made from the uploaded
 * file before it is encrypted; the original itself is never re-encoded
 * (re-encoding stored images has turned photo backgrounds black before).
 */
class ChatThumbnails
{
    /** The image types shown inline (and so previewed). Anything else, SVG included, is a download. */
    public const INLINE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Longest side of a preview, in pixels. */
    private const MAX_SIDE = 480;

    /** Beyond this many pixels GD would need more memory than the 128M limit allows. */
    private const MAX_PIXELS = 24_000_000;

    /**
     * JPEG bytes of a preview, or null when one can't or shouldn't be made
     * (not an inline image type, too large, GD missing or the file broken).
     */
    public function make(string $path, string $mime): ?string
    {
        if (! in_array($mime, self::INLINE_MIMES, true) || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        try {
            $size = @getimagesize($path);

            if (! $size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
                return null;
            }

            $source = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($path),
                'image/png' => @imagecreatefrompng($path),
                'image/gif' => @imagecreatefromgif($path),
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            };

            if (! $source) {
                return null;
            }

            [$width, $height] = [$size[0], $size[1]];
            $scale = min(1, self::MAX_SIDE / max($width, $height));
            $w = max(1, (int) round($width * $scale));
            $h = max(1, (int) round($height * $scale));

            // A white backdrop, so transparent PNG/GIF areas don't turn black in JPEG.
            $preview = imagecreatetruecolor($w, $h);
            imagefill($preview, 0, 0, imagecolorallocate($preview, 255, 255, 255));
            imagecopyresampled($preview, $source, 0, 0, 0, 0, $w, $h, $width, $height);

            ob_start();
            imagejpeg($preview, null, 80);
            $bytes = (string) ob_get_clean();

            imagedestroy($source);
            imagedestroy($preview);

            return $bytes !== '' ? $bytes : null;
        } catch (Throwable) {
            return null;
        }
    }
}
