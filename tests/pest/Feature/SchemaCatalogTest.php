<?php

declare(strict_types=1);

use App\Http\Controllers\SchemaCatalogController;
use Inertia\Testing\AssertableInertia as Assert;

covers(SchemaCatalogController::class);

test('offers an anonymous catalogue whose metadata documentation and revision files are reachable', function () {
    $response = $this->get('/schemas/docs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('schemas/catalog')->where('auth.user', null)
        ->where('catalog.locale', 'en')->has('catalog.entries', 3));

    foreach ($response->inertiaProps('catalog.entries') as $entry) {
        $this->get($entry['documentationUrl'])->assertOk();
        $this->get($entry['fixedArtifactUrl'])->assertOk();
        $this->get($entry['manifestUrl'])->assertOk();
    }

    expect($response->inertiaProps('catalog'))->not->toHaveKey('guidance')->not->toHaveKey('supporting');
});

test('follows the configured published revision and falls back to English for the catalogue', function () {
    config(['app.locale' => 'de', 'igsn_core.revision' => 'v2']);

    $this->get('/schemas/docs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('catalog.locale', 'en')
        ->where('catalog.entries.0.revision', 'v2')
        ->where('catalog.entries.0.fixedArtifactUrl', url('/schemas/igsn/2.0/v2/core.xsd'))
        ->where('catalog.entries.1.revision', config('datacite.schemas.revision')));
});
