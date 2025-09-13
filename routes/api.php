<?php

use App\Http\Controllers\ApiDocController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ResourceTypeController;
use App\Http\Controllers\TitleTypeController;
use App\Http\Controllers\LicenseController;
use Illuminate\Support\Facades\Route;

Route::get('/changelog', [ChangelogController::class, 'index']);

Route::get('/v1/resource-types', [ResourceTypeController::class, 'index']);
Route::get('/v1/resource-types/elmo', [ResourceTypeController::class, 'elmo']);
Route::get('/v1/resource-types/ernie', [ResourceTypeController::class, 'ernie']);
Route::get('/v1/title-types', [TitleTypeController::class, 'index']);
Route::get('/v1/title-types/elmo', [TitleTypeController::class, 'elmo']);
Route::get('/v1/title-types/ernie', [TitleTypeController::class, 'ernie']);
Route::get('/v1/licenses', [LicenseController::class, 'index']);
Route::get('/v1/licenses/elmo', [LicenseController::class, 'elmo']);
Route::get('/v1/licenses/ernie', [LicenseController::class, 'ernie']);
Route::get('/v1/doc', ApiDocController::class);
