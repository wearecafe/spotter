<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Clients join through a link their coach shares with them.
Route::livewire('invitation/{token}', 'pages::invitation.accept')
    ->middleware('guest')
    ->name('invitation.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function (Request $request) {
        return $request->user()->isClient()
            ? redirect()->route('training.index')
            : redirect()->route('coach.dashboard');
    })->name('dashboard');

    Route::middleware('role:coach')->prefix('coach')->name('coach.')->group(function () {
        Route::livewire('/', 'pages::coach.dashboard')->name('dashboard');
        Route::livewire('clients', 'pages::coach.clients.index')->name('clients.index');
        Route::livewire('clients/{client}', 'pages::coach.clients.show')->name('clients.show');
        Route::livewire('exercises', 'pages::coach.exercises')->name('exercises');
        Route::livewire('templates', 'pages::coach.templates')->name('templates');
        Route::livewire('workouts/{workout}', 'pages::coach.workout-builder')->name('workouts.edit');
    });

    Route::middleware('role:client')->prefix('training')->name('training.')->group(function () {
        Route::livewire('/', 'pages::training.index')->name('index');
        Route::livewire('{workout}', 'pages::training.workout')->name('workout');
    });
});

require __DIR__.'/settings.php';
