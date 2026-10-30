<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * The component sheet (/{locale}/_ui): every shared component in every state,
 * the visual regression reference for the identity work. A developer tool, so
 * it exists only in the local and testing environments; anywhere else it is a
 * plain 404, indistinguishable from a page that was never there.
 */
class UiSheetController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(app()->environment('local', 'testing'), 404);

        return view('ui-sheet');
    }
}
