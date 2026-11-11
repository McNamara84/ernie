<?php

use App\Enums\AccessLevel;
use App\Models\Description;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Right;
use App\Models\Title;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function completeDataCiteFixture(Resource $resource): void
{
    $resource->update(['access_level' => AccessLevel::OPEN]);
    Title::factory()->create(['resource_id' => $resource->id]);
    Description::factory()->create(['resource_id' => $resource->id]);
    ResourceCreator::factory()->create([
        'resource_id' => $resource->id,
        'creatorable_id' => Person::factory()->create()->id,
    ]);
    $resource->rights()->attach(Right::factory()->create());
}

/** @return array<string, mixed> */
function dataCiteRecord(string $doi, string $state = 'findable', string $repository = 'test.repo', array $attributes = []): array
{
    return ['data' => [
        'id' => $doi, 'type' => 'dois',
        'attributes' => [...$attributes, 'doi' => $doi, 'state' => $state],
        'relationships' => ['client' => ['data' => ['id' => $repository, 'type' => 'clients']]],
    ]];
}

/** A stateful member API fixture: registration is seeded independently of local DOIs. */
function fakeIgsnMemberApi(array $registered = []): void
{
    Http::fake(function (Request $request) use (&$registered) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }
        $doi = $request['data']['attributes']['doi'] ?? rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));
        $repository = str_contains($request->url(), 'api.test.') ? 'test.repo'
            : (str_starts_with($doi, '10.60510/') ? 'igsn.repo' : 'prod.repo');
        if ($request->method() === 'GET') {
            return isset($registered[$doi])
                ? Http::response(dataCiteRecord($doi, $registered[$doi], $repository))
                : Http::response([], 404);
        }
        $state = ($request['data']['attributes']['event'] ?? null) === 'publish' ? 'findable' : ($registered[$doi] ?? 'draft');
        $registered[$doi] = $state;

        return Http::response(dataCiteRecord($doi, $state, $repository, $request['data']['attributes']), 201);
    });
}
