<?php

declare(strict_types=1);

use App\Models\ResourceType;
use App\Services\LegacyResourceTypeResolverService;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->seed(ResourceTypeSeeder::class);
});

it('resolves every configured resource type without relying on database ids', function () {
    $resolver = new LegacyResourceTypeResolverService;
    $audiovisualType = ResourceType::query()->where('slug', 'audiovisual')->firstOrFail();
    $datasetType = ResourceType::query()->where('slug', 'dataset')->firstOrFail();

    expect($audiovisualType->id)->toBe(1)
        ->and($datasetType->id)->not->toBe($audiovisualType->id);

    ResourceType::query()->each(function (ResourceType $resourceType) use ($resolver): void {
        expect($resolver->resolveId($resourceType->name))->toBe($resourceType->id)
            ->and($resolver->resolveId($resourceType->slug))->toBe($resourceType->id)
            ->and($resolver->resolveId($resourceType->dataciteResourceTypeGeneral()))->toBe($resourceType->id);
    });
});

it('normalizes common legacy spelling variants', function () {
    $resolver = new LegacyResourceTypeResolverService;
    $journalArticleTypeId = ResourceType::query()->where('slug', 'journal-article')->value('id');
    $physicalObjectTypeId = ResourceType::query()->where('slug', 'physical-object')->value('id');

    expect($resolver->resolveId('JournalArticle'))->toBe($journalArticleTypeId)
        ->and($resolver->resolveId(' journal article '))->toBe($journalArticleTypeId)
        ->and($resolver->resolveId('JOURNAL-ARTICLE'))->toBe($journalArticleTypeId)
        ->and($resolver->resolveId('journal_article'))->toBe($journalArticleTypeId)
        ->and($resolver->resolveId('PhysicalObject'))->toBe($physicalObjectTypeId)
        ->and($resolver->resolveId('physical object'))->toBe($physicalObjectTypeId);
});

it('uses Dataset only for an empty legacy type', function () {
    $resolver = new LegacyResourceTypeResolverService;
    $datasetTypeId = ResourceType::query()->where('slug', 'dataset')->value('id');

    expect($resolver->resolveId(null))->toBe($datasetTypeId)
        ->and($resolver->resolveId(''))
        ->toBe($datasetTypeId)
        ->and($resolver->resolveId('   '))->toBe($datasetTypeId);
});

it('maps an unknown explicit legacy type to Other and records a warning', function () {
    Log::spy();

    $otherTypeId = ResourceType::query()->where('slug', 'other')->value('id');
    $resolvedId = (new LegacyResourceTypeResolverService)->resolveId('Legacy Type Without DataCite Equivalent');

    expect($resolvedId)->toBe($otherTypeId);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Unknown legacy resource type mapped to Other', [
            'legacy_resource_type' => 'Legacy Type Without DataCite Equivalent',
        ]);
});

it('fails explicitly when the required fallback type is not configured', function () {
    ResourceType::query()->where('slug', 'other')->delete();

    expect(fn (): int => (new LegacyResourceTypeResolverService)->resolveId('Unknown Type'))
        ->toThrow(RuntimeException::class, "The ERNIE resource type 'other' is required for legacy imports.");
});
