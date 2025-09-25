<?php

use App\Http\Controllers\UploadXmlController;
use App\Models\License;
use App\Models\Setting;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'laravel' => app()->version()
    ]);
})->name('health');

Route::get('/debug', function () {
    return response()->json([
        'message' => 'Laravel is working!',
        'database' => DB::connection()->getPdo() ? 'Connected' : 'Not connected',
        'redis' => Cache::get('test') !== null ? 'Available' : 'Testing...',
        'app_key' => config('app.key') ? 'Set' : 'Missing',
        'app_url' => config('app.url'),
        'environment' => app()->environment()
    ]);
})->name('debug');

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('/about', function () {
    return Inertia::render('about');
})->name('about');

Route::get('/legal-notice', function () {
    return Inertia::render('legal-notice');
})->name('legal-notice');

Route::get('/changelog', function () {
    return Inertia::render('changelog');
})->name('changelog');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('dashboard/upload-xml', UploadXmlController::class)
        ->name('dashboard.upload-xml');

    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('docs', function () {
        return Inertia::render('docs');
    })->name('docs');

    Route::get('docs/users', function () {
        return Inertia::render('docs-users');
    })->name('docs.users');

    Route::get('curation', function (\Illuminate\Http\Request $request) {
        return Inertia::render('curation', [
            'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
            'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
            'doi' => $request->query('doi'),
            'year' => $request->query('year'),
            'version' => $request->query('version'),
            'language' => $request->query('language'),
            'resourceType' => $request->query('resourceType'),
            'titles' => $request->query('titles', []),
            'initialLicenses' => $request->query('licenses', []),
        ]);
    })->name('curation');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
