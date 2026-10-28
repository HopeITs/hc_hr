<?php

namespace App\Services;

use App\Models\InAppFeedback;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * The one write of in-app feedback (D3, DEC-08).
 *
 * Stores the text plus exactly the disclosed context, each derived here on the
 * server: the language of this request, the role the user is acting under now
 * (User::myRole()), and a page route name that must name a real route. The
 * caller supplies only the text, an optional category and the route name the
 * form captured on the page it was opened from.
 */
class InAppFeedbackRecorder
{
    public function tooManyAttempts(User $user): bool
    {
        return RateLimiter::tooManyAttempts($this->limiterKey($user), (int) config('feedback.rate_limit.max_attempts', 5));
    }

    public function availableInSeconds(User $user): int
    {
        return RateLimiter::availableIn($this->limiterKey($user));
    }

    /**
     * @throws InvalidArgumentException when the input is outside what the form validates
     */
    public function record(User $user, string $message, ?string $category, ?string $routeName): InAppFeedback
    {
        $message = trim($message);
        $max = (int) config('feedback.max_length', 2000);

        if ($message === '' || mb_strlen($message) > $max) {
            throw new InvalidArgumentException('Feedback text is empty or too long.');
        }

        if ($category !== null && ! in_array($category, config('feedback.categories', []), true)) {
            throw new InvalidArgumentException('Unknown feedback category.');
        }

        RateLimiter::hit($this->limiterKey($user), (int) config('feedback.rate_limit.decay_seconds', 3600));

        $feedback = new InAppFeedback;
        $feedback->user_id = $user->getKey();
        $feedback->category = $category;
        $feedback->message = $message;
        $feedback->route_name = $this->safeRouteName($routeName);
        $feedback->locale = $this->currentLocale();
        $feedback->role_name = $this->activeRoleName($user);
        $feedback->save();

        return $feedback;
    }

    /** A route name only if it names a page route of this application; never a URL. */
    public function safeRouteName(?string $routeName): ?string
    {
        if (! is_string($routeName) || $routeName === '' || strlen($routeName) > 150) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $routeName) || ! Route::has($routeName)) {
            return null;
        }

        // Livewire's own endpoints are not pages.
        return Str::contains($routeName, 'livewire') ? null : $routeName;
    }

    private function currentLocale(): string
    {
        $locale = app()->getLocale();
        $supported = array_keys(LaravelLocalization::getSupportedLocales());

        return in_array($locale, $supported, true) ? $locale : (string) config('app.fallback_locale', 'en');
    }

    private function activeRoleName(User $user): ?string
    {
        $role = trim((string) $user->myRole());

        return $role === '' ? null : mb_substr($role, 0, 64);
    }

    private function limiterKey(User $user): string
    {
        return 'in-app-feedback:'.$user->getKey();
    }
}
