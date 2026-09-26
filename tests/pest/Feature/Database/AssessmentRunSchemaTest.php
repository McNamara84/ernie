<?php

declare(strict_types=1);

use App\Enums\AssessmentScope;
use App\Models\AssessmentRun;
use App\Models\AssessmentRunItem;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceAssessmentRefresh;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

it('defines the indexes needed for unique active runs and dispatcher lookups', function (): void {
    $runIndexes = collect(Schema::getIndexes('assessment_runs'));
    $itemIndexes = collect(Schema::getIndexes('assessment_run_items'));

    expect($runIndexes->contains(fn (array $index): bool => ($index['unique'] ?? false) === true
        && array_values($index['columns'] ?? []) === ['active_scope']))->toBeTrue()
        ->and($itemIndexes->contains(fn (array $index): bool => array_values($index['columns'] ?? []) === [
            'run_id',
            'status',
            'available_at',
        ]))->toBeTrue()
        ->and($itemIndexes->contains(fn (array $index): bool => array_values($index['columns'] ?? []) === [
            'lease_expires_at',
        ]))->toBeTrue();
});

it('stores resumable snapshot preparation progress', function (): void {
    expect(Schema::hasColumns('assessment_runs', [
        'snapshot_max_resource_id',
        'preparation_cursor',
        'prepared_at',
        'service_errors',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('assessment_run_items', [
            'failure_type',
            'error_code',
            'error_detail',
            'last_attempt_duration_ms',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('resource_assessments', [
            'failure_type',
            'error_code',
        ]))->toBeTrue();
});

it('persists microsecond precision for assessment result ordering', function (): void {
    expect(Schema::hasColumn('resource_assessments', 'assessment_started_at'))->toBeTrue();

    $resource = Resource::factory()->withDoi('10.5880/assessment.precision')->create();
    $assessment = ResourceAssessment::query()->create([
        'resource_id' => $resource->id,
        'status' => ResourceAssessment::STATUS_COMPLETED,
        'assessment_started_at' => Carbon::parse('2026-09-25 12:00:00.123456'),
    ]);

    expect($assessment->fresh()->assessment_started_at?->format('u'))->toBe('123456');

    if (DB::getDriverName() === 'mysql') {
        $precision = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'resource_assessments')
            ->where('COLUMN_NAME', 'assessment_started_at')
            ->value('DATETIME_PRECISION');

        expect((int) $precision)->toBe(6);
    }
});

it('persists microsecond precision for publication request ordering', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/refresh.precision')->create();
    $refresh = ResourceAssessmentRefresh::query()->create([
        'resource_id' => $resource->id,
        'status' => ResourceAssessmentRefresh::PENDING,
        'requested_at' => Carbon::parse('2026-09-25 12:00:00.654321'),
    ]);

    expect($refresh->fresh()->requested_at->format('u'))->toBe('654321');

    if (DB::getDriverName() === 'mysql') {
        $precision = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'resource_assessment_refreshes')
            ->where('COLUMN_NAME', 'requested_at')
            ->value('DATETIME_PRECISION');

        expect((int) $precision)->toBe(6);
    }
});

it('stores one durable publication refresh per resource and removes it on resource deletion', function (): void {
    expect(Schema::hasColumns('resource_assessment_refreshes', [
        'resource_id', 'status', 'generation', 'attempts', 'service_attempts',
        'queue_job_id', 'requested_at', 'available_at', 'lease_expires_at', 'completed_at', 'last_error',
    ]))->toBeTrue();

    $resource = Resource::factory()->withDoi('10.5880/assessment.refresh-schema')->create();
    ResourceAssessmentRefresh::query()->create([
        'resource_id' => $resource->id,
        'status' => ResourceAssessmentRefresh::PENDING,
        'requested_at' => now(),
    ]);

    expect(fn () => ResourceAssessmentRefresh::query()->create([
        'resource_id' => $resource->id,
        'status' => ResourceAssessmentRefresh::PENDING,
        'requested_at' => now(),
    ]))->toThrow(QueryException::class);

    $resource->delete();
    expect(ResourceAssessmentRefresh::query()->whereKey($resource->id)->exists())->toBeFalse();
});

it('allows only one active run per scope while retaining terminal run history', function (): void {
    $first = AssessmentRun::factory()->create([
        'scope' => AssessmentScope::RESOURCE,
        'active_scope' => AssessmentScope::RESOURCE,
    ]);

    expect(fn () => AssessmentRun::factory()->create([
        'scope' => AssessmentScope::RESOURCE,
        'active_scope' => AssessmentScope::RESOURCE,
    ]))->toThrow(QueryException::class);

    $first->update(['active_scope' => null]);
    $second = AssessmentRun::factory()->create([
        'scope' => AssessmentScope::RESOURCE,
        'active_scope' => AssessmentScope::RESOURCE,
    ]);

    expect($second->id)->not->toBe($first->id)
        ->and(AssessmentRun::query()->count())->toBe(2);
});

it('preserves audit history on user and resource deletion and cascades run deletion', function (): void {
    $user = User::factory()->admin()->create();
    $resource = Resource::factory()->withDoi('10.5880/assessment.foreign-keys')->create();
    $run = AssessmentRun::factory()->for($user, 'initiatedBy')->create([
        'last_controlled_by_user_id' => $user->id,
    ]);
    $item = AssessmentRunItem::factory()->for($run, 'run')->for($resource)->create();

    $user->delete();
    $resource->delete();

    expect($run->fresh()->initiated_by_user_id)->toBeNull()
        ->and($run->fresh()->last_controlled_by_user_id)->toBeNull()
        ->and($item->fresh()->resource_id)->toBeNull();

    $run->delete();

    expect(AssessmentRunItem::query()->whereKey($item->id)->exists())->toBeFalse();
});
