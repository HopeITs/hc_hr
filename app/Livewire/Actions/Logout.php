<?php

namespace App\Livewire\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class Logout
{
    /**
     * Log the current user out of the application.
     */
    public function __invoke()
    {
        // Read before the session is cleared, so the sign-in page that
        // follows stays in the language the user was working in.
        $locale = app()->getLocale();

        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();

        return redirect(LaravelLocalization::localizeUrl('/', $locale));
    }
}
