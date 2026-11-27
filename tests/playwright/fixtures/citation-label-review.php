<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\IdentifierType;
use App\Models\LandingPage;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\TitleType;
use App\Services\DataCiteApiService;
use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$tag = $argv[2] ?? '';
if (! app()->environment('local', 'testing') || ! Str::isUuid($tag)) {
    throw new RuntimeException('Citation fixtures require a local test environment and a unique fixture ID.');
}
$doi = '10.1234/browser-citation-review.'.$tag;
$paper = '10.1234/browser-paper.'.$tag;
$key = CacheKey::DOI_CITATION;
$cache = $key->repository();
if ($argv[1] === 'clean') {
    Resource::where('doi', $doi)->get()->each->delete();
    $cache->forget($key->key($paper));
    exit;
}
if ($argv[1] !== 'create') {
    throw new RuntimeException('Unknown citation fixture action.');
}
$metadata = json_decode(file_get_contents(base_path('tests/fixtures/citations/ordinary-water.json')), true, flags: JSON_THROW_ON_ERROR);
$metadata['DOI'] = $paper;
$data = DB::transaction(function () use ($doi, $paper, $tag, $metadata): array {
    $old = app(DataCiteApiService::class)->buildCitationFromMetadata($metadata);
    $resource = Resource::factory()->create(['doi' => $doi]);
    $resource->titles()->create(['title_type_id' => TitleType::where('slug', 'MainTitle')->firstOrFail()->id, 'value' => 'APA browser '.$tag]);
    $row = $resource->relatedIdentifiers()->create([
        'identifier' => $paper, 'identifier_type_id' => IdentifierType::where('slug', 'DOI')->firstOrFail()->id,
        'relation_type_id' => RelationType::where('slug', 'References')->firstOrFail()->id, 'position' => 0, 'citation_label' => $old,
    ]);
    $page = LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'slug' => 'apa-browser', 'doi_prefix' => $doi]);
    app(ResourceListingProjectorService::class)->refreshMany([$resource->id]);

    return ['id' => $resource->id, 'row' => $row->id, 'path' => $page->getPublicPath(), 'old' => $old, 'doi' => $paper];
});
// On file-backed stacks, the resource's after-commit callbacks clear the entire cache.
$cache->put($key->key($paper), $metadata, 600);
echo json_encode($data, JSON_THROW_ON_ERROR);
