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

// Attendance: the device report becomes a printed monthly timesheet.
Route::prefix('attendance')->name('attendance.')->group(function () {
    Route::get('/upload', \App\Livewire\Attendance\Upload::class)
        ->middleware('active_permission:attendance.manage')->name('upload');
    Route::get('/timesheets', \App\Livewire\Attendance\Timesheets::class)
        ->middleware('active_permission:attendance.view-own')->name('timesheets');
    Route::get('/print', \App\Http\Controllers\AttendancePrintController::class)
        ->middleware('active_permission:attendance.view-own')->name('print');
    Route::get('/export/pdf', [\App\Http\Controllers\AttendanceExportController::class, 'pdf'])
        ->middleware('active_permission:attendance.view-own')->name('export.pdf');
    Route::get('/export/xlsx', [\App\Http\Controllers\AttendanceExportController::class, 'xlsx'])
        ->middleware('active_permission:attendance.view-own')->name('export.xlsx');
    Route::get('/signed/{month}', \App\Http\Controllers\AttendanceSignedController::class)
        ->whereNumber('month')->middleware('active_permission:attendance.view-own')->name('signed');
});

// HR's email / WhatsApp templates; the picker on a person's card needs only message-templates.use.
Route::get('/message-templates', \App\Livewire\Messages\TemplateManager::class)
    ->middleware('active_permission:message-templates.manage')->name('message-templates.index');
