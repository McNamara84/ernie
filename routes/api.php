<?php

use App\Http\Controllers\ApiDocController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ResourceTypeController;
use App\Http\Controllers\TitleTypeController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\RorAffiliationController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\VocabularyController;
use Illuminate\Support\Facades\Route;

Route::get('/changelog', [ChangelogController::class, 'index']);

Route::get('/v1/resource-types', [ResourceTypeController::class, 'index']);
Route::middleware('elmo.api-key')->get('/v1/resource-types/elmo', [ResourceTypeController::class, 'elmo']);
Route::get('/v1/resource-types/ernie', [ResourceTypeController::class, 'ernie']);
Route::get('/v1/title-types', [TitleTypeController::class, 'index']);
Route::middleware('elmo.api-key')->get('/v1/title-types/elmo', [TitleTypeController::class, 'elmo']);
Route::get('/v1/title-types/ernie', [TitleTypeController::class, 'ernie']);
Route::get('/v1/licenses', [LicenseController::class, 'index']);
Route::middleware('elmo.api-key')->get('/v1/licenses/elmo', [LicenseController::class, 'elmo']);
Route::get('/v1/licenses/ernie', [LicenseController::class, 'ernie']);
Route::get('/v1/languages', [LanguageController::class, 'index']);
Route::middleware('elmo.api-key')->get('/v1/languages/elmo', [LanguageController::class, 'elmo']);
Route::get('/v1/languages/ernie', [LanguageController::class, 'ernie']);
Route::get('/v1/roles/authors/ernie', [RoleController::class, 'authorRolesForErnie']);
Route::middleware('elmo.api-key')->get('/v1/roles/authors/elmo', [RoleController::class, 'authorRolesForElmo']);
Route::get(
    '/v1/roles/contributor-persons/ernie',
    [RoleController::class, 'contributorPersonRolesForErnie'],
);
Route::middleware('elmo.api-key')->get(
    '/v1/roles/contributor-persons/elmo',
    [RoleController::class, 'contributorPersonRolesForElmo'],
);
Route::get(
    '/v1/roles/contributor-institutions/ernie',
    [RoleController::class, 'contributorInstitutionRolesForErnie'],
);
Route::middleware('elmo.api-key')->get(
    '/v1/roles/contributor-institutions/elmo',
    [RoleController::class, 'contributorInstitutionRolesForElmo'],
);
Route::get('/v1/ror-affiliations', RorAffiliationController::class);
Route::middleware('elmo.api-key')->get('/v1/vocabularies/gcmd-science-keywords', [VocabularyController::class, 'gcmdScienceKeywords']);
Route::middleware('elmo.api-key')->get('/v1/vocabularies/gcmd-platforms', [VocabularyController::class, 'gcmdPlatforms']);
Route::middleware('elmo.api-key')->get('/v1/vocabularies/gcmd-instruments', [VocabularyController::class, 'gcmdInstruments']);
Route::get('/v1/msl-vocabulary-url', function () {
    return response()->json([
        'url' => config('msl.vocabulary_url'),
    ]);
});
Route::get('/v1/doc', ApiDocController::class);
