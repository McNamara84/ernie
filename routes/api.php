<?php

use App\Http\Controllers\Api\DataCiteController;
use App\Http\Controllers\Api\RorResolveController;
use App\Http\Controllers\ApiDocController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\DateTypeController;
use App\Http\Controllers\DescriptionTypeController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\OrcidController;
use App\Http\Controllers\RelatedIdentifierTypeController;
use App\Http\Controllers\RelationTypeController;
use App\Http\Controllers\ResourceTypeController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RorAffiliationController;
use App\Http\Controllers\TitleTypeController;
use App\Http\Controllers\VocabularyController;
use Illuminate\Support\Facades\Route;

Route::get('/changelog', [ChangelogController::class, 'index']);

Route::get('/v1/resource-types', [ResourceTypeController::class, 'index']);
Route::middleware('ernie.api-key')->get('/v1/resource-types/elmo', [ResourceTypeController::class, 'elmo']);
Route::get('/v1/resource-types/ernie', [ResourceTypeController::class, 'ernie']);
Route::get('/v1/title-types', [TitleTypeController::class, 'index']);
Route::middleware('ernie.api-key')->get('/v1/title-types/elmo', [TitleTypeController::class, 'elmo']);
Route::get('/v1/title-types/ernie', [TitleTypeController::class, 'ernie']);
Route::get('/v1/date-types', [DateTypeController::class, 'index']);
Route::middleware('ernie.api-key')->get('/v1/date-types/elmo', [DateTypeController::class, 'elmo']);
Route::get('/v1/date-types/ernie', [DateTypeController::class, 'ernie']);
Route::get('/v1/description-types', [DescriptionTypeController::class, 'index']);
Route::middleware('ernie.api-key')->get('/v1/description-types/elmo', [DescriptionTypeController::class, 'elmo']);
Route::get('/v1/description-types/ernie', [DescriptionTypeController::class, 'ernie']);
Route::get('/v1/licenses', [LicenseController::class, 'index']);
Route::middleware('ernie.api-key')->get('/v1/licenses/elmo/{resourceTypeSlug}', [LicenseController::class, 'elmoForResourceType']);
Route::middleware('ernie.api-key')->get('/v1/licenses/elmo', [LicenseController::class, 'elmo']);
Route::get('/v1/licenses/ernie', [LicenseController::class, 'ernie']);
Route::get('/v1/languages', [LanguageController::class, 'index']);
Route::middleware('ernie.api-key')->get('/v1/languages/elmo', [LanguageController::class, 'elmo']);
Route::get('/v1/languages/ernie', [LanguageController::class, 'ernie']);
Route::get('/v1/relation-types', [RelationTypeController::class, 'index']);
Route::middleware('ernie.api-key')->get('/v1/relation-types/elmo', [RelationTypeController::class, 'elmo']);
Route::get('/v1/relation-types/ernie', [RelationTypeController::class, 'ernie']);
Route::get('/v1/identifier-types', [RelatedIdentifierTypeController::class, 'index']);
Route::middleware('ernie.api-key')->get('/v1/identifier-types/elmo', [RelatedIdentifierTypeController::class, 'elmo']);
Route::get('/v1/identifier-types/ernie', [RelatedIdentifierTypeController::class, 'ernie']);
Route::get('/v1/roles/authors/ernie', [RoleController::class, 'authorRolesForErnie']);
Route::middleware('ernie.api-key')->get('/v1/roles/authors/elmo', [RoleController::class, 'authorRolesForElmo']);
Route::get(
    '/v1/roles/contributor-persons/ernie',
    [RoleController::class, 'contributorPersonRolesForErnie'],
);
Route::middleware('ernie.api-key')->get(
    '/v1/roles/contributor-persons/elmo',
    [RoleController::class, 'contributorPersonRolesForElmo'],
);
Route::get(
    '/v1/roles/contributor-institutions/ernie',
    [RoleController::class, 'contributorInstitutionRolesForErnie'],
);
Route::middleware('ernie.api-key')->get(
    '/v1/roles/contributor-institutions/elmo',
    [RoleController::class, 'contributorInstitutionRolesForElmo'],
);
Route::get('/v1/ror-affiliations', RorAffiliationController::class);
Route::middleware('throttle:60,1')->post('/v1/ror-resolve', RorResolveController::class);

// ORCID routes - rate limited to prevent API abuse
// Allows 30 requests per minute per IP address
Route::middleware('throttle:orcid-api')->group(function () {
    Route::get('/v1/orcid/search', [OrcidController::class, 'search']);
    Route::get('/v1/orcid/validate/{orcid}', [OrcidController::class, 'validate']);
    Route::get('/v1/orcid/{orcid}', [OrcidController::class, 'show']);
});
Route::middleware('ernie.api-key')->get('/v1/vocabularies/gcmd-science-keywords', [VocabularyController::class, 'gcmdScienceKeywords']);
Route::middleware('ernie.api-key')->get('/v1/vocabularies/gcmd-platforms', [VocabularyController::class, 'gcmdPlatforms']);
Route::middleware('ernie.api-key')->get('/v1/vocabularies/gcmd-instruments', [VocabularyController::class, 'gcmdInstruments']);
Route::middleware('ernie.api-key')->get('/v1/vocabularies/msl', [VocabularyController::class, 'mslVocabulary']);
Route::middleware('ernie.api-key')->get('/v1/vocabularies/pid4inst-instruments', [VocabularyController::class, 'pid4instInstruments']);
Route::middleware('ernie.api-key')->get('/v1/vocabularies/chronostrat-timescale', [VocabularyController::class, 'chronostratTimescale']);
Route::middleware('ernie.api-key')->get('/v1/vocabularies/gemet', [VocabularyController::class, 'gemetThesaurus']);
Route::middleware('ernie.api-key')->get('/v1/vocabularies/analytical-methods', [VocabularyController::class, 'analyticalMethods']);
Route::middleware('ernie.api-key')->get('/v1/vocabularies/euroscivoc', [VocabularyController::class, 'euroSciVoc']);
Route::middleware('ernie.api-key')->get('/v1/ror-affiliations/elmo', [VocabularyController::class, 'rorAffiliations']);

// Thesauri/PID availability - dual routes: without auth for ERNIE frontend, with API key for ELMO
Route::get('/v1/vocabularies/thesauri-availability', [VocabularyController::class, 'thesauriAvailability']);
Route::get('/v1/vocabularies/pid-availability', [VocabularyController::class, 'pidAvailability']);
Route::middleware('ernie.api-key')->get('/v1/elmo/vocabularies/thesauri-availability', [VocabularyController::class, 'thesauriAvailability']);
Route::middleware('ernie.api-key')->get('/v1/elmo/vocabularies/pid-availability', [VocabularyController::class, 'pidAvailability']);

Route::get('/datacite/citation', [DataCiteController::class, 'getCitation']);
Route::get('/datacite/authors', [DataCiteController::class, 'getAuthors']);

// Thesaurus settings API routes (check, update, update-status) are in web.php
// because they require session-based authentication via can:manage-thesauri gate

Route::get('/v1/doc', ApiDocController::class);
