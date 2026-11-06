<?php

use App\Livewire\Basics\BasicSettings;
use App\Livewire\Basics\HolidayModule;
use App\Livewire\Position\OrgChart;
use Illuminate\Support\Facades\Route;

Route::middleware('reference_page:project-basics')->group(function () {
    // The one entry point (?tab=cities|contract-types|...).
    Route::get('basic-settings', BasicSettings::class)->name('basic-settings.index');

    // The old pages stay, under their old names, and open the matching tab.
    $legacy = [
        'cities' => ['cities.index', 'cities'],
        'contract-types' => ['contract-types.index', 'contract-types'],
        'checklist-items' => ['basic-checklist-items.index', 'checklist-items'],
        'leave-types' => ['leave-types.index', 'leave-types'],
        'job-levels' => ['job-levels.index', 'job-levels'],
        'departments' => ['departments.index', 'departments'],
        'annual-leave-rules' => ['annual-leave-rules.index', 'annual-leave-rules'],
        'termination-reasons' => ['termination-reasons.index', 'termination-reasons'],
    ];

    foreach ($legacy as $uri => [$name, $tab]) {
        Route::get($uri, fn () => redirect()->route('basic-settings.index', ['tab' => $tab]))->name($name);
    }
});

// National HR and program managers (team-coverage-contract §6.1); the
// component checks who may set which holidays, so no reference-page gate here.
Route::get('holidays', HolidayModule::class)->name('holidays.index');

Route::get('/org-chart', OrgChart::class)->middleware('active_permission:positions.view')->name('org.chart');
