<?php

use App\Livewire\Basics\AnnualLeaveRuleModule;
use App\Livewire\Basics\ChecklistItemModule;
use App\Livewire\Basics\CityModule;
use App\Livewire\Basics\ContractTypeModule;
use App\Livewire\Basics\DepartmentModule;
use App\Livewire\Basics\HolidayModule;
use App\Livewire\Basics\JobLevelModule;
use App\Livewire\Basics\LeaveTypeModule;
use App\Livewire\Basics\TerminationReasonModule;
use App\Livewire\Position\OrgChart;
use Illuminate\Support\Facades\Route;

Route::middleware('reference_page:project-basics')->group(function () {
    Route::get('cities', CityModule::class)->name('cities.index');
    Route::get('contract-types', ContractTypeModule::class)->name('contract-types.index');
    Route::get('checklist-items', ChecklistItemModule::class)->name('basic-checklist-items.index');
    Route::get('leave-types', LeaveTypeModule::class)->name('leave-types.index');
    Route::get('job-levels', JobLevelModule::class)->name('job-levels.index');
    Route::get('departments', DepartmentModule::class)->name('departments.index');
    Route::get('annual-leave-rules', AnnualLeaveRuleModule::class)->name('annual-leave-rules.index');
    Route::get('termination-reasons', TerminationReasonModule::class)->name('termination-reasons.index');
});

// National HR and program managers (team-coverage-contract §6.1); the
// component checks who may set which holidays, so no reference-page gate here.
Route::get('holidays', HolidayModule::class)->name('holidays.index');

Route::get('/org-chart', OrgChart::class)->middleware('active_permission:positions.view')->name('org.chart');
