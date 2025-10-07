<?php

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

it('renders the API documentation with Swagger UI', function () {
    get('/api/v1/doc')
        ->assertOk()
        ->assertSee('<title>API Documentation</title>', false)
        ->assertSee('<main id="main-content"', false)
        ->assertSee('<h1 class="text-3xl font-bold text-zinc-900 dark:text-zinc-50">', false)
        ->assertSee('id="swagger-ui"', false)
        ->assertSee('Interactive API documentation', false)
        ->assertDontSee('unpkg.com');
});

it('returns the OpenAPI documentation as JSON', function () {
    getJson('/api/v1/doc')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('tags.0.name', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/resource-types.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/resource-types/ernie.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/title-types.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/title-types/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/title-types/ernie.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/languages.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/languages/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/languages/ernie.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/roles/authors/ernie.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/roles/authors/ernie.get.security.0.ErnieApiKey', [])
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/ernie.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/ernie.get.security.0.ErnieApiKey', [])
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/ernie.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/ernie.get.security.0.ErnieApiKey', [])
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.summary', 'List resource types enabled for ELMO')
        ->assertJsonPath('paths./api/v1/title-types/elmo.get.summary', 'List title types enabled for ELMO')
        ->assertJsonPath('paths./api/v1/languages/elmo.get.summary', 'List languages enabled for ELMO')
        ->assertJsonPath('paths./api/v1/roles/authors/ernie.get.summary', 'List author roles active for Ernie')
        ->assertJsonPath('paths./api/v1/roles/authors/ernie.get.description', 'Returns all author roles that are active for Ernie. Provide the API key via the `X-API-Key` header or the `api_key` query parameter.')
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.summary', 'List author roles active for ELMO')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/ernie.get.summary', 'List contributor person roles active for Ernie')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/ernie.get.description', 'Returns all contributor person roles that are active for Ernie. Provide the API key via the `X-API-Key` header or the `api_key` query parameter.')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.summary', 'List contributor person roles active for ELMO')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/ernie.get.summary', 'List contributor institution roles active for Ernie')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/ernie.get.description', 'Returns all contributor institution roles that are active for Ernie. Provide the API key via the `X-API-Key` header or the `api_key` query parameter.')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.summary', 'List contributor institution roles active for ELMO')
        ->assertJsonPath('paths./api/v1/resource-types.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/ElmoResourceType')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/ElmoResourceType')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/resource-types/ernie.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/ElmoResourceType')
        ->assertJsonPath('paths./api/v1/title-types.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/TitleType')
        ->assertJsonPath('paths./api/v1/title-types/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/TitleType')
        ->assertJsonPath('paths./api/v1/title-types/ernie.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/TitleType')
        ->assertJsonPath('paths./api/v1/languages.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Language')
        ->assertJsonPath('paths./api/v1/languages/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Language')
        ->assertJsonPath('paths./api/v1/languages/ernie.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Language')
        ->assertJsonPath('paths./api/v1/roles/authors/ernie.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/authors/ernie.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/ernie.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/ernie.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/ernie.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/ernie.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('components.securitySchemes.ElmoApiKey.name', 'X-API-Key')
        ->assertJsonPath('components.securitySchemes.ElmoApiKey.type', 'apiKey')
        ->assertJsonPath('components.securitySchemes.ErnieApiKey.name', 'X-API-Key')
        ->assertJsonPath('components.securitySchemes.ErnieApiKey.type', 'apiKey')
        ->assertJsonMissingPath('components.schemas.ResourceType')
        ->assertJsonMissingPath('components.schemas.ErnieResourceType')
        ->assertJsonPath('components.schemas.Role.properties.slug.type', 'string')
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
