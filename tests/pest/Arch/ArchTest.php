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
            'App\Services\DataCiteToResourceTransformer',
            'App\Services\Editor\EditorDataTransformer',
            'App\Services\LandingPageResourceTransformer',
            'App\Services\MslKeywordTransformer',
            'App\Services\OldDatasetKeywordTransformer',
            'App\Services\DataCiteXmlValidator',
            'App\Services\DataCiteServiceInterface',
            'App\Services\Traits\DataCiteExporterHelpers',
            'App\Services\OldDatasetEditorLoader',
            'App\Services\JsonSchemaValidator',
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
