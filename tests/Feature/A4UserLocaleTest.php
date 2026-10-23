<?php

namespace Tests\Feature;

use App\Http\Middleware\RememberUserLocale;
use App\Models\User;
use App\Notifications\ContractsExpiringAutoRenewWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A4 decision (2026-10-21): mail sent outside a request goes out in the
 * language the recipient last worked in; a user never seen since the column
 * was added keeps today's behaviour (the app default).
 */
class A4UserLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_interface_language_is_remembered_and_follows_a_switch(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->fresh()->locale);
        $this->assertNull($user->fresh()->preferredLocale());

        $this->runMiddleware($user, 'ar');
        $this->assertSame('ar', $user->fresh()->locale);
        $this->assertSame('ar', $user->fresh()->preferredLocale());

        $this->runMiddleware($user, 'en');
        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_remembering_the_language_writes_nothing_else(): void
    {
        $user = User::factory()->create();
        $updatedAt = $user->fresh()->updated_at;
        $this->travel(5)->minutes();

        $this->runMiddleware($user, 'ar');

        $this->assertEquals($updatedAt, $user->fresh()->updated_at);
        $this->assertDatabaseMissing('activity_log', ['subject_type' => User::class, 'event' => 'updated']);
    }

    public function test_an_unsupported_locale_is_not_stored(): void
    {
        $user = User::factory()->create();

        $this->runMiddleware($user, 'fr');

        $this->assertNull($user->fresh()->locale);
    }

    public function test_contract_expiry_mail_uses_the_recipients_language(): void
    {
        config(['mail.default' => 'array']);
        $english = User::factory()->create();
        $arabic = User::factory()->create();
        $english->forceFill(['locale' => 'en'])->saveQuietly();
        $arabic->forceFill(['locale' => 'ar'])->saveQuietly();

        app()->setLocale('ar');
        Notification::sendNow([$english, $arabic], new ContractsExpiringAutoRenewWarning(collect(), 7));

        $subjects = collect(Mail::mailer('array')->getSymfonyTransport()->messages())
            ->mapWithKeys(fn ($sent) => [
                $sent->getOriginalMessage()->getTo()[0]->getAddress() => $sent->getOriginalMessage()->getSubject(),
            ]);

        $notification = (new ContractsExpiringAutoRenewWarning(collect(), 7))->toArray($english);
        $this->assertSame($notification['title']['en'], $subjects[$english->email]);
        $this->assertSame($notification['title']['ar'], $subjects[$arabic->email]);
    }

    private function runMiddleware(User $user, string $locale): void
    {
        app()->setLocale($locale);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        (new RememberUserLocale)->handle($request, fn () => new Response);
    }
}
