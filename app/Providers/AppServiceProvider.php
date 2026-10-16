<?php

namespace App\Providers;

use App\Http\Middleware\ActiveLivewirePermissionMiddleware;
use App\Livewire\Features\SupportToasts;
use App\Models\ApplicationForm;
use App\Models\Badge;
use App\Models\Center;
use App\Models\Contract;
use App\Models\DefaultDeliverable;
use App\Models\Employee;
use App\Models\HiringCampaign;
use App\Models\JobPost;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\PublicRecruitmentLink;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplicantFile;
use App\Models\RecruitmentApplicantNote;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentCampaignInvite;
use App\Models\RecruitmentHiringConversion;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewEvaluation;
use App\Models\RecruitmentJobOffer;
use App\Models\RecruitmentRequest;
use App\Models\RecruitmentRequestEvent;
use App\Models\RecruitmentTag;
use App\Models\RecruitmentWorkflowInstanceStep;
use App\Models\Vacancy;
use App\Notifications\Messages\BrandedMailMessage;
use App\Observers\ContractObserver;
use App\Observers\EmployeeObserver;
use App\Observers\RecruitmentApplicantObserver;
use App\Observers\RecruitmentApplicationObserver;
use App\Observers\RecruitmentCampaignInviteObserver;
use App\Policies\ApplicationFormPolicy;
use App\Policies\BadgePolicy;
use App\Policies\CenterPolicy;
use App\Policies\ContractPolicy;
use App\Policies\DefaultDeliverablePolicy;
use App\Policies\EmployeePolicy;
use App\Policies\HiringCampaignPolicy;
use App\Policies\JobPostPolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\PositionPolicy;
use App\Policies\PublicRecruitmentLinkPolicy;
use App\Policies\RecruitmentApplicantFilePolicy;
use App\Policies\RecruitmentApplicantNotePolicy;
use App\Policies\RecruitmentApplicantPolicy;
use App\Policies\RecruitmentApplicationPolicy;
use App\Policies\RecruitmentCampaignInvitePolicy;
use App\Policies\RecruitmentHiringConversionPolicy;
use App\Policies\RecruitmentInterviewEvaluationPolicy;
use App\Policies\RecruitmentInterviewPolicy;
use App\Policies\RecruitmentJobOfferPolicy;
use App\Policies\RecruitmentRequestEventPolicy;
use App\Policies\RecruitmentRequestPolicy;
use App\Policies\RecruitmentTagPolicy;
use App\Policies\RecruitmentWorkflowInstanceStepPolicy;
use App\Policies\VacancyPolicy;
use App\Services\ActivityLogger;
use App\Services\Monitoring\SecurityMonitor;
use App\Services\Recruitment\Privacy\ConfiguredRetentionPolicy;
use App\Services\Recruitment\Privacy\RetentionPolicy;
use App\Services\Recruitment\Scanning\ClamdScanner;
use App\Services\Recruitment\Scanning\DisabledScanner;
use App\Services\Recruitment\Scanning\RecruitmentFileScanner;
use App\Services\RecruitmentIndicatorRules;
use App\Services\SalaryCipher;
use App\Support\Permissions\PermissionCacheGeneration;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivityLogger::class, function ($app) {
            return new ActivityLogger;
        });

        // Its dedupe counters live in the cache, so one instance is plenty.
        $this->app->singleton(SecurityMonitor::class);

        // The seam for the organization's retention decision: bind another
        // RetentionPolicy here once the periods are confirmed.
        $this->app->bind(RetentionPolicy::class, ConfiguredRetentionPolicy::class);

        // Singleton so the Encrypter - and the "this host cannot read the
        // figures" warning that goes with it - is built once per request
        // rather than once per contract row rendered.
        $this->app->singleton(SalaryCipher::class);
        // One instance, so a save is seen at once by everything that evaluates indicators.
        $this->app->singleton(RecruitmentIndicatorRules::class);

        // Anything but "clamd" leaves every upload in quarantine; there is no
        // driver that clears a file without scanning it.
        $this->app->bind(RecruitmentFileScanner::class, fn () => config('recruitment-uploads.scanner.driver') === 'clamd'
            ? new ClamdScanner
            : new DisabledScanner);

        // Sends or flashes the toasts raised through WithFeedback::toast().
        // Registered while booting, not in boot(): Livewire attaches the hooks
        // it knows about during its own boot, so one added afterwards is never
        // called, and this one has to run ahead of Livewire's event hook.
        $this->app->booting(fn () => Livewire::componentHook(SupportToasts::class));
    }

    public function boot(): void
    {
        // Spatie forgets its cache key on every grant or revocation; User drops
        // its memoized roles and permissions when the generation moves on.
        Event::listen(function (KeyForgotten $event): void {
            if ($event->key === config('permission.cache.key')) {
                PermissionCacheGeneration::bump();
            }
        });

        RateLimiter::for('careers-view', fn (Request $request) => Limit::perMinute(60)
            ->by($request->ip()));
        // Asking "is this address invited?" tells the asker, so it is the one
        // question on the public page that has to be slowed down.
        RateLimiter::for('careers-invitation', fn (Request $request) => [
            Limit::perMinute(6)->by($request->ip().'|'.hash('sha256', (string) $request->route('token'))),
            Limit::perHour(40)->by($request->ip()),
        ]);
        RateLimiter::for('careers-submit', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip().'|'.hash('sha256', (string) $request->route('token'))),
            Limit::perHour(30)->by($request->ip()),
        ]);

        // Applies wherever Rules\Password::defaults() is used (registration,
        // reset, and the settings screen).
        //
        // 12 characters rather than 8: length is what defeats offline cracking,
        // and a passphrase is easier to remember than a short string with
        // symbol requirements bolted on. No character-class rules, which push
        // users toward predictable substitutions; uncompromised() checks the
        // password against the Have I Been Pwned k-anonymity range API instead,
        // which catches the passwords that are actually being sprayed.
        PasswordRule::defaults(fn () => $this->app->isProduction()
            ? PasswordRule::min(12)->max(255)->uncompromised()
            : PasswordRule::min(12)->max(255));

        Gate::policy(Badge::class, BadgePolicy::class);
        Gate::policy(Center::class, CenterPolicy::class);
        Gate::policy(Contract::class, ContractPolicy::class);
        Gate::policy(DefaultDeliverable::class, DefaultDeliverablePolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(LeaveRequest::class, LeaveRequestPolicy::class);
        Gate::policy(Position::class, PositionPolicy::class);
        Gate::policy(RecruitmentRequest::class, RecruitmentRequestPolicy::class);
        Gate::policy(RecruitmentApplicantFile::class, RecruitmentApplicantFilePolicy::class);
        Gate::policy(RecruitmentApplicant::class, RecruitmentApplicantPolicy::class);
        Gate::policy(RecruitmentApplication::class, RecruitmentApplicationPolicy::class);
        Gate::policy(RecruitmentTag::class, RecruitmentTagPolicy::class);
        Gate::policy(RecruitmentApplicantNote::class, RecruitmentApplicantNotePolicy::class);
        Gate::policy(RecruitmentWorkflowInstanceStep::class, RecruitmentWorkflowInstanceStepPolicy::class);
        Gate::policy(Vacancy::class, VacancyPolicy::class);
        Gate::policy(HiringCampaign::class, HiringCampaignPolicy::class);
        Gate::policy(JobPost::class, JobPostPolicy::class);
        Gate::policy(ApplicationForm::class, ApplicationFormPolicy::class);
        Gate::policy(PublicRecruitmentLink::class, PublicRecruitmentLinkPolicy::class);
        Gate::policy(RecruitmentRequestEvent::class, RecruitmentRequestEventPolicy::class);
        Gate::policy(RecruitmentCampaignInvite::class, RecruitmentCampaignInvitePolicy::class);
        // Team 2 (Phase 2 Task 7)
        Gate::policy(RecruitmentInterview::class, RecruitmentInterviewPolicy::class);
        // Team 2 (Phase 2 Task 8)
        Gate::policy(RecruitmentInterviewEvaluation::class, RecruitmentInterviewEvaluationPolicy::class);
        // Team 2 (Phase 2 Task 9)
        Gate::policy(RecruitmentJobOffer::class, RecruitmentJobOfferPolicy::class);
        // Team 2 (Phase 2 Task 10)
        Gate::policy(RecruitmentHiringConversion::class, RecruitmentHiringConversionPolicy::class);

        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/livewire/update', $handle)
                ->middleware(['web', ActiveLivewirePermissionMiddleware::class])
                ->prefix(LaravelLocalization::setLocale());
        });

        Blade::directive('glyph', function ($expr) {
            return '<?php echo app(\App\Support\Arabic::class)->glyphs('.$expr.'); ?>';
        });

        Builder::macro('whereLikeAny', function ($fields, string $search) {
            $fields = Arr::wrap($fields);
            if ($search === '' || $search === null) {
                return $this;
            }

            return $this->where(function ($q) use ($fields, $search) {
                foreach ($fields as $field) {
                    $q->orWhere($field, 'like', "%{$search}%");
                }
            });
        });

        Builder::macro('search', function ($fields, $string) {
            if (! $string) {
                return $this;
            }

            $fields = Arr::wrap($fields);

            return $this->where(function ($query) use ($fields, $string) {
                foreach ($fields as $field) {
                    $query->orWhere($field, 'like', '%'.$string.'%');
                }

            });

        });
        Builder::macro('findBySlug', function ($fields, $string) {
            if (! $string) {
                return $this;
            }

            $fields = Arr::wrap($fields);

            return $this->where(function ($query) use ($fields, $string) {
                foreach ($fields as $field) {
                    $query->orWhere($field, 'like', '%'.$string.'%');
                }

            });

        });

        Blade::if('activerole', function (...$roles) {
            $user = auth()->user();

            return $user?->activeRoleIs(...$roles) ?? false;
        });

        Blade::if('notactiverole', function (...$roles) {
            return auth()->check() && ! auth()->user()->activeRoleIs(...$roles);
        });

        Blade::if('activepermission', function (string $permission) {
            return auth()->user()?->hasActivePermission($permission) ?? false;
        });

        Contract::observe(ContractObserver::class);
        Employee::observe(EmployeeObserver::class);

        // Team 2 (Phase 2): rules that must hold for every path that writes
        // these records — Team 1's public form included — without that code
        // being modified. Phone-duplicate hint; submission audit; a used
        // invite is final.
        RecruitmentApplicant::observe(RecruitmentApplicantObserver::class);
        RecruitmentApplication::observe(RecruitmentApplicationObserver::class);
        RecruitmentCampaignInvite::observe(RecruitmentCampaignInviteObserver::class);

        $this->brandAuthEmails();
    }

    /**
     * Laravel's password-reset and verify-email messages, rebuilt from the
     * lang files with the security note the branded template shows. The
     * links are the ones Laravel would build itself.
     */
    private function brandAuthEmails(): void
    {
        ResetPassword::toMailUsing(fn (object $notifiable, string $token) => (new BrandedMailMessage)
            ->subject(__('mail.reset_password.subject'))
            ->preheader(__('mail.reset_password.preheader'))
            ->line(__('mail.reset_password.intro', ['app' => config('app.name')]))
            ->action(__('mail.reset_password.action'), url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false)))
            ->line(trans_choice('mail.reset_password.expires', (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire')))
            ->notice(__('mail.reset_password.notice')));

        VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => (new BrandedMailMessage)
            ->subject(__('mail.verify_email.subject'))
            ->preheader(__('mail.verify_email.preheader'))
            ->line(__('mail.verify_email.intro'))
            ->action(__('mail.verify_email.action'), $url)
            ->line(trans_choice('mail.verify_email.expires', (int) config('auth.verification.expire', 60)))
            ->notice(__('mail.verify_email.notice', ['app' => config('app.name')])));
    }
}
