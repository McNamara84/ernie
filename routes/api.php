<?php

use App\Http\Controllers\Api\DataCiteController;
use App\Http\Controllers\Api\RorResolveController;
use App\Http\Controllers\ApiDocController;
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
Route::middleware('ernie.api-key')->get('/v1/vocabularies/gcmd-science-keywords', [VocabularyController::class, 'gcmdScienceKeywords'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/vocabularies/gcmd-platforms', [VocabularyController::class, 'gcmdPlatforms'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/vocabularies/gcmd-instruments', [VocabularyController::class, 'gcmdInstruments'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/vocabularies/msl', [VocabularyController::class, 'mslVocabulary'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/vocabularies/msl-laboratories', [VocabularyController::class, 'mslLaboratories'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/vocabularies/pid4inst-instruments', [VocabularyController::class, 'pid4instInstruments'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/vocabularies/raid-projects', [VocabularyController::class, 'raidProjects'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/vocabularies/chronostrat-timescale', [VocabularyController::class, 'chronostratTimescale'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/vocabularies/gemet', [VocabularyController::class, 'gemetThesaurus'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/vocabularies/analytical-methods', [VocabularyController::class, 'analyticalMethods'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/vocabularies/euroscivoc', [VocabularyController::class, 'euroSciVoc'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/vocabularies/cgi-simple-lithology', [VocabularyController::class, 'cgiSimpleLithology'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/ror-affiliations/elmo', [VocabularyController::class, 'rorAffiliations'])->defaults('editor', 'elmo');

// Thesauri/PID availability - dual routes: without auth for ERNIE frontend, with API key for ELMO
Route::get('/v1/vocabularies/thesauri-availability', [VocabularyController::class, 'thesauriAvailability']);
Route::get('/v1/vocabularies/pid-availability', [VocabularyController::class, 'pidAvailability']);
Route::middleware('ernie.api-key')->get('/v1/elmo/vocabularies/thesauri-availability', [VocabularyController::class, 'thesauriAvailability'])->defaults('editor', 'elmo');
Route::middleware('ernie.api-key')->get('/v1/elmo/vocabularies/pid-availability', [VocabularyController::class, 'pidAvailability'])->defaults('editor', 'elmo');

Route::get('/datacite/citation', [DataCiteController::class, 'getCitation']);
Route::get('/datacite/authors', [DataCiteController::class, 'getAuthors']);

// Thesaurus settings API routes (check, update, update-status) are in web.php
// because they require session-based authentication via can:manage-thesauri gate

Route::middleware('ernie.api-key')->group(function () {
    Route::get('/v1/resource-types/elmo-msl', [ResourceTypeController::class, 'elmoMsl']);
    Route::get('/v1/title-types/elmo-msl', [TitleTypeController::class, 'elmoMsl']);
    Route::get('/v1/date-types/elmo-msl', [DateTypeController::class, 'elmoMsl']);
    Route::get('/v1/description-types/elmo-msl', [DescriptionTypeController::class, 'elmoMsl']);
    Route::get('/v1/languages/elmo-msl', [LanguageController::class, 'elmoMsl']);
    Route::get('/v1/relation-types/elmo-msl', [RelationTypeController::class, 'elmoMsl']);
    Route::get('/v1/identifier-types/elmo-msl', [RelatedIdentifierTypeController::class, 'elmoMsl']);
    Route::get('/v1/licenses/elmo-msl', [LicenseController::class, 'elmoMsl']);
    Route::get('/v1/licenses/elmo-msl/{resourceTypeSlug}', [LicenseController::class, 'elmoMslForResourceType']);
    Route::get('/v1/roles/authors/elmo-msl', [RoleController::class, 'authorRolesForElmoMsl']);
    Route::get('/v1/roles/contributor-persons/elmo-msl', [RoleController::class, 'contributorPersonRolesForElmoMsl']);
    Route::get('/v1/roles/contributor-institutions/elmo-msl', [RoleController::class, 'contributorInstitutionRolesForElmoMsl']);
    Route::get('/v1/ror-affiliations/elmo-msl', [VocabularyController::class, 'rorAffiliations'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/gcmd-science-keywords', [VocabularyController::class, 'gcmdScienceKeywords'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/gcmd-platforms', [VocabularyController::class, 'gcmdPlatforms'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/gcmd-instruments', [VocabularyController::class, 'gcmdInstruments'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/msl', [VocabularyController::class, 'mslVocabulary'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/msl-laboratories', [VocabularyController::class, 'mslLaboratories'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/pid4inst-instruments', [VocabularyController::class, 'pid4instInstruments'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/raid-projects', [VocabularyController::class, 'raidProjects'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/chronostrat-timescale', [VocabularyController::class, 'chronostratTimescale'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/gemet', [VocabularyController::class, 'gemetThesaurus'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/analytical-methods', [VocabularyController::class, 'analyticalMethods'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/euroscivoc', [VocabularyController::class, 'euroSciVoc'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/cgi-simple-lithology', [VocabularyController::class, 'cgiSimpleLithology'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/thesauri-availability', [VocabularyController::class, 'thesauriAvailability'])->defaults('editor', 'elmo-msl');
    Route::get('/v1/elmo-msl/vocabularies/pid-availability', [VocabularyController::class, 'pidAvailability'])->defaults('editor', 'elmo-msl');
});

Route::get('/v1/licenses/ernie/{resourceTypeSlug}', [LicenseController::class, 'ernieForResourceType']);

Route::get('/v1/doc', ApiDocController::class);
