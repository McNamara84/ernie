<?php

use App\Http\Controllers\DatacenterController;
use App\Http\Controllers\LandingPageDomainController;
use App\Http\Controllers\Settings\EditorSettingsController;
use App\Http\Controllers\Settings\FontSizeController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    // Editor Settings (Admin, Group Leader only - Issue #379)
    Route::middleware(['can:access-editor-settings'])->group(function () {
        Route::get('settings', [EditorSettingsController::class, 'index'])->name('settings');
        Route::post('settings', [EditorSettingsController::class, 'update'])->name('settings.update');

        // Landing Page Domains API (Issue #540)
        // Note: GET listing is at /api/landing-page-domains/list in web.php (available to all authenticated users).
        Route::post('api/landing-page-domains', [LandingPageDomainController::class, 'store'])->name('landing-page-domains.store');
        Route::delete('api/landing-page-domains/{landing_page_domain}', [LandingPageDomainController::class, 'destroy'])->name('landing-page-domains.destroy');

        // Datacenters API (Issue: Datacenter categorization)
        Route::post('api/datacenters', [DatacenterController::class, 'store'])->name('datacenters.store');
        Route::delete('api/datacenters/{datacenter}', [DatacenterController::class, 'destroy'])->name('datacenters.destroy');
    });

    // Personal settings (all authenticated users)
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    Route::put('settings/font-size', [FontSizeController::class, 'update'])->name('font-size.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});
