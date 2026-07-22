<?php

declare(strict_types=1);

use App\Models\ThesaurusSetting;
use App\Services\MslLaboratoryVocabularyService;
use App\Services\ThesaurusStatusService;

covers(ThesaurusStatusService::class);

function mslStatusPayload(
    string $version,
    string $sha,
    int $total,
    string $lastUpdated = '2026-07-21T12:00:00+00:00'
): array {
    return [
        'version' => $version,
        'lastUpdated' => $lastUpdated,
        'total' => $total,
        'source' => [
            'repository' => 'UtrechtUniversity/msl_vocabularies',
            'ref' => 'main',
            'path' => "vocabularies/labs/{$version}/laboratories.json",
            'sha' => $sha,
        ],
        'data' => [],
    ];
}

function mslStatusSetting(): ThesaurusSetting
{
    return new ThesaurusSetting([
        'type' => ThesaurusSetting::TYPE_MSL_LABORATORIES,
        'display_name' => 'MSL Laboratories',
    ]);
}

it('marks a missing local vocabulary as update available', function (): void {
    $vocabulary = Mockery::mock(MslLaboratoryVocabularyService::class);
    $vocabulary->shouldReceive('getLocalPayload')->once()->andReturnNull();
    $vocabulary->shouldReceive('fetchLatest')->once()
        ->andReturn(mslStatusPayload('1.1', 'remote-sha', 119));

    $comparison = (new ThesaurusStatusService($vocabulary))
        ->compareWithRemote(mslStatusSetting());

    expect($comparison)
        ->toMatchArray([
            'localCount' => 0,
            'remoteCount' => 119,
            'updateAvailable' => true,
            'localVersion' => null,
            'remoteVersion' => '1.1',
            'updateReason' => 'missing_local',
        ]);
});

it('is up to date only when version and SHA both match', function (): void {
    $local = mslStatusPayload('1.1', 'same-sha', 119);
    $vocabulary = Mockery::mock(MslLaboratoryVocabularyService::class);
    $vocabulary->shouldReceive('getLocalPayload')->once()->andReturn($local);
    $vocabulary->shouldReceive('fetchLatest')->once()->andReturn($local);

    $comparison = (new ThesaurusStatusService($vocabulary))
        ->compareWithRemote(mslStatusSetting());

    expect($comparison['updateAvailable'])->toBeFalse()
        ->and($comparison['updateReason'])->toBeNull()
        ->and($comparison['localVersion'])->toBe('1.1')
        ->and($comparison['remoteVersion'])->toBe('1.1');
});

it('detects a new version even when the item count is unchanged', function (): void {
    $vocabulary = Mockery::mock(MslLaboratoryVocabularyService::class);
    $vocabulary->shouldReceive('getLocalPayload')->once()
        ->andReturn(mslStatusPayload('1.1', 'old-sha', 119));
    $vocabulary->shouldReceive('fetchLatest')->once()
        ->andReturn(mslStatusPayload('1.2', 'new-sha', 119));

    $comparison = (new ThesaurusStatusService($vocabulary))
        ->compareWithRemote(mslStatusSetting());

    expect($comparison['updateAvailable'])->toBeTrue()
        ->and($comparison['updateReason'])->toBe('new_version');
});

it('detects changed content at the same version regardless of a lower count', function (): void {
    $vocabulary = Mockery::mock(MslLaboratoryVocabularyService::class);
    $vocabulary->shouldReceive('getLocalPayload')->once()
        ->andReturn(mslStatusPayload('1.1', 'old-sha', 119));
    $vocabulary->shouldReceive('fetchLatest')->once()
        ->andReturn(mslStatusPayload('1.1', 'new-sha', 118));

    $comparison = (new ThesaurusStatusService($vocabulary))
        ->compareWithRemote(mslStatusSetting());

    expect($comparison['updateAvailable'])->toBeTrue()
        ->and($comparison['remoteCount'])->toBe(118)
        ->and($comparison['updateReason'])->toBe('content_changed');
});

it('exposes local MSL version, SHA, count and timestamp', function (): void {
    $vocabulary = Mockery::mock(MslLaboratoryVocabularyService::class);
    $vocabulary->shouldReceive('getLocalPayload')->once()
        ->andReturn(mslStatusPayload('1.1', 'stored-sha', 119));

    $status = (new ThesaurusStatusService($vocabulary))
        ->getLocalStatus(mslStatusSetting());

    expect($status)->toBe([
        'exists' => true,
        'conceptCount' => 119,
        'lastUpdated' => '2026-07-21T12:00:00+00:00',
        'version' => '1.1',
        'sourceSha' => 'stored-sha',
    ]);
});
