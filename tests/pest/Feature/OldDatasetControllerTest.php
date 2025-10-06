<?php

use App\Models\OldDataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery as MockeryAlias;
use Tests\Helpers\OldDatasetMockFactory;

use function Pest\Laravel\get;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockeryAlias::close();
});

beforeEach(function (): void {
    $this->withoutVite();
    actingAs(User::factory()->create([
        'email_verified_at' => now(),
    ]));
});

it('renders the old datasets page with paginated data', function (): void {
    $dataset1 = OldDatasetMockFactory::make([
        'id' => 1,
        'identifier' => '10.1234/example-one',
        'resourcetypegeneral' => 'Dataset',
        'curator' => 'Alice',
        'title' => 'Example dataset number one',
        'created_at' => '2024-01-01 10:00:00',
        'updated_at' => '2024-01-05 12:00:00',
        'publicstatus' => 'published',
        'publisher' => 'Example Publisher',
        'publicationyear' => 2024,
        'licenses' => [],
    ]);

    $dataset2 = OldDatasetMockFactory::make([
        'id' => 2,
        'identifier' => '10.1234/example-two',
        'resourcetypegeneral' => 'Dataset',
        'curator' => 'Bob',
        'title' => 'Example dataset number two',
        'created_at' => '2024-02-02 14:30:00',
        'updated_at' => '2024-02-05 09:15:00',
        'publicstatus' => 'review',
        'publisher' => 'Example Publisher',
        'publicationyear' => 2023,
        'licenses' => [],
    ]);

    $datasets = [$dataset1, $dataset2];

    $paginator = new LengthAwarePaginator(
        $datasets,
        total: 2,
        perPage: 50,
        currentPage: 1,
        options: ['path' => '/old-datasets']
    );

    MockeryAlias::mock('alias:' . OldDataset::class)
        ->shouldReceive('getPaginatedOrdered')
        ->once()
        ->with(1, 50, 'updated_at', 'desc')
        ->andReturn($paginator);

    // Expected datasets with licenses added
    $expectedDatasets = [
        [
            'id' => 1,
            'identifier' => '10.1234/example-one',
            'resourcetypegeneral' => 'Dataset',
            'curator' => 'Alice',
            'title' => 'Example dataset number one',
            'created_at' => '2024-01-01 10:00:00',
            'updated_at' => '2024-01-05 12:00:00',
            'publicstatus' => 'published',
            'publisher' => 'Example Publisher',
            'publicationyear' => 2024,
            'licenses' => [],
        ],
        [
            'id' => 2,
            'identifier' => '10.1234/example-two',
            'resourcetypegeneral' => 'Dataset',
            'curator' => 'Bob',
            'title' => 'Example dataset number two',
            'created_at' => '2024-02-02 14:30:00',
            'updated_at' => '2024-02-05 09:15:00',
            'publicstatus' => 'review',
            'publisher' => 'Example Publisher',
            'publicationyear' => 2023,
            'licenses' => [],
        ],
    ];

    get(route('old-datasets'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('old-datasets')
            ->where('datasets', $expectedDatasets)
            ->where('pagination', [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 50,
                'total' => 2,
                'from' => 1,
                'to' => 2,
                'has_more' => false,
            ])
            ->missing('error')
            ->missing('debug')
            ->where('sort', [
                'key' => 'updated_at',
                'direction' => 'desc',
            ])
        );
});

it('sanitises pagination parameters before fetching datasets', function (): void {
    $paginator = new LengthAwarePaginator(
        [],
        total: 0,
        perPage: 200,
        currentPage: 1,
        options: ['path' => '/old-datasets']
    );

    MockeryAlias::mock('alias:' . OldDataset::class)
        ->shouldReceive('getPaginatedOrdered')
        ->once()
        ->with(1, 200, 'updated_at', 'desc')
        ->andReturn($paginator);

    get('/old-datasets?page=0&per_page=999')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('old-datasets')
            ->where('datasets', [])
            ->where('pagination', [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 200,
                'total' => 0,
                'from' => null,
                'to' => null,
                'has_more' => false,
            ])
            ->where('sort', [
                'key' => 'updated_at',
                'direction' => 'desc',
            ])
        );
});

it('applies the requested sort parameters when valid values are provided', function (): void {
    $paginator = new LengthAwarePaginator(
        [],
        total: 0,
        perPage: 50,
        currentPage: 1,
        options: ['path' => '/old-datasets']
    );

    MockeryAlias::mock('alias:' . OldDataset::class)
        ->shouldReceive('getPaginatedOrdered')
        ->once()
        ->with(1, 50, 'id', 'asc')
        ->andReturn($paginator);

    get('/old-datasets?sort_key=id&sort_direction=asc')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('old-datasets')
            ->where('sort', [
                'key' => 'id',
                'direction' => 'asc',
            ])
        );
});

it('falls back to the default sort when invalid parameters are provided', function (): void {
    $paginator = new LengthAwarePaginator(
        [],
        total: 0,
        perPage: 50,
        currentPage: 1,
        options: ['path' => '/old-datasets']
    );

    MockeryAlias::mock('alias:' . OldDataset::class)
        ->shouldReceive('getPaginatedOrdered')
        ->once()
        ->with(1, 50, 'updated_at', 'desc')
        ->andReturn($paginator);

    get('/old-datasets?sort_key=title&sort_direction=down')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('old-datasets')
            ->where('sort', [
                'key' => 'updated_at',
                'direction' => 'desc',
            ])
        );
});

it('returns JSON payload for the load-more endpoint', function (): void {
    $dataset3 = OldDatasetMockFactory::make([
        'id' => 3,
        'identifier' => '10.1234/example-three',
        'resourcetypegeneral' => 'Image',
        'curator' => 'Carol',
        'title' => 'Example dataset number three',
        'created_at' => '2024-03-01 08:00:00',
        'updated_at' => '2024-03-02 09:00:00',
        'publicstatus' => 'draft',
        'publisher' => 'Example Publisher',
        'publicationyear' => 2022,
        'licenses' => [],
    ]);

    $datasets = [$dataset3];

    $paginator = new LengthAwarePaginator(
        $datasets,
        total: 21,
        perPage: 20,
        currentPage: 2,
        options: ['path' => '/old-datasets/load-more']
    );

    MockeryAlias::mock('alias:' . OldDataset::class)
        ->shouldReceive('getPaginatedOrdered')
        ->once()
        ->with(2, 20, 'updated_at', 'desc')
        ->andReturn($paginator);

    // Expected datasets with licenses added
    $expectedDatasets = [
        [
            'id' => 3,
            'identifier' => '10.1234/example-three',
            'resourcetypegeneral' => 'Image',
            'curator' => 'Carol',
            'title' => 'Example dataset number three',
            'created_at' => '2024-03-01 08:00:00',
            'updated_at' => '2024-03-02 09:00:00',
            'publicstatus' => 'draft',
            'publisher' => 'Example Publisher',
            'publicationyear' => 2022,
            'licenses' => [],
        ],
    ];

    get('/old-datasets/load-more?page=2&per_page=20')
        ->assertOk()
        ->assertJson([
            'datasets' => $expectedDatasets,
            'pagination' => [
                'current_page' => 2,
                'last_page' => 2,
                'per_page' => 20,
                'total' => 21,
                'from' => 21,
                'to' => 21,
                'has_more' => false,
            ],
            'sort' => [
                'key' => 'updated_at',
                'direction' => 'desc',
            ],
        ]);
});

it('exposes a helpful error state when the listing cannot be loaded', function (): void {
    $exception = new RuntimeException('database unavailable');

    config()->set('database.connections.metaworks.host', 'sumario-db.gfz');
    config()->set('database.connections.metaworks.port', 3306);
    config()->set('database.connections.metaworks.database', 'sumario-pmd');
    config()->set('database.connections.metaworks.username', 'sumario');

    Log::spy();

    MockeryAlias::mock('alias:' . OldDataset::class)
        ->shouldReceive('getPaginatedOrdered')
        ->once()
        ->withAnyArgs()
        ->andThrow($exception);

    get(route('old-datasets'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('old-datasets')
            ->where('datasets', [])
            ->where('pagination', [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => 50,
                'total' => 0,
                'from' => 0,
                'to' => 0,
                'has_more' => false,
            ])
            ->where('error', 'SUMARIOPMD-Datenbankverbindung fehlgeschlagen: ' . $exception->getMessage())
            ->has('debug', fn (Assert $debug): Assert => $debug
                ->where('connection', 'metaworks')
                ->where('driver', 'mysql')
                ->where('hosts', ['sumario-db.gfz'])
                ->where('port', 3306)
                ->where('database', 'sumario-pmd')
                ->where('username', 'sumario')
                ->where('error_code', $exception->getCode())
            )
            ->where('sort', [
                'key' => 'updated_at',
                'direction' => 'desc',
            ])
        );

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($exception): bool {
            expect($message)->toBe('SUMARIOPMD connection failure when rendering old datasets');
            expect($context)->toMatchArray([
                'connection' => 'metaworks',
                'driver' => 'mysql',
                'hosts' => ['sumario-db.gfz'],
                'port' => 3306,
                'database' => 'sumario-pmd',
                'username' => 'sumario',
                'error_code' => $exception->getCode(),
            ]);
            expect($context['exception'])->toBe($exception);

            return true;
        });
});

it('returns an error response when the load-more endpoint fails', function (): void {
    config()->set('database.connections.metaworks.host', 'sumario-db.gfz');
    config()->set('database.connections.metaworks.port', 3306);
    config()->set('database.connections.metaworks.database', 'sumario-pmd');
    config()->set('database.connections.metaworks.username', 'sumario');

    Log::spy();

    MockeryAlias::mock('alias:' . OldDataset::class)
        ->shouldReceive('getPaginatedOrdered')
        ->once()
        ->withAnyArgs()
        ->andThrow(new RuntimeException('timeout while contacting replica'));

    get('/old-datasets/load-more')
        ->assertStatus(500)
        ->assertJson([
            'error' => 'Error loading datasets:ss timeout while contacting replica',
            'debug' => [
                'connection' => 'metaworks',
                'driver' => 'mysql',
                'hosts' => ['sumario-db.gfz'],
                'port' => 3306,
                'database' => 'sumario-pmd',
                'username' => 'sumario',
                'error_code' => 0,
            ],
            'sort' => [
                'key' => 'updated_at',
                'direction' => 'desc',
            ],
        ]);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('SUMARIOPMD connection failure when loading more old datasets');
            expect($context)->toMatchArray([
                'connection' => 'metaworks',
                'driver' => 'mysql',
                'hosts' => ['sumario-db.gfz'],
                'port' => 3306,
                'database' => 'sumario-pmd',
                'username' => 'sumario',
                'error_code' => 0,
            ]);
            expect($context['exception'])->toBeInstanceOf(RuntimeException::class);

            return true;
        });
});
