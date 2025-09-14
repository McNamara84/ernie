<?php

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

it('renders the API documentation with Swagger UI', function () {
    get('/api/v1/doc')
        ->assertOk()
        ->assertSee('<title>API Documentation</title>', false)
        ->assertSee('<main id="main-content">', false)
        ->assertSee('id="swagger-ui"', false)
        ->assertDontSee('unpkg.com');
});

  it('returns the OpenAPI documentation as JSON', function () {
      getJson('/api/v1/doc')
          ->assertOk()
          ->assertJsonPath('openapi', '3.1.0')
          ->assertJsonPath('tags.0.name', 'Editor Configuration')
          ->assertJsonPath('paths./api/v1/resource-types.get.tags.0', 'Editor Configuration')
          ->assertJsonPath('paths./api/v1/resource-types/elmo.get.tags.0', 'Editor Configuration')
          ->assertJsonPath('paths./api/v1/resource-types/ernie.get.tags.0', 'Editor Configuration')
          ->assertJsonPath('paths./api/v1/title-types.get.tags.0', 'Editor Configuration')
          ->assertJsonPath('paths./api/v1/title-types/elmo.get.tags.0', 'Editor Configuration')
          ->assertJsonPath('paths./api/v1/title-types/ernie.get.tags.0', 'Editor Configuration')
          ->assertJsonPath('paths./api/v1/languages.get.tags.0', 'Editor Configuration')
          ->assertJsonPath('paths./api/v1/languages/elmo.get.tags.0', 'Editor Configuration')
          ->assertJsonPath('paths./api/v1/languages/ernie.get.tags.0', 'Editor Configuration')
          ->assertJsonPath('paths./api/v1/resource-types/elmo.get.summary', 'List resource types enabled for ELMO')
          ->assertJsonPath('paths./api/v1/title-types/elmo.get.summary', 'List title types enabled for ELMO')
          ->assertJsonPath('paths./api/v1/languages/elmo.get.summary', 'List languages enabled for ELMO')
          ->assertJsonPath('paths./api/v1/resource-types.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/ElmoResourceType')
          ->assertJsonPath('paths./api/v1/resource-types/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/ElmoResourceType')
          ->assertJsonPath('paths./api/v1/resource-types/ernie.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/ElmoResourceType')
          ->assertJsonPath('paths./api/v1/title-types.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/TitleType')
          ->assertJsonPath('paths./api/v1/title-types/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/TitleType')
          ->assertJsonPath('paths./api/v1/title-types/ernie.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/TitleType')
          ->assertJsonPath('paths./api/v1/languages.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Language')
          ->assertJsonPath('paths./api/v1/languages/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Language')
          ->assertJsonPath('paths./api/v1/languages/ernie.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Language')
        ->assertJsonMissingPath('components.schemas.ResourceType')
        ->assertJsonMissingPath('components.schemas.ErnieResourceType')
        ->assertJsonPath('components.schemas.ElmoResourceType.properties.id.type', 'integer')
        ->assertJsonPath('components.schemas.ElmoResourceType.properties.name.type', 'string')
        ->assertJsonPath('components.schemas.TitleType.properties.slug.type', 'string')
        ->assertJsonPath('components.schemas.Language.properties.code.type', 'string');
});

it('returns 500 when the OpenAPI file is missing (JSON)', function () {
    $path = resource_path('data/openapi.json');
    $backup = $path.'.bak';
    rename($path, $backup);

    try {
        getJson('/api/v1/doc')
            ->assertStatus(500)
            ->assertJson(['message' => 'API specification unavailable']);
    } finally {
        rename($backup, $path);
    }
});

it('returns 500 when the OpenAPI file is missing (HTML)', function () {
    $path = resource_path('data/openapi.json');
    $backup = $path.'.bak';
    rename($path, $backup);

    try {
        get('/api/v1/doc')
            ->assertStatus(500)
            ->assertSee('API documentation unavailable');
    } finally {
        rename($backup, $path);
    }
});

it('returns 500 when the OpenAPI file contains invalid JSON', function () {
    $path = resource_path('data/openapi.json');
    $backup = $path.'.bak';
    rename($path, $backup);
    file_put_contents($path, '{invalid');

    try {
        getJson('/api/v1/doc')
            ->assertStatus(500)
            ->assertJson(['message' => 'API specification unavailable']);
    } finally {
        unlink($path);
        rename($backup, $path);
    }
});
