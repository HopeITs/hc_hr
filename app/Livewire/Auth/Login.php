<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Access\OrgRoleSync;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

#[Layout('components.layouts.auth.simple')]
class Login extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function render()
    {
        return view('livewire.auth.login')
            ->layoutData(['description' => __('auth.meta-description')]);
    }

    public function updatedEmail(): void
    {
        $this->resetValidation();
    }

    public function login(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(
            ['email' => $this->email, 'password' => $this->password],
            $this->remember,
        )) {
            $this->failAuthentication();
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user?->profile?->is_active) {
            Auth::guard('web')->logout();
            Session::invalidate();
            Session::regenerateToken();
            $this->failAuthentication();
        }

        // Only the per-account counter is cleared. Clearing the IP counter on
        // success would let an attacker who holds one valid credential reset
        // the spray limiter at will.
        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        // Roles that follow the org chart and the contracts are brought up to date
        // before one is picked, so a new team lead signs in as a manager at once.
        try {
            app(OrgRoleSync::class)->syncUser($user);
        } catch (Throwable $e) {
            report($e);
        }

        DB::transaction(function () use ($user) {
            $allIds = $user->roles()->pluck('roles.id')->all();

            if ($allIds) {
                $user->roles()->updateExistingPivot($allIds, ['is_active' => false]);
            }

            $roleIdToActivate = $user->defaultLoginRoleId();

            if ($roleIdToActivate) {
                $user->roles()->updateExistingPivot($roleIdToActivate, ['is_active' => true]);
                session(['active_role_id' => $roleIdToActivate]);
            }
        });

        // Not navigate: true — Session::regenerate() above rotates the CSRF
        // token, but the login page still holds the old token in its meta
        // tag. A wire:navigate fetch would send that stale token, get
        // rejected (419), and leave the loading bar spinning forever with
        // no visible error. A full redirect always picks up the new session.
        $this->redirectIntended(
            default: route('dashboard', absolute: false),
        );
    }

    /**
     * Two independent limiters, because they stop different attacks.
     *
     * The account+IP limiter stops brute force against one account. It does
     * nothing against password spraying, where an attacker tries one common
     * password against thousands of accounts: each account+IP pair sees a
     * single failure and never approaches its threshold. The IP-only limiter
     * closes that, because every one of those attempts shares a source.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            event(new Lockout(request()));

            $seconds = RateLimiter::availableIn($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }

        // Deliberately generous relative to the per-account limit: a shared
        // office NAT can legitimately produce a run of failures, so this is
        // sized to stop automation rather than to police typos.
        if (RateLimiter::tooManyAttempts($this->ipThrottleKey(), 25)) {
            event(new Lockout(request()));

            throw ValidationException::withMessages([
                'email' => __('auth.throttle_ip', [
                    'seconds' => RateLimiter::availableIn($this->ipThrottleKey()),
                ]),
            ]);
        }
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->email).'|'.request()->ip(),
        );
    }

    protected function ipThrottleKey(): string
    {
        return 'login-ip|'.request()->ip();
    }

    private function failAuthentication(): never
    {
        RateLimiter::hit($this->throttleKey(), 60);
        // Held for 15 minutes: spraying is slow and low-volume by design, so a
        // one-minute window would reset before the pattern became visible.
        RateLimiter::hit($this->ipThrottleKey(), 900);

        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }
}
