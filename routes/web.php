<?php

use App\Http\Controllers\MediaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Clients join through a link their coach shares with them.
Route::livewire('invitation/{token}', 'pages::invitation.accept')
    ->middleware('guest')
    ->name('invitation.accept');

// Coaches join a team through an emailed link (works signed in or out).
Route::livewire('team-invitation/{token}', 'pages::team-invitation.accept')->name('team-invitation.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function (Request $request) {
        return $request->user()->isClient()
            ? redirect()->route('training.index')
            : redirect()->route('coach.dashboard');
    })->name('dashboard');

    Route::middleware('role:coach')->prefix('coach')->name('coach.')->group(function () {
        Route::livewire('/', 'pages::coach.dashboard')->name('dashboard');
        Route::livewire('setup', 'pages::coach.setup')->name('setup');
        Route::livewire('team', 'pages::coach.team')->name('team');
        Route::livewire('inbox', 'pages::coach.inbox')->name('inbox');
        Route::livewire('inbox/focus', 'pages::coach.inbox-focus')->name('inbox.focus');
        Route::livewire('clients', 'pages::coach.clients.index')->name('clients.index');
        Route::livewire('clients/{client}', 'pages::coach.clients.show')->name('clients.show');
        Route::livewire('clients/{client}/messages', 'pages::coach.clients.messages')->name('clients.messages');
        Route::livewire('clients/{client}/nutrition', 'pages::coach.clients.nutrition')->name('clients.nutrition');
        Route::livewire('programs/{program}', 'pages::coach.program')->name('programs.show');
        Route::livewire('imports/{import}', 'pages::coach.import')->name('imports.show');
        Route::livewire('exercises', 'pages::coach.exercises')->name('exercises');
        Route::livewire('templates', 'pages::coach.templates')->name('templates');
        Route::livewire('workouts/{workout}', 'pages::coach.workout-builder')->name('workouts.edit');
    });

    Route::middleware('role:client')->prefix('training')->name('training.')->group(function () {
        Route::livewire('/', 'pages::training.index')->name('index');
        Route::livewire('check-in', 'pages::training.check-in')->name('check-in');
        Route::livewire('messages', 'pages::training.messages')->name('messages');
        Route::livewire('nutrition', 'pages::training.nutrition')->name('nutrition');
        Route::livewire('{workout}', 'pages::training.workout')->whereNumber('workout')->name('workout');
    });

    // Spotter staff backoffice.
    Route::middleware('staff')->prefix('admin')->name('admin.')->group(function () {
        Route::livewire('/', 'pages::admin.tenants')->name('tenants');
        Route::livewire('tenants/{team}', 'pages::admin.tenant')->name('tenants.show');
        Route::livewire('audit', 'pages::admin.audit')->name('audit');
    });

    Route::get('media/form-checks/{formCheck}', [MediaController::class, 'formCheck'])->name('media.form-check');
    Route::get('media/check-ins/{checkIn}/{index}', [MediaController::class, 'checkInPhoto'])->whereNumber('index')->name('media.check-in-photo');
});

require __DIR__.'/settings.php';
