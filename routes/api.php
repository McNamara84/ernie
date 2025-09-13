<?php

use App\Http\Controllers\ApiDocController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ResourceTypeController;
use Illuminate\Support\Facades\Route;

Route::get('/changelog', [ChangelogController::class, 'index']);

Route::get('/v1/resource-types/elmo', [ResourceTypeController::class, 'elmo']);
Route::get('/v1/resource-types/ernie', [ResourceTypeController::class, 'ernie']);
Route::get('/v1/doc', ApiDocController::class);
