<?php

use App\Models\ResourceType;
use App\Models\TitleType;
use App\Http\Controllers\UploadXmlController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('/about', function () {
    return Inertia::render('about');
})->name('about');

Route::get('/legal-notice', function () {
    return Inertia::render('legal-notice');
})->name('legal-notice');

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
            'resourceTypes' => ResourceType::orderBy('name')->get(),
            'titleTypes' => TitleType::orderBy('name')->get(),
            'doi' => $request->query('doi'),
            'year' => $request->query('year'),
        ]);
    })->name('curation');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
