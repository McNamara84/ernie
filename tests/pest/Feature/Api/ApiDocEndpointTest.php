<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

function containsArrayKeyRecursively(mixed $value, string $key): bool
{
    if (! is_array($value)) {
        return false;
    }

    if (array_key_exists($key, $value)) {
        return true;
    }

    foreach ($value as $nestedValue) {
        if (containsArrayKeyRecursively($nestedValue, $key)) {
            return true;
        }
    }

    return false;
}

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
    $response = getJson('/api/v1/doc')
        ->assertOk();

    $spec = $response->json();

    $response
        ->assertJsonPath('openapi', '3.2.0')
        ->assertJsonPath('info.summary', 'Read-only metadata, vocabulary, and citation endpoints for ERNIE integrations.')
        ->assertJsonPath('servers.0.name', 'Current ERNIE deployment')
        ->assertJsonPath('security', [])
        ->assertJsonPath('tags.0.name', 'Editor Configuration')
        ->assertJsonPath('tags.1.name', 'Vocabularies')
        ->assertJsonPath('tags.0.summary', 'Metadata types and editor option endpoints')
        ->assertJsonPath('tags.3.name', 'IGSN Imports')
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
        ->assertJsonPath('paths./api/v1/vocabularies/msl-laboratories.get.tags.0', 'Vocabularies')
        ->assertJsonPath('paths./api/v1/vocabularies/msl-laboratories.get.security.0.ElmoApiKey', [])
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
        ->assertJsonPath('paths./api/v1/vocabularies/msl-laboratories.get.summary', 'Get MSL laboratories vocabulary')
        ->assertJsonPath('paths./api/v1/vocabularies/msl-laboratories.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/MslLaboratoriesResponse')
        ->assertJsonPath('paths./api/v1/vocabularies/msl-laboratories.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/vocabularies/msl-laboratories.get.responses.404.description', 'Thesaurus is disabled or the local vocabulary file is missing')
        ->assertJsonPath('paths./api/v1/vocabularies/msl-laboratories.get.responses.500.description', 'The local vocabulary file is unreadable or corrupted')
        // ROR affiliations endpoint
        ->assertJsonPath('paths./api/v1/ror-affiliations/elmo.get.tags.0', 'Vocabularies')
        ->assertJsonPath('paths./api/v1/ror-affiliations/elmo.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/ror-affiliations/elmo.get.summary', 'Get ROR affiliations for ELMO')
        ->assertJsonPath('paths./api/v1/ror-affiliations/elmo.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/RorAffiliations')
        ->assertJsonPath('paths./api/v1/ror-affiliations/elmo.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/ror-affiliations/elmo.get.responses.404.description', 'ROR is disabled or data file not found')
        // RAiD projects endpoint
        ->assertJsonPath('paths./api/v1/vocabularies/raid-projects.get.tags.0', 'Vocabularies')
        ->assertJsonPath('paths./api/v1/vocabularies/raid-projects.get.security.0.ElmoApiKey', [])
        ->assertJsonPath('paths./api/v1/vocabularies/raid-projects.get.summary', 'Get RAiD projects for ELMO')
        ->assertJsonPath('paths./api/v1/vocabularies/raid-projects.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/RaidProjects')
        ->assertJsonPath('paths./api/v1/vocabularies/raid-projects.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/vocabularies/raid-projects.get.responses.404.description', 'RAiD is disabled or data file not found')
        // Authenticated IGSN import endpoint
        ->assertJsonPath('paths./igsns/import/start-single.post.tags.0', 'IGSN Imports')
        ->assertJsonPath('paths./igsns/import/start-single.post.requestBody.content.application/json.schema.$ref', '#/components/schemas/SingleIgsnImportRequest')
        ->assertJsonPath('paths./igsns/import/start-single.post.responses.200.content.application/json.schema.$ref', '#/components/schemas/IgsnImportStartResponse')
        ->assertJsonPath('paths./igsns/import/start-single.post.responses.403.$ref', '#/components/responses/ForbiddenError')
        ->assertJsonPath('paths./igsns/import/start-single.post.responses.422.content.application/json.schema.$ref', '#/components/schemas/ValidationErrorResponse')
        ->assertJsonPath('paths./igsns/import/start-single.post.responses.503.content.application/json.schema.$ref', '#/components/schemas/MessageResponse')
        // PID schemas
        ->assertJsonPath('components.schemas.RaidProjects.description', 'Public RAiD project records with metadata wrapper')
        ->assertJsonPath('components.schemas.RaidProject.properties.raidId.type.0', 'string')
        ->assertJsonPath('components.schemas.RaidProject.properties.raidId.type.1', 'null')
        ->assertJsonPath('components.schemas.RaidProject.properties.raidId.format', 'uri')
        ->assertJsonPath('components.schemas.RaidProject.properties.url.type.0', 'string')
        ->assertJsonPath('components.schemas.RaidProject.properties.url.type.1', 'null')
        ->assertJsonPath('components.schemas.RaidProject.properties.url.format', 'uri')
        ->assertJsonPath('components.schemas.PidAvailability.example.raid.displayName', 'RAiD (Research Activity Identifier)')
        ->assertJsonPath('components.schemas.RorAffiliations.description', 'ROR affiliations data with metadata wrapper')
        ->assertJsonPath('components.schemas.MslLaboratoriesResponse.additionalProperties', false)
        ->assertJsonPath('components.schemas.MslLaboratoriesResponse.properties.version.type', 'string')
        ->assertJsonPath('components.schemas.MslLaboratoriesResponse.properties.version.pattern', '^\\d+(?:\\.\\d+)+$')
        ->assertJsonPath('components.schemas.MslLaboratoriesResponse.properties.lastUpdated.format', 'date-time')
        ->assertJsonPath('components.schemas.MslLaboratoriesResponse.properties.total.minimum', 1)
        ->assertJsonPath('components.schemas.MslLaboratoriesResponse.properties.data.minItems', 1)
        ->assertJsonPath('components.schemas.MslLaboratoriesResponse.properties.data.items.$ref', '#/components/schemas/MslLaboratory')
        ->assertJsonPath('components.schemas.MslLaboratory.additionalProperties', false)
        ->assertJsonPath('components.schemas.MslLaboratory.properties.identifier.type', 'string')
        ->assertJsonPath('components.schemas.MslLaboratory.properties.identifier.minLength', 1)
        ->assertJsonPath('components.schemas.MslLaboratory.properties.display_name.type', 'string')
        ->assertJsonPath('components.schemas.MslLaboratory.properties.affiliation_ror.type.0', 'string')
        ->assertJsonPath('components.schemas.MslLaboratory.properties.affiliation_ror.type.1', 'null')
        ->assertJsonPath('components.schemas.MslLaboratory.properties.affiliation_ror.pattern', '^https://ror\\.org/[0-9A-Za-z]{9}$')
        ->assertJsonPath('components.schemas.MslLaboratory.properties.scientific_domain.type', 'string')
        ->assertJsonPath('components.schemas.MslLaboratory.properties.country.type', 'string')
        ->assertJsonMissingPath('components.schemas.MslLaboratoriesResponse.properties.source')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/ElmoResourceType')
        ->assertJsonPath('paths./api/v1/resource-types/elmo.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/title-types/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/TitleType')
        ->assertJsonPath('paths./api/v1/title-types/elmo.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/licenses/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/License')
        ->assertJsonPath('paths./api/v1/licenses/elmo.get.responses.200.content.application/json.example.0.uri', 'https://creativecommons.org/licenses/by/4.0/')
        ->assertJsonPath('paths./api/v1/licenses/elmo.get.responses.200.content.application/json.example.0.scheme_uri', 'https://spdx.org/licenses/')
        ->assertJsonPath('paths./api/v1/licenses/elmo/{resourceTypeSlug}.get.responses.200.content.application/json.example.0.uri', 'https://spdx.org/licenses/MIT.html')
        ->assertJsonPath('paths./api/v1/licenses/elmo/{resourceTypeSlug}.get.responses.200.content.application/json.example.0.scheme_uri', 'https://spdx.org/licenses/')
        ->assertJsonPath('components.schemas.License.properties.uri.type.0', 'string')
        ->assertJsonPath('components.schemas.License.properties.uri.type.1', 'null')
        ->assertJsonPath('components.schemas.License.properties.uri.format', 'uri')
        ->assertJsonPath('components.schemas.License.properties.scheme_uri.type.0', 'string')
        ->assertJsonPath('components.schemas.License.properties.scheme_uri.type.1', 'null')
        ->assertJsonPath('components.schemas.License.properties.scheme_uri.format', 'uri')
        ->assertJsonPath('components.schemas.License.required.3', 'uri')
        ->assertJsonPath('components.schemas.License.required.4', 'scheme_uri')
        ->assertJsonPath('paths./api/v1/licenses/elmo.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/languages/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Language')
        ->assertJsonPath('paths./api/v1/languages/elmo.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-science-keywords.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/GcmdScienceKeywords')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-science-keywords.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-science-keywords.get.responses.404.description', 'Vocabulary file not found')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-platforms.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/GcmdPlatforms')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-platforms.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-platforms.get.responses.404.description', 'Vocabulary file not found')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-instruments.get.responses.200.content.application/json.schema.$ref', '#/components/schemas/GcmdInstruments')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-instruments.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/vocabularies/gcmd-instruments.get.responses.404.description', 'Vocabulary file not found')
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/authors/elmo.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/contributor-persons/elmo.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.responses.200.content.application/json.schema.items.$ref', '#/components/schemas/Role')
        ->assertJsonPath('paths./api/v1/roles/contributor-institutions/elmo.get.responses.401.$ref', '#/components/responses/UnauthorizedError')
        // Verify the UnauthorizedError component is properly defined
        ->assertJsonPath('components.responses.UnauthorizedError.description', 'Authentication failed. Either the API key is invalid/missing, or the server has no API key configured.')
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
        ->assertJsonPath('components.securitySchemes.LaravelSession.in', 'cookie')
        ->assertJsonPath('components.securitySchemes.CsrfToken.name', 'X-CSRF-TOKEN')
        ->assertJsonPath('components.schemas.Role.properties.slug.type', 'string')
        ->assertJsonPath('components.schemas.ElmoResourceType.properties.id.type', 'integer')
        ->assertJsonPath('components.schemas.ElmoResourceType.properties.name.type', 'string')
        ->assertJsonPath('components.schemas.TitleType.properties.slug.type', 'string')
        ->assertJsonPath('components.schemas.Language.properties.code.type', 'string')
        ->assertJsonMissingPath('paths./api/v1/citation-lookup.get.responses.200.content.application/json.schema.properties.subtitle.nullable')
        ->assertJsonMissingPath('components.schemas.DateType.properties.description.nullable')
        ->assertJsonPath('components.schemas.GcmdScienceKeywords.description', 'GCMD Science Keywords from NASA Knowledge Management System')
        ->assertJsonPath('components.schemas.GcmdPlatforms.description', 'GCMD Platforms from NASA Knowledge Management System')
        ->assertJsonPath('components.schemas.GcmdInstruments.description', 'GCMD Instruments from NASA Knowledge Management System');

    expect(data_get($spec, 'paths./api/v1/citation-lookup.get.responses.200.content.application/json.schema.properties.subtitle.type'))
        ->toBeArray()
        ->toContain('string', 'null');

    expect(data_get($spec, 'components.schemas.DateType.properties.description.type'))
        ->toBeArray()
        ->toContain('string', 'null');
});

it('serves an OpenAPI 3.2 document without legacy nullable keywords', function () {
    $spec = getJson('/api/v1/doc')
        ->assertOk()
        ->json();

    expect($spec['openapi'])->toBe('3.2.0')
        ->and($spec['info']['license'])->not->toHaveKey('url')
        ->and(containsArrayKeyRecursively($spec, 'nullable'))->toBeFalse();
});

it('returns 500 when the OpenAPI file is missing (JSON)', function () {
    File::shouldReceive('exists')
        ->once()
        ->with(resource_path('data/openapi.json'))
        ->andReturn(false);

    getJson('/api/v1/doc')
        ->assertStatus(500)
        ->assertJson(['message' => 'API specification unavailable']);
});

it('returns 500 when the OpenAPI file is missing (HTML)', function () {
    File::shouldReceive('exists')
        ->once()
        ->with(resource_path('data/openapi.json'))
        ->andReturn(false);

    get('/api/v1/doc')
        ->assertStatus(500)
        ->assertSee('API documentation unavailable');
});

it('returns 500 when the OpenAPI file contains invalid JSON', function () {
    File::shouldReceive('exists')
        ->once()
        ->with(resource_path('data/openapi.json'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->once()
        ->with(resource_path('data/openapi.json'))
        ->andReturn('{invalid');

    getJson('/api/v1/doc')
        ->assertStatus(500)
        ->assertJson(['message' => 'API specification unavailable']);
});

it('dynamically replaces URLs with current APP_URL', function () {
    // Mirror the same normalization as the controller (trim trailing slashes)
    $appUrl = rtrim((string) config('app.url'), '/');

    getJson('/api/v1/doc')
        ->assertOk()
        ->assertJsonPath('servers.0.url', $appUrl)
        ->assertJsonPath('info.termsOfService', $appUrl.'/legal-notice');
});
