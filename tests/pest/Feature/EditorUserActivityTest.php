<?php

declare(strict_types=1);

use App\Enums\EditorDraftSaveIntent;
use App\Models\Language;
use App\Models\RelatedItem;
use App\Models\RelationType;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use App\Services\Editor\EditorResourceSaveService;
use App\Services\UserActivityService;
use Database\Seeders\ContributorTypeSeeder;
use Database\Seeders\DateTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->actor = User::factory()->admin()->create();
    TitleType::firstOrCreate(['slug' => 'MainTitle'], ['name' => 'Main Title']);
    $this->seed([DateTypeSeeder::class, ContributorTypeSeeder::class]);
    $this->save = app(EditorResourceSaveService::class);
    $this->payload = [
        'titles' => [['title' => 'Autosaved dataset', 'titleType' => 'main-title']],
        'authors' => [['type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'position' => 0,
            'affiliations' => [['value' => 'Example University']]]],
        'contributors' => [['type' => 'person', 'firstName' => 'John', 'lastName' => 'Smith', 'position' => 0,
            'roles' => ['Editor'], 'affiliations' => [['value' => 'Example Institute']]]],
        'dates' => [['dateType' => 'available', 'startDate' => '2026-01-01']],
        'instruments' => [['pid' => '10.1234/sensor', 'pidType' => 'DOI', 'name' => 'Sensor']],
        'spatialTemporalCoverages' => [['type' => 'polygon', 'polygonPoints' => [
            ['longitude' => 1, 'latitude' => 2], ['longitude' => 3, 'latitude' => 4], ['longitude' => 5, 'latitude' => 6],
        ]]],
    ];
});

it('records autosave creation and ignores equivalent replacement rows and generated dates', function () {
    Log::spy();
    [$resource] = $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $this->payload['resourceId'] = $resource->id;
    $this->travel(1)->days();
    $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);

    Log::shouldHaveReceived('info')->once();
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'resource.created');
});

it('records semantic autosave edits without including metadata values', function (string $path, mixed $value, string $field) {
    [$resource] = $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $this->payload['resourceId'] = $resource->id;
    data_set($this->payload, $path, $value);
    Log::spy();
    $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => ($context['activity']['action'] ?? null) === 'resource.metadata_updated'
        && $context['activity']['changed_fields'] === [$field] && ! isset($context['activity']['values']));
})->with([
    'title' => ['titles.0.title', 'Revised dataset', 'titles'],
    'creator name' => ['authors.0.firstName', 'Janet', 'creators'],
    'creator affiliation' => ['authors.0.affiliations.0.value', 'Another University', 'creators'],
    'contributor identity' => ['contributors.0.firstName', 'Jack', 'contributors'],
    'contributor role' => ['contributors.0.roles', ['DataCurator'], 'contributors'],
    'contributor affiliation' => ['contributors.0.affiliations.0.value', 'Another Institute', 'contributors'],
    'date' => ['dates.0.startDate', '2026-02-01', 'dates'],
    'instrument' => ['instruments.0.name', 'Revised sensor', 'instruments'],
    'geometry order' => ['spatialTemporalCoverages.0.polygonPoints', [
        ['longitude' => 5, 'latitude' => 6], ['longitude' => 3, 'latitude' => 4], ['longitude' => 1, 'latitude' => 2],
    ], 'geo_locations'],
    'cleared creators' => ['authors', [], 'creators'],
    'version' => ['version', '2.0', 'version'],
]);

it('records catalog selections while treating numeric string IDs as unchanged', function () {
    [$resource] = $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $this->payload['resourceId'] = $resource->id;
    $this->payload['resourceType'] = ResourceType::factory()->create()->id;
    $this->payload['language'] = Language::firstOrCreate(['code' => 'en'], ['name' => 'English'])->code;
    Log::spy();
    $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $this->payload['resourceType'] = (string) $this->payload['resourceType'];
    $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);

    Log::shouldHaveReceived('info')->once();
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context['activity']['changed_fields'] === ['resource_type', 'language']);
});

it('compares current persisted metadata after a manual save by another user', function () {
    [$resource] = $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $this->payload['resourceId'] = $resource->id;
    $otherPayload = $this->payload;
    $otherPayload['titles'][0]['title'] = 'Changed in another editor';
    $this->save->saveRelaxed($otherPayload, User::factory()->admin()->create(), EditorDraftSaveIntent::SAVE_DRAFT);
    Log::spy();
    $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context['activity']['changed_fields'] === ['titles']
        && $context['activity']['actor']['id'] === $this->actor->id);
});

it('leaves unsubmitted related-item graphs unread during autosaves', function () {
    [$resource] = $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    RelatedItem::factory()->count(20)->create(['resource_id' => $resource->id])->each(function (RelatedItem $item) {
        $item->titles()->create(['title' => 'Preserved citation', 'title_type' => 'MainTitle']);
        $item->creators()->create(['name' => 'Citation author', 'name_type' => 'Personal', 'position' => 0]);
    });
    $this->payload['resourceId'] = $resource->id;
    Log::spy();
    DB::enableQueryLog();
    $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(array_filter($queries, fn (array $query): bool => preg_match('/\b(?:related_items|related_item_\w+|igsn_(?!metadata\b)\w+)\b/i', $query['query']) === 1))->toBe([]);
    expect($resource->relatedItems()->count())->toBe(20);
    Log::shouldNotHaveReceived('info');
});

it('compares explicitly submitted related items and ignores replacement IDs', function () {
    RelationType::firstOrCreate(['slug' => 'Cites'], ['name' => 'Cites']);
    $this->payload['relatedItems'] = [[
        'related_item_type' => 'JournalArticle', 'relation_type_slug' => 'Cites',
        'titles' => [['title' => 'Original citation', 'title_type' => 'MainTitle']],
        'creators' => [['name' => 'Citation author', 'name_type' => 'Personal', 'affiliations' => [['name' => 'Citation institute']]]],
    ]];
    [$resource] = $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $this->payload['resourceId'] = $resource->id;
    Log::spy();
    $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $this->payload['relatedItems'][0]['creators'][0]['affiliations'][0]['name'] = 'Revised citation institute';
    $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);

    Log::shouldHaveReceived('info')->once();
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context['activity']['changed_fields'] === ['related_items']);
});

it('avoids export snapshots and reuses loaded editor relations', function () {
    [$resource] = $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $activities = app(UserActivityService::class);
    DB::enableQueryLog();
    $snapshot = $activities->editorSnapshot($resource);
    $narrowQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    expect($activities->editorSnapshot($resource))->toBe($snapshot);
    expect(DB::getQueryLog())->toBe([]);
    $activities->snapshot($resource);
    $exportQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    $activities->editorSnapshot($resource->fresh());
    $coldNarrowQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($narrowQueries)->toBeLessThan($exportQueries / 2)
        ->and($coldNarrowQueries)->toBeLessThan($exportQueries)
        ->and($snapshot)->not->toHaveKeys(['igsn_metadata', 'alternate_identifiers', 'sizes', 'formats', 'related_items'])
        ->and($snapshot['creators'])->toMatch('/^[a-f0-9]{64}$/');
});

it('does not record autosave activity after a rollback or invalid save', function (bool $invalid) {
    [$resource] = $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $this->payload['resourceId'] = $resource->id;
    $this->payload['titles'][0]['title'] = 'Rolled back title';
    if ($invalid) {
        $this->payload['dates'][0]['dateType'] = 'invalid';
    }
    Log::spy();

    expect(fn () => DB::transaction(function () {
        $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
        throw new RuntimeException('Rollback');
    }))->toThrow($invalid ? ValidationException::class : RuntimeException::class);

    expect($resource->fresh()->main_title)->toBe('Autosaved dataset');
    Log::shouldNotHaveReceived('info');
})->with([false, true]);

it('persists anonymous autosaves without activity snapshots', function () {
    Log::spy();
    [$resource] = $this->save->saveRelaxed($this->payload, null, EditorDraftSaveIntent::AUTOSAVE);
    expect($resource->main_title)->toBe('Autosaved dataset');
    Log::shouldNotHaveReceived('info');
});

it('records removal of editor metadata that storage clears when omitted', function (string $input, string $field) {
    [$resource] = $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $this->payload['resourceId'] = $resource->id;
    unset($this->payload[$input]);
    Log::spy();
    $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context['activity']['changed_fields'] === [$field]);
})->with([['authors', 'creators'], ['contributors', 'contributors'], ['instruments', 'instruments']]);

it('detects raw rights changes and ignores equivalent normalized inputs', function () {
    $this->payload['customLicenses'] = [];
    $this->payload['rawRights'] = [['rights' => 'Original terms']];
    [$resource] = $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $this->payload['resourceId'] = $resource->id;
    $this->payload['rawRights'][0]['rights'] = '  Original terms  ';
    Log::spy();
    $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);
    $this->payload['rawRights'][0]['rights'] = 'Updated terms';
    $this->save->saveRelaxed($this->payload, $this->actor, EditorDraftSaveIntent::AUTOSAVE);

    Log::shouldHaveReceived('info')->once();
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context): bool => $context['activity']['changed_fields'] === ['rights']
        && ! str_contains(json_encode($context), 'Updated terms'));
});
