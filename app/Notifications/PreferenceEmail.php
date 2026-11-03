<?php

namespace App\Notifications;

use App\Notifications\Contracts\BypassesPreferences;
use App\Services\Notifications\WebPushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email copy of one or several in-app notifications, sent by the outbox
 * (OutboxDispatcher) once the person's preferences said email, and when.
 * It bypasses the preference listener: the decision was already made.
 *
 * Each payload is {title, body, url} as the notification stored it, so title
 * and body may be {en, ar} or plain strings. The sender renders the mail in
 * the recipient's locale (OutboxDispatcher sets it), read here through
 * app()->getLocale().
 */
class PreferenceEmail extends Notification implements BypassesPreferences
{
    use Queueable;

    /**
     * @param  array<int, array{title?: mixed, body?: mixed, url?: ?string}>  $payloads
     */
    public function __construct(public array $payloads, public bool $test = false) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = app()->getLocale();
        $count = count($this->payloads);

        if ($count === 1) {
            $payload = $this->payloads[0];
            $title = WebPushSender::text($payload['title'] ?? null, $locale) ?: __('pwa.app-name');

            return (new MailMessage)
                ->subject($title)
                ->greeting($title)
                ->line(WebPushSender::text($payload['body'] ?? null, $locale))
                ->action(
                    __('notifications-delivery.email.open'),
                    WebPushSender::safeUrl($payload['url'] ?? null) ?? route('notifications.index'),
                )
                ->salutation(__($this->test ? 'notifications-delivery.email.test_footer' : 'notifications-delivery.email.footer'));
        }

        $message = (new MailMessage)
            ->subject(__('notifications-delivery.summary.title', ['count' => $count]))
            ->greeting(__('notifications-delivery.summary.title', ['count' => $count]));

        foreach ($this->payloads as $payload) {
            $title = WebPushSender::text($payload['title'] ?? null, $locale);
            $body = WebPushSender::text($payload['body'] ?? null, $locale);
            $message->line($body === '' ? $title : "{$title}: {$body}");
        }

        return $message
            ->action(__('notifications-delivery.email.open_all'), route('notifications.index'))
            ->salutation(__('notifications-delivery.email.footer'));
    }
}
