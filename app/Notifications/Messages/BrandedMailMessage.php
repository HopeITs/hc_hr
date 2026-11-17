<?php

namespace App\Notifications\Messages;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * A mail notification with the extra pieces the branded template can show:
 * the inbox preview line, a red-edged security note and a one-time code.
 *
 * Each one is plain view data for resources/views/vendor/notifications/email.blade.php,
 * so a notification that needs none of them can keep using MailMessage.
 */
class BrandedMailMessage extends MailMessage
{
    /**
     * The inbox preview line. Without one, clients preview the first body text.
     */
    public function preheader(string $text): static
    {
        $this->viewData['preheader'] = $text;

        return $this;
    }

    /**
     * A security warning, shown below the action in a red-edged note.
     */
    public function notice(string $text): static
    {
        $this->viewData['notice'] = $text;

        return $this;
    }

    /**
     * A one-time code, shown large in its own box with its expiry under it.
     *
     * Also sets a preheader, unless one is set already: the preview line must
     * never be left to fall through to the body, where the code is.
     */
    public function code(string $code, int $minutes): static
    {
        $this->viewData['code'] = $code;
        $this->viewData['codeMinutes'] = $minutes;
        $this->viewData['preheader'] ??= trans_choice('mail.code.preheader', $minutes);

        return $this;
    }
}
