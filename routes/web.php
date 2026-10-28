<?php

use App\Http\Controllers\DemoController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\MediaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// The public help center. Articles are Markdown in resources/help.
Route::controller(HelpController::class)->prefix('help')->name('help.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('search', 'search')->name('search');
    Route::get('suggest', 'suggest')->middleware('throttle:120,1')->name('suggest');
    Route::get('{category}', 'category')->where('category', '[a-z0-9-]+')->name('category');
    Route::get('{category}/{article}', 'article')->where(['category' => '[a-z0-9-]+', 'article' => '[a-z0-9-]+'])->name('article');
});

// One-click sign-in to the demo gym (404 unless SPOTTER_DEMO=true).
Route::get('demo', [DemoController::class, 'show'])->middleware('throttle:60,1')->name('demo');
Route::post('demo', [DemoController::class, 'login'])->middleware('throttle:20,1')->name('demo.login');
Route::get('demo/leave', [DemoController::class, 'leave'])->name('demo.leave');

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
        Route::livewire('train', 'pages::coach.train')->name('train');
        Route::livewire('session', 'pages::coach.session')->name('session');
        Route::livewire('inbox', 'pages::coach.inbox')->name('inbox');
        Route::livewire('inbox/focus', 'pages::coach.inbox-focus')->name('inbox.focus');
        Route::livewire('clients', 'pages::coach.clients.index')->name('clients.index');
        Route::livewire('clients/{client}', 'pages::coach.clients.show')->name('clients.show');
        Route::livewire('messages', 'pages::coach.messages')->name('messages');
        // Older links to a client's conversation open it in Messages.
        Route::get('clients/{client}/messages', fn (string $client) => redirect()->route('coach.messages', ['client' => $client]))->name('clients.messages');
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
    Route::get('media/messages/{message}/{index}', [MediaController::class, 'messagePhoto'])->whereNumber('index')->name('media.message-photo');
});

require __DIR__.'/settings.php';
