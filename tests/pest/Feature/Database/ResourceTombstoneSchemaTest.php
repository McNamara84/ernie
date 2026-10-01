<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceTombstoneTransition;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

it('adds inactive defaults to existing landing pages and preserves configuration across rollback', function (): void {
    $page = LandingPage::factory()->published()->create(['ftp_url' => 'https://example.org/original.zip']);
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_01_000001_add_resource_tombstones.php');
    $migration->down();
    try {
        expect(Schema::hasTable('resource_tombstone_transitions'))->toBeFalse()
            ->and(Schema::hasColumn('landing_pages', 'is_tombstone'))->toBeFalse()
            ->and($page->fresh()->ftp_url)->toBe('https://example.org/original.zip');
    } finally {
        $migration->up();
    }
    expect($page->fresh()->is_tombstone)->toBeFalse()
        ->and($page->fresh()->tombstone_revision)->toBe(0)
        ->and($page->fresh()->is_published)->toBeTrue();
});

it('indexes visibility, revision uniqueness and due outbox work', function (): void {
    $indexes = collect(Schema::getIndexes('resource_tombstone_transitions'));
    expect($indexes->contains(fn (array $index): bool => ($index['unique'] ?? false)
        && $index['columns'] === ['resource_id', 'revision']))->toBeTrue()
        ->and($indexes->contains(fn (array $index): bool => $index['columns'] === ['status', 'available_at']))->toBeTrue()
        ->and(collect(Schema::getIndexes('landing_pages'))->contains(
            fn (array $index): bool => $index['columns'] === ['is_tombstone'],
        ))->toBeTrue();
});

it('retains audit history when the responsible user is removed and cascades resource deletion', function (): void {
    $resource = Resource::factory()->create();
    $user = User::factory()->create();
    $page = LandingPage::factory()->create(['resource_id' => $resource->id]);
    $page->forceFill(['tombstoned_by_user_id' => $user->id])->save();
    $transition = ResourceTombstoneTransition::create([
        'resource_id' => $resource->id, 'user_id' => $user->id, 'revision' => 1,
        'action' => 'activate', 'doi' => '10.83279/schema', 'test_mode' => true,
        'target_state' => 'registered', 'target_url' => $page->public_url,
        'snapshot' => ['configuration' => ['is_published' => true], 'files' => []],
    ]);
    expect($transition->fresh()->status)->toBe('pending')
        ->and($transition->fresh()->snapshot['configuration']['is_published'])->toBeTrue();
    $user->delete();
    expect($transition->fresh()->user_id)->toBeNull()
        ->and($page->fresh()->tombstoned_by_user_id)->toBeNull();
    $resource->delete();
    expect($transition->fresh())->toBeNull();
});

it('rejects duplicate revisions for one resource', function (): void {
    $values = [
        'resource_id' => Resource::factory()->create()->id, 'revision' => 1,
        'action' => 'activate', 'doi' => '10.83279/unique', 'test_mode' => true,
        'target_state' => 'registered', 'target_url' => 'https://example.org/tombstone',
    ];
    ResourceTombstoneTransition::create($values);
    expect(fn () => ResourceTombstoneTransition::create($values))->toThrow(QueryException::class);
});
