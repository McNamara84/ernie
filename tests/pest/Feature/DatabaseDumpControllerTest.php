<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Controllers\DatabaseDumpController;
use App\Jobs\CreateDatabaseDumpJob;
use App\Models\DatabaseDumpDownload;
use App\Models\DatabaseDumpExport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

covers(DatabaseDumpController::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('database_dumps.disk', 'local');
    config()->set('database_dumps.expiry_hours', 24);
    config()->set('database_dumps.max_parallel_per_user', 1);
});

it('renders the database dump page for admins without opening legacy database connections', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/database')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('database')
            ->where('auth.user.can_access_database_dumps', true)
            ->has('targets', 4)
            ->where('targets.0.key', 'ernie')
            ->where('targets.1.key', 'sumariopmd')
            ->where('targets.2.key', 'metaworks')
            ->where('targets.3.key', 'igsn')
        );
});

it('forbids non-admin users and redirects guests', function (): void {
    $this->get('/database')->assertRedirect('/login');

    foreach ([UserRole::GROUP_LEADER, UserRole::CURATOR, UserRole::BEGINNER] as $role) {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->get('/database')
            ->assertForbidden();
    }
});

it('queues a database dump export for a known target', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson('/database/ernie/dumps')
        ->assertAccepted()
        ->assertJsonPath('export.targetKey', 'ernie')
        ->assertJsonPath('export.status', DatabaseDumpExport::STATUS_PENDING);

    $exportId = $response->json('export.id');

    $export = DatabaseDumpExport::query()->findOrFail($exportId);

    expect($export->user_id)->toBe($admin->id)
        ->and($export->target_label)->toBe('ERNIE')
        ->and($export->path)->toContain("database-dumps/ernie/{$export->id}/")
        ->and($export->filename)->toEndWith('.sql.gz');

    Queue::assertPushed(CreateDatabaseDumpJob::class, fn (CreateDatabaseDumpJob $job): bool => $job->exportId === $export->id);
});

it('rejects unknown dump targets', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson('/database/unknown/dumps')
        ->assertNotFound();

    Queue::assertNothingPushed();
});

it('prevents a user from starting a second active dump', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    DatabaseDumpExport::factory()->for($admin)->running()->create();

    $this->actingAs($admin)
        ->postJson('/database/ernie/dumps')
        ->assertStatus(409)
        ->assertJsonPath('message', 'Another database dump is already running. Please wait for it to finish.');

    Queue::assertNothingPushed();
});

it('serializes dump creation per user with a cache lock', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $lock = Cache::lock("database-dumps:user:{$admin->id}", 10);

    expect($lock->get())->toBeTrue();

    try {
        $this->actingAs($admin)
            ->postJson('/database/ernie/dumps')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Another database dump request is already being prepared. Please try again shortly.');
    } finally {
        $lock->release();
    }

    expect(DatabaseDumpExport::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('returns status payloads and marks completed expired exports', function (): void {
    $admin = User::factory()->admin()->create();
    $export = DatabaseDumpExport::factory()->for($admin)->expired()->create();

    $this->actingAs($admin)
        ->getJson(route('database.dumps.status', $export))
        ->assertOk()
        ->assertJsonPath('export.status', DatabaseDumpExport::STATUS_EXPIRED)
        ->assertJsonPath('export.downloadUrl', null);

    expect($export->refresh()->status)->toBe(DatabaseDumpExport::STATUS_EXPIRED);
});

it('downloads completed dumps and writes an audit record', function (): void {
    $admin = User::factory()->admin()->create();
    $export = DatabaseDumpExport::factory()->for($admin)->completed()->create([
        'path' => 'database-dumps/ernie/test.sql.gz',
        'filename' => 'ernie-test.sql.gz',
    ]);
    Storage::disk('local')->put($export->path, gzencode('create table test (id int);'));

    $this->actingAs($admin)
        ->get(route('database.dumps.download', $export))
        ->assertOk()
        ->assertDownload('ernie-test.sql.gz');

    expect($export->refresh()->download_count)->toBe(1)
        ->and($export->last_downloaded_at)->not->toBeNull()
        ->and(DatabaseDumpDownload::query()->where('database_dump_export_id', $export->id)->count())->toBe(1);
});

it('does not download expired, missing, or unfinished exports', function (): void {
    $admin = User::factory()->admin()->create();

    $expired = DatabaseDumpExport::factory()->for($admin)->expired()->create();
    $missing = DatabaseDumpExport::factory()->for($admin)->completed()->create([
        'path' => 'database-dumps/ernie/missing.sql.gz',
    ]);
    $pending = DatabaseDumpExport::factory()->for($admin)->create();

    $this->actingAs($admin)
        ->get(route('database.dumps.download', $expired))
        ->assertStatus(410);

    $this->actingAs($admin)
        ->get(route('database.dumps.download', $missing))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('database.dumps.download', $pending))
        ->assertStatus(409);
});
