<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// This app is API-only (no session-based web auth). Laravel's default
// Authenticate middleware falls back to redirecting unauthenticated
// requests to a route named "login" when the request doesn't look like
// it's expecting JSON. Since we never built a "login" web route, that
// redirect attempt itself crashed with "Route [login] not defined."
// Registering this name here just makes that fallback return a clean
// 401 instead of a 500.
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');
