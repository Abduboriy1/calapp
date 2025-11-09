<?php

use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\Integrations\GoogleAuthController;
use App\Http\Controllers\Integrations\GoogleCalendarController;
use App\Http\Controllers\GoogleOAuthController;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');



Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/calendar', fn() => Inertia::render('calendar/Index'))->name('calendar');

    // JSON endpoints
    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::post('/events', [EventController::class, 'store'])->name('events.store');
    Route::put('/events/{event}', [EventController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // UI page
    Route::get('/integrations', [GoogleCalendarController::class, 'index'])->name('integrations.index');

    // OAuth
//    Route::get('/integrations/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
//    Route::get('/integrations/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');

    // Manage
    Route::post('/integrations/google/revoke', [GoogleAuthController::class, 'revoke'])->name('google.revoke');

    // Data endpoints
    Route::get('/api/google/calendars', [GoogleCalendarController::class, 'listCalendars'])->name('google.calendars');
    Route::get('/api/google/events', [GoogleCalendarController::class, 'listEvents'])->name('google.events'); // ?calendarId=primary&from=...&to=...
});


Route::middleware('auth')->group(function () {
    Route::get('/oauth/google/redirect', [GoogleOAuthController::class, 'redirect'])->name('google.redirect');
});

Route::get('/oauth/google/callback', [GoogleOAuthController::class, 'callback'])
    ->name('google.callback');

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';
