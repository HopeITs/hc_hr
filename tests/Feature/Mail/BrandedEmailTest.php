<?php

namespace Tests\Feature\Mail;

use App\Listeners\EmbedMailLogo;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ContractsExpiringAutoRenewWarning;
use App\Notifications\Messages\BrandedMailMessage;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;
use Tests\TestCase;

class BrandedEmailTest extends TestCase
{
    private const CODE = '482913';

    public function test_the_logo_travels_inside_the_email_and_the_html_points_at_it(): void
    {
        $email = $this->send(new ResetPassword('token'));

        $logo = collect($this->parts($email->getBody()))
            ->first(fn (AbstractPart $part) => $part instanceof DataPart && $part->getFilename() === EmbedMailLogo::NAME);

        $this->assertNotNull($logo, 'The logo is not attached to the message.');
        $this->assertSame('inline', $logo->getDisposition());
        $this->assertSame('image/png', $logo->getMediaType().'/'.$logo->getMediaSubtype());
        $this->assertSame(file_get_contents(EmbedMailLogo::path()), $logo->getBody());

        // Symfony rewrites cid:<name> to the part's Content-ID when the body is built.
        $this->assertStringContainsString('src="cid:'.$logo->getContentId().'"', $this->builtHtml($email));
        $this->assertDoesNotMatchRegularExpression('/<img[^>]+src="https?:/i', $email->getHtmlBody());
    }

    public function test_a_message_that_does_not_ask_for_the_logo_gets_no_attachment(): void
    {
        Mail::html('<p>Plain</p>', fn ($message) => $message->to('someone@example.test')->subject('Plain'));

        $this->assertSame([], $this->lastSent()->getAttachments());
    }

    public function test_the_frame_carries_the_brand_border_button_and_automated_footer(): void
    {
        $html = $this->send(new ResetPassword('token'))->getHtmlBody();

        $this->assertStringContainsString('border-top: 3px solid #be1f24', $html);
        $this->assertMatchesRegularExpression('/<td class="button-cell"[^>]*bgcolor="#be1f24"/', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*class="button"[^>]*background-color: #be1f24/', $html);
        $this->assertStringContainsString(__('mail.automated', ['app' => config('app.name')]), $html);
        $this->assertStringContainsString('<html lang="en" dir="ltr"', $html);
    }

    public function test_the_password_reset_email_keeps_its_link_and_warns_in_a_red_edged_note(): void
    {
        $user = $this->user();
        $email = $this->send(new ResetPassword('reset-token'), $user);
        $html = $email->getHtmlBody();

        $this->assertSame(__('mail.reset_password.subject'), $email->getSubject());
        $this->assertStringContainsString(e(url(route('password.reset', ['token' => 'reset-token', 'email' => $user->email], false))), $html);
        $this->assertStringContainsString(__('mail.security_notice'), $html);
        $this->assertStringContainsString(e(__('mail.reset_password.notice')), $html);
        $this->assertMatchesRegularExpression('/<td class="notice-content"[^>]*border-left: 4px solid #be1f24/', $html);
        $this->assertStringContainsString(__('mail.reset_password.preheader'), $this->preheader($html));
    }

    public function test_the_verify_email_message_is_branded_too(): void
    {
        $email = $this->send(new VerifyEmail);

        $this->assertSame(__('mail.verify_email.subject'), $email->getSubject());
        $this->assertStringContainsString('/verify-email/', $email->getHtmlBody());
        $this->assertStringContainsString(e(__('mail.verify_email.notice', ['app' => config('app.name')])), $email->getHtmlBody());
    }

    public function test_arabic_emails_read_right_to_left_with_the_note_edge_on_the_right(): void
    {
        app()->setLocale('ar');

        $email = $this->send(new ResetPassword('token'));
        $html = $email->getHtmlBody();

        $this->assertSame(__('mail.reset_password.subject', [], 'ar'), $email->getSubject());
        $this->assertStringContainsString('<html lang="ar" dir="rtl"', $html);
        $this->assertMatchesRegularExpression('/<td class="content-cell" dir="rtl" align="right"/', $html);
        $this->assertMatchesRegularExpression('/<td class="notice-content"[^>]*border-right: 4px solid #be1f24/', $html);
        $this->assertStringContainsString(__('mail.automated', ['app' => config('app.name')], 'ar'), $html);
    }

    public function test_a_code_is_shown_large_and_monospaced_with_its_expiry_and_kept_out_of_the_preview(): void
    {
        $email = $this->send($this->codeNotification());
        $html = $email->getHtmlBody();

        $this->assertMatchesRegularExpression(
            '/<td class="code-value"[^>]*dir="ltr"[^>]*style="[^"]*monospace[^"]*font-size: 34px[^"]*">'.self::CODE.'<\/td>/',
            $html,
        );
        $this->assertStringContainsString(trans_choice('mail.code.expires', 10), $html);

        $preheader = $this->preheader($html);
        $this->assertStringContainsString(trans_choice('mail.code.preheader', 10), $preheader);
        $this->assertStringNotContainsString(self::CODE, $preheader);
        // The preview line comes first in the body, ahead of the code.
        $this->assertLessThan(strpos($html, self::CODE), strpos($html, 'class="preheader"'));
        $this->assertStringNotContainsString(self::CODE, (string) $email->getSubject());

        $this->assertStringContainsString(self::CODE, $email->getTextBody());
        $this->assertStringContainsString(trans_choice('mail.code.expires', 10), $email->getTextBody());
    }

    public function test_the_code_stays_left_to_right_in_an_arabic_email(): void
    {
        app()->setLocale('ar');

        $html = $this->send($this->codeNotification())->getHtmlBody();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertMatchesRegularExpression('/<td class="code-value"[^>]*dir="ltr"/', $html);
        $this->assertStringContainsString(trans_choice('mail.code.expires', 10, [], 'ar'), $html);
    }

    public function test_an_arabic_only_notification_is_framed_in_arabic_whatever_the_app_locale(): void
    {
        app()->setLocale('en');
        $employee = (new Employee)->forceFill(['first_name_ar' => 'رنا', 'last_name_ar' => 'حداد', 'first_name_en' => 'Rana', 'last_name_en' => 'Haddad']);
        $contract = (new Contract)->forceFill(['id' => 7, 'end_date' => '2026-10-06'])->setRelation('employee', $employee);

        $html = $this->send(new ContractsExpiringAutoRenewWarning(collect([$contract])))->getHtmlBody();

        $this->assertStringContainsString('<html lang="ar" dir="rtl"', $html);
        $this->assertStringContainsString(__('mail.automated', ['app' => config('app.name')], 'ar'), $html);
    }

    public function test_english_and_arabic_mail_lang_files_have_the_same_keys(): void
    {
        $en = array_keys(Arr::dot(require lang_path('en/mail.php')));
        $ar = array_keys(Arr::dot(require lang_path('ar/mail.php')));

        sort($en);
        sort($ar);
        $this->assertSame($en, $ar);
    }

    private function codeNotification(): Notification
    {
        return new class(self::CODE) extends Notification
        {
            public function __construct(private readonly string $code) {}

            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): BrandedMailMessage
            {
                return (new BrandedMailMessage)
                    ->subject(__('mail.code.label'))
                    ->line(__('mail.code.label'))
                    ->code($this->code, 10);
            }
        };
    }

    private function user(): User
    {
        return (new User)->forceFill(['id' => 1, 'email' => 'person@example.test']);
    }

    private function send(Notification $notification, ?User $user = null): Email
    {
        ($user ?? $this->user())->notifyNow($notification, ['mail']);

        return $this->lastSent();
    }

    private function lastSent(): Email
    {
        $sent = app('mailer')->getSymfonyTransport()->messages()->last();
        $this->assertNotNull($sent, 'Nothing was sent.');

        return $sent->getOriginalMessage();
    }

    /** The hidden preview-line element, or '' when the email has none. */
    private function preheader(string $html): string
    {
        return preg_match('/<div class="preheader"[^>]*>(.*?)<\/div>/s', $html, $match) ? $match[1] : '';
    }

    /** The HTML as it goes over the wire, after Symfony has resolved cid references. */
    private function builtHtml(Email $email): string
    {
        $html = collect($this->parts($email->getBody()))
            ->first(fn (AbstractPart $part) => $part instanceof TextPart && ! $part instanceof DataPart && $part->getMediaSubtype() === 'html');

        return $html->getBody();
    }

    /** @return list<AbstractPart> */
    private function parts(AbstractPart $part): array
    {
        if (! $part instanceof AbstractMultipartPart) {
            return [$part];
        }

        return array_merge(...array_map(fn (AbstractPart $child) => $this->parts($child), $part->getParts()));
    }
}
