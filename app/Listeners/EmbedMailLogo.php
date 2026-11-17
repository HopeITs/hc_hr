<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * Embeds the logo PNG in every email whose layout asks for it.
 *
 * The mail header refers to the logo as `cid:{NAME}`, and Symfony swaps that
 * reference for the attached part's Content-ID when the message is built. An
 * embedded image shows in Outlook and Gmail straight away, where one loaded
 * from a URL waits behind a "download pictures" prompt.
 */
class EmbedMailLogo
{
    public const NAME = 'hope-center-logo.png';

    public function handle(MessageSending $event): void
    {
        $email = $event->message;
        $html = $email->getHtmlBody();

        if (! is_string($html) || ! str_contains($html, 'cid:'.self::NAME)) {
            return;
        }

        foreach ($email->getAttachments() as $part) {
            if ($part->getName() === self::NAME) {
                return;
            }
        }

        $email->embedFromPath(self::path(), self::NAME, 'image/png');
    }

    public static function path(): string
    {
        return resource_path('images/mail/logo.png');
    }
}
