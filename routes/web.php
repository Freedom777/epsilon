<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-admin-auth', function() {
    return response()->json([
        'auth' => auth()->check(),
        'user' => auth()->user()?->email,
        'guard' => config('auth.defaults.guard'),
    ]);
});
