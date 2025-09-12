<?php

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

it('renders the API documentation with Swagger UI', function () {
    get('/api/v1/doc')
        ->assertOk()
        ->assertSee('<title>API Documentation</title>', false)
        ->assertSee('id="swagger-ui"', false)
        ->assertDontSee('unpkg.com');
});

it('returns the OpenAPI documentation as JSON', function () {
    getJson('/api/v1/doc')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.summary', 'List resource types enabled for ELMO')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/ElmoResourceType')
        ->assertJsonPath('components.schemas.ElmoResourceType.properties.id.type', 'integer')
        ->assertJsonPath('components.schemas.ElmoResourceType.properties.name.type', 'string');
});
