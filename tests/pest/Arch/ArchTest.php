<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture Tests
|--------------------------------------------------------------------------
|
| Enforce coding standards and architectural rules across the codebase.
| These tests run fast (no database) and catch structural issues early.
|
*/

describe('Strict Types', function () {
    arch('all PHP files use strict types')
        ->expect('App')
        ->toUseStrictTypes();
});

describe('Controllers', function () {
    arch('controllers extend nothing or base Controller')
        ->expect('App\Http\Controllers')
        ->toExtend('App\Http\Controllers\Controller')
        ->ignoring('App\Http\Controllers\Controller');

    arch('controllers have correct suffix')
        ->expect('App\Http\Controllers')
        ->toHaveSuffix('Controller');

    arch('controllers are not used by models')
        ->expect('App\Http\Controllers')
        ->not->toBeUsedIn('App\Models');
});

describe('Models', function () {
    arch('models extend Eloquent Model')
        ->expect('App\Models')
        ->toExtend('Illuminate\Database\Eloquent\Model');

    arch('models are not using controllers')
        ->expect('App\Models')
        ->not->toUse('App\Http\Controllers');
});

describe('Services', function () {
    arch('services have correct suffix')
        ->expect('App\Services')
        ->toHaveSuffix('Service')
        ->ignoring([
            'App\Services\DataCiteJsonExporter',
            'App\Services\DataCiteXmlExporter',
            'App\Services\DataCiteLinkedDataExporter',
            'App\Services\SchemaOrgJsonLdExporter',
            'App\Services\DataCiteToResourceTransformer',
            'App\Services\DataCiteToIgsnTransformer',
            'App\Services\IgsnDifXmlParser',
            'App\Services\Editor\EditorDataTransformer',
            'App\Services\LandingPageResourceTransformer',
            'App\Services\MslKeywordTransformer',
            'App\Services\OldDatasetKeywordTransformer',
            'App\Services\DataCiteXmlValidator',
            'App\Services\DataCiteServiceInterface',
            'App\Services\Traits\DataCiteExporterHelpers',
            'App\Services\OldDatasetEditorLoader',
            'App\Services\JsonSchemaValidator',
            'App\Services\DataCiteSyncResult',
            'App\Services\OaiPmh\OaiPmhXmlResponseBuilder',
            'App\Services\OaiPmh\DublinCoreMapper',
            'App\Services\Assistance\AssistantManifest',
            'App\Services\Assistance\AssistantContract',
            'App\Services\Assistance\AbstractAssistant',
            'App\Services\Assistance\GenericTableAssistant',
            'App\Services\Assistance\AssistantRegistrar',
            'App\Services\Orcid\OrcidPreflightIssue',
            'App\Services\Orcid\OrcidPreflightResult',
            'App\Services\Orcid\OrcidPreflightValidator',
            'App\Services\Citations\CitationFormatter',
            'App\Services\Citations\CitationLookupResult',
            'App\Services\Citations\CrossrefClient',
            'App\Services\Citations\CrossrefTypeMapper',
            'App\Services\Citations\DataCiteTypeMapper',
            'App\Services\Xml\DataCiteXmlImportParser',
            'App\Services\Xml\DataCiteXmlImportResult',
            'App\Services\Xml\Sections\AuthorSectionParser',
            'App\Services\Xml\Sections\ContributorSectionParser',
            'App\Services\Xml\Sections\CoverageSectionParser',
            'App\Services\Xml\Sections\DateSectionParser',
            'App\Services\Xml\Sections\DescriptionSectionParser',
            'App\Services\Xml\Sections\FundingReferenceSectionParser',
            'App\Services\Xml\Sections\GcmdKeywordSectionParser',
            'App\Services\Xml\Sections\IdentifierSectionParser',
            'App\Services\Xml\Sections\IsoContactSectionParser',
            'App\Services\Xml\Sections\RelatedItemSectionParser',
            'App\Services\Xml\Sections\RelatedWorkAndInstrumentSectionParser',
            'App\Services\Xml\Sections\RightsSectionParser',
            'App\Services\Xml\Sections\TitleSectionParser',
        ]);

    arch('services are not extending controllers')
        ->expect('App\Services')
        ->not->toExtend('App\Http\Controllers\Controller');
});

describe('Enums', function () {
    arch('enums are backed enums')
        ->expect('App\Enums')
        ->toBeEnums();
});

describe('Jobs', function () {
    arch('jobs implement ShouldQueue')
        ->expect('App\Jobs')
        ->toImplement('Illuminate\Contracts\Queue\ShouldQueue');
});

describe('Mail', function () {
    arch('mailables extend Mailable')
        ->expect('App\Mail')
        ->toExtend('Illuminate\Mail\Mailable');
});

describe('Policies', function () {
    arch('policies have correct suffix')
        ->expect('App\Policies')
        ->toHaveSuffix('Policy');
});

describe('Observers', function () {
    arch('observers have correct suffix')
        ->expect('App\Observers')
        ->toHaveSuffix('Observer');
});

describe('Value Objects & Support', function () {
    arch('support classes have no dependencies on controllers')
        ->expect('App\Support')
        ->not->toUse('App\Http\Controllers');
});

describe('Dependency Rules', function () {
    arch('models do not depend on HTTP layer')
        ->expect('App\Models')
        ->not->toUse([
            'Illuminate\Http\Request',
            'Illuminate\Http\JsonResponse',
        ]);

    arch('enums do not depend on Eloquent')
        ->expect('App\Enums')
        ->not->toUse('Illuminate\Database\Eloquent');
});

describe('No Debugging Code', function () {
    arch('no dd() or dump() in production code')
        ->expect('App')
        ->not->toUse(['dd', 'dump', 'ray']);
});

describe('Citation Manager', function () {
    // Keep HTTP access isolated to CrossrefClient. All other services in
    // App\Services\Citations must stay pure (no Guzzle, no Http facade,
    // no Http\Client\Factory) to remain trivially unit-testable.
    arch('only CrossrefClient performs outbound HTTP calls')
        ->expect('App\Services\Citations')
        ->not->toUse([
            'Illuminate\Http\Client\Factory',
            'Illuminate\Http\Client\PendingRequest',
            'Illuminate\Support\Facades\Http',
            'GuzzleHttp\Client',
        ])
        ->ignoring('App\Services\Citations\CrossrefClient');

    arch('citation manager models extend Eloquent Model')
        ->expect([
            'App\Models\RelatedItem',
            'App\Models\RelatedItemTitle',
            'App\Models\RelatedItemCreator',
            'App\Models\RelatedItemCreatorAffiliation',
            'App\Models\RelatedItemContributor',
            'App\Models\RelatedItemContributorAffiliation',
        ])->toExtend('Illuminate\Database\Eloquent\Model');

    arch('citation manager services do not depend on controllers')
        ->expect('App\Services\Citations')
        ->not->toUse('App\Http\Controllers');
});
