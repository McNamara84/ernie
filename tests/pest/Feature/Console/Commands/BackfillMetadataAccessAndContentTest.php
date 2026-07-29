<?php

declare(strict_types=1);

use App\Console\Commands\BackfillMetadataAccessAndContent;
use App\Enums\AccessLevel;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

covers(BackfillMetadataAccessAndContent::class);

test('command defaults to dry run writes a review CSV and applies only on request', function (): void {
    $resource = Resource::factory()->create(['access_level' => null]);
    $physicalObject = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );
    $unknownIgsn = Resource::factory()->create([
        'resource_type_id' => $physicalObject->id,
        'access_level' => null,
    ]);
    IgsnMetadata::create([
        'resource_id' => $unknownIgsn->id,
        'sample_access' => 'by appointment',
    ]);

    $reportPath = storage_path('framework/testing/access-content-review-'.Str::uuid().'.csv');

    try {
        $this->artisan('metadata:backfill-access-content', ['--report' => $reportPath])
            ->expectsOutputToContain('Dry run only; no data was changed.')
            ->expectsOutputToContain('Records requiring manual review:')
            ->assertExitCode(Command::SUCCESS);

        expect($resource->fresh()->access_level)->toBeNull()
            ->and(File::exists($reportPath))->toBeTrue()
            ->and(File::get($reportPath))->toContain('resource_id,category,value,detail')
            ->toContain('access_unknown_igsn');

        $this->artisan('metadata:backfill-access-content', ['--apply' => true])
            ->expectsOutputToContain('Backfill applied.')
            ->assertExitCode(Command::SUCCESS);

        expect($resource->fresh()->access_level)->toBe(AccessLevel::OPEN)
            ->and($unknownIgsn->fresh()->access_level)->toBeNull();
    } finally {
        File::delete($reportPath);
    }
});
