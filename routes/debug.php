<?php

use Illuminate\Support\Facades\Route;

Route::get('/debug/session-info', function () {
    return response()->json([
        'scheme' => request()->getScheme(),
        'host' => request()->getHost(),
        'path' => request()->getPath(),
        'url' => request()->url(),
        'full_url' => request()->fullUrl(),
        'session_id' => session()->getId(),
        'csrf_token' => csrf_token(),
        'session_config' => [
            'driver' => config('session.driver'),
            'path' => config('session.path'),
            'domain' => config('session.domain'),
            'secure' => config('session.secure'),
            'same_site' => config('session.same_site'),
            'http_only' => config('session.http_only'),
        ],
        'headers' => [
            'X-Forwarded-Proto' => request()->header('X-Forwarded-Proto'),
            'X-Forwarded-Host' => request()->header('X-Forwarded-Host'),
            'X-Forwarded-For' => request()->header('X-Forwarded-For'),
            'X-Forwarded-Prefix' => request()->header('X-Forwarded-Prefix'),
        ],
        'cookies' => request()->cookies->all(),
        'is_secure' => request()->secure(),
        'app_url' => config('app.url'),
    ]);
})->name('debug.session-info');
