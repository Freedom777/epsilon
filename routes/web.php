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

Route::get('/test-session', function() {
    // Записываем в сессию
    session(['debug_time' => now()->toDateTimeString()]);

    return response()->json([
        'session_id'    => session()->getId(),
        'debug_time'    => session('debug_time'),
        'cookie_name'   => config('session.cookie'),
        'cookie_secure' => config('session.secure'),
        'cookie_domain' => config('session.domain'),
        'same_site'     => config('session.same_site'),
        'app_env'       => config('app.env'),
        'app_url'       => config('app.url'),
        'is_https'      => request()->isSecure(),
    ]);
});

Route::get('/test-session-auth', function() {
    $sessionData = session()->all();

    return response()->json([
        'session_id'     => session()->getId(),
        'all_keys'       => array_keys($sessionData),
        'has_login_key'  => !empty(
        collect($sessionData)->filter(fn($v, $k) => str_starts_with($k, 'login_'))->toArray()
        ),
        'login_keys'     => collect($sessionData)->filter(
            fn($v, $k) => str_starts_with($k, 'login_')
        )->toArray(),
        'password_hash'  => $sessionData['password_hash_web'] ?? 'NOT SET',
        'auth_check'     => auth()->check(),
    ]);
});
