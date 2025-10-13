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
        ->assertJsonPath('tags.1.name', 'Vocabularies')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/title-types/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/title-types/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/licenses/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/licenses/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/languages/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/languages/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.tags.0', 'Editor Configuration')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-science-keywords.get.tags.0', 'Vocabularies')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-science-keywords.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-platforms.get.tags.0', 'Vocabularies')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-platforms.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-instruments.get.tags.0', 'Vocabularies')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-instruments.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.summary', 'List resource types enabled for ELMO')
        ->assertJsonPath('paths./api/v1/title-types/elmo.get.summary', 'List title types enabled for ELMO')
        ->assertJsonPath('paths./api/v1/licenses/elmo.get.summary', 'List licenses enabled for ELMO')
        ->assertJsonPath('paths./api/v1/languages/elmo.get.summary', 'List languages enabled for ELMO')
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.summary', 'List author roles active for ELMO')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.summary', 'List contributor person roles active for ELMO')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.summary', 'List contributor institution roles active for ELMO')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-science-keywords.get.summary', 'Get GCMD Science Keywords vocabulary')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-platforms.get.summary', 'Get GCMD Platforms vocabulary')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-instruments.get.summary', 'Get GCMD Instruments vocabulary')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/ElmoResourceType')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/title-types/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/TitleType')
        ->assertJsonPath('paths./api/v1/title-types/elmo.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/licenses/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/License')
        ->assertJsonPath('paths./api/v1/licenses/elmo.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/languages/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Language')
        ->assertJsonPath('paths./api/v1/languages/elmo.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-science-keywords.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/GcmdScienceKeywords')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-science-keywords.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-science-keywords.get.responses.404.description', 'Vocabulary file not found')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-platforms.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/GcmdPlatforms')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-platforms.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-platforms.get.responses.404.description', 'Vocabulary file not found')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-instruments.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/GcmdInstruments')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-instruments.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-instruments.get.responses.404.description', 'Vocabulary file not found')
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.responses.401.description', 'Invalid or missing API key')
        ->assertJsonMissingPath('paths./api/v1/resource-types.get')
        ->assertJsonMissingPath('paths./api/v1/resource-types/ernie.get')
        ->assertJsonMissingPath('paths./api/v1/title-types.get')
        ->assertJsonMissingPath('paths./api/v1/title-types/ernie.get')
        ->assertJsonMissingPath('paths./api/v1/licenses.get')
        ->assertJsonMissingPath('paths./api/v1/licenses/ernie.get')
        ->assertJsonMissingPath('paths./api/v1/languages.get')
        ->assertJsonMissingPath('paths./api/v1/languages/ernie.get')
        ->assertJsonMissingPath('paths./api/v1/roles/authors/ernie.get')
        ->assertJsonMissingPath('paths./api/v1/roles/contributor-persons/ernie.get')
        ->assertJsonMissingPath('paths./api/v1/roles/contributor-institutions/ernie.get')
        ->assertJsonMissingPath('components.securitySchemes.ErnieApiKey')
        ->assertJsonMissingPath('components.schemas.ResourceType')
        ->assertJsonMissingPath('components.schemas.ErnieResourceType')
        ->assertJsonPath('components.securitySchemes.ElmoApiKey.name', 'X-API-Key')
        ->assertJsonPath('components.securitySchemes.ElmoApiKey.type', 'apiKey')
        ->assertJsonPath('components.schemas.Role.properties.slug.type', 'string')
        ->assertJsonPath('components.schemas.ElmoResourceType.properties.id.type', 'integer')
        ->assertJsonPath('components.schemas.ElmoResourceType.properties.name.type', 'string')
        ->assertJsonPath('components.schemas.TitleType.properties.slug.type', 'string')
        ->assertJsonPath('components.schemas.Language.properties.code.type', 'string')
        ->assertJsonPath('components.schemas.GcmdScienceKeywords.description', 'GCMD Science Keywords from NASA Knowledge Management System')
        ->assertJsonPath('components.schemas.GcmdPlatforms.description', 'GCMD Platforms from NASA Knowledge Management System')
        ->assertJsonPath('components.schemas.GcmdInstruments.description', 'GCMD Instruments from NASA Knowledge Management System');
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
