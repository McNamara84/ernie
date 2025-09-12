<?php

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

it('renders the API documentation with Swagger UI', function () {
    get('/api/v1/doc')
        ->assertOk()
        ->assertSee('<title>API Documentation</title>', false)
        ->assertSee('SwaggerUIBundle', false);
});

it('returns the OpenAPI documentation as JSON', function () {
    getJson('/api/v1/doc')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.summary', 'List resource types enabled for ELMO');
});
