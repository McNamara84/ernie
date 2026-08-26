<?php

declare(strict_types=1);

use App\Console\Commands\RepairDescriptionEntities;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Resource;
use App\Services\Descriptions\DescriptionEntityRepairService;
use App\Support\DescriptionTextNormalizer;
use Database\Seeders\DescriptionTypeSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

covers(RepairDescriptionEntities::class, DescriptionEntityRepairService::class, DescriptionTextNormalizer::class);

beforeEach(function (): void {
    test()->seed(DescriptionTypeSeeder::class);
});

function issue1173Description(Resource $resource, string $value): Description
{
    return Description::create([
        'resource_id' => $resource->id,
        'description_type_id' => DescriptionType::where('slug', 'Abstract')->value('id'),
        'value' => $value,
    ]);
}

it('is a dry run by default and applies the repair idempotently', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.5880/digis.e.2025.004']);
    $candidate = issue1173Description($resource, 'Pressure &gt;500 MPa and ratio &#x3c;0.5; keep &amp;gt;.');
    $unchanged = issue1173Description($resource, 'Already correct: >500 MPa and <0.5.');

    $dryRun = app(DescriptionEntityRepairService::class)->run();

    expect($dryRun)->toMatchArray([
        'scanned' => 2,
        'changed' => 1,
        'unchanged' => 1,
        'skipped' => 0,
        'errors' => 0,
    ])->and(collect($dryRun['records'])->pluck('status')->all())
        ->toBe(['would_update', 'unchanged'])
        ->and($candidate->fresh()->value)
        ->toBe('Pressure &gt;500 MPa and ratio &#x3c;0.5; keep &amp;gt;.');

    $applied = app(DescriptionEntityRepairService::class)->run(apply: true);

    expect($applied)->toMatchArray(['scanned' => 2, 'changed' => 1, 'unchanged' => 1])
        ->and($candidate->fresh()->value)->toBe('Pressure >500 MPa and ratio <0.5; keep &amp;gt;.')
        ->and($unchanged->fresh()->value)->toBe('Already correct: >500 MPa and <0.5.');

    expect(app(DescriptionEntityRepairService::class)->run(apply: true))
        ->toMatchArray(['scanned' => 2, 'changed' => 0, 'unchanged' => 2]);
});

it('honors DOI filtering resume and limit options and writes a command report', function (): void {
    $otherResource = Resource::factory()->create(['doi' => '10.5880/other']);
    $targetResource = Resource::factory()->create(['doi' => '10.5880/TARGET']);
    $first = issue1173Description($targetResource, 'First &gt;1');
    $second = issue1173Description($targetResource, 'Second &lt;2');
    $third = issue1173Description($targetResource, 'Third &gt;3');
    $other = issue1173Description($otherResource, 'Other &gt;4');
    $reportPath = storage_path('framework/testing/issue-1173-'.Str::uuid().'.csv');

    try {
        $this->artisan('descriptions:repair-entities', [
            '--apply' => true,
            '--doi' => ['https://doi.org/10.5880/target'],
            '--after-id' => $first->id,
            '--limit' => 1,
            '--report' => $reportPath,
        ])->expectsOutputToContain('Description entity repair applied.')
            ->assertExitCode(Command::SUCCESS);

        expect($first->fresh()->value)->toBe('First &gt;1')
            ->and($second->fresh()->value)->toBe('Second <2')
            ->and($third->fresh()->value)->toBe('Third &gt;3')
            ->and($other->fresh()->value)->toBe('Other &gt;4')
            ->and(File::get($reportPath))->toContain('description_id,resource_id,doi,status,replacements,message')
            ->toContain('10.5880/TARGET')
            ->toContain('updated');
    } finally {
        File::delete($reportPath);
    }
});

it('keeps command execution non-mutating unless apply is requested', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.5880/dry-run']);
    $description = issue1173Description($resource, 'Value &#62;10');

    $this->artisan('descriptions:repair-entities', ['--doi' => ['doi:10.5880/dry-run']])
        ->expectsOutputToContain('Dry run only; no data was changed.')
        ->assertExitCode(Command::SUCCESS);

    expect($description->fresh()->value)->toBe('Value &#62;10');
});
