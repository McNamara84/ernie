<?php

declare(strict_types=1);

use App\Services\Citations\LegacyCitationCacheService;
use App\Services\DataCiteApiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

covers(LegacyCitationCacheService::class);

beforeEach(function (): void {
    Config::set('database.connections.legacy_metaworks', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    DB::purge('legacy_metaworks');

    Schema::connection('legacy_metaworks')->create('citationcache', function (Blueprint $table): void {
        $table->string('url', 333)->primary();
        $table->text('citation');
        $table->dateTime('datetimecopied')->nullable();
    });
});

function legacyCitationCacheService(): LegacyCitationCacheService
{
    return new LegacyCitationCacheService(new DataCiteApiService);
}

it('returns and normalizes the canonical legacy citation', function (): void {
    DB::connection('legacy_metaworks')->table('citationcache')->insert([
        'url' => 'http://doi.org/10.1007/978-94-015-7879-0',
        'citation' => 'Cook, E., Kairiukstis, Leonardas, 1990. Methods of dendrochronology.',
    ]);

    expect(legacyCitationCacheService()->find('https://doi.org/10.1007/978-94-015-7879-0'))
        ->toBe('Cook, E., Kairiukstis, Leonardas, 1990. Methods of dendrochronology.');
});

it('finds mixed-case legacy URLs through the case-insensitive fallback', function (): void {
    DB::connection('legacy_metaworks')->table('citationcache')->insert([
        'url' => 'http://doi.org/10.5880/ICDP.5071.002',
        'citation' => 'Greenwood legacy citation',
    ]);

    expect(legacyCitationCacheService()->find('doi:10.5880/icdp.5071.002'))
        ->toBe('Greenwood legacy citation');
});

it('prefers the canonical legacy URL when multiple valid variants exist', function (): void {
    DB::connection('legacy_metaworks')->table('citationcache')->insert([
        [
            'url' => 'https://doi.org/10.1234/example',
            'citation' => 'HTTPS citation',
        ],
        [
            'url' => 'http://doi.org/10.1234/example',
            'citation' => 'Canonical HTTP citation',
        ],
    ]);

    expect(legacyCitationCacheService()->find('10.1234/example'))
        ->toBe('Canonical HTTP citation');
});

it('loads a mixed-case canonical variant even after an exact lower-priority hit', function (): void {
    DB::connection('legacy_metaworks')->table('citationcache')->insert([
        [
            'url' => 'https://doi.org/10.1234/example',
            'citation' => 'Exact lower-priority citation',
        ],
        [
            'url' => 'http://doi.org/10.1234/EXAMPLE',
            'citation' => 'Mixed-case canonical citation',
        ],
    ]);

    expect(legacyCitationCacheService()->find('10.1234/example'))
        ->toBe('Mixed-case canonical citation');
});

it('skips error sentinels and uses the next valid URL variant', function (string $sentinel): void {
    DB::connection('legacy_metaworks')->table('citationcache')->insert([
        [
            'url' => 'http://doi.org/10.1234/example',
            'citation' => $sentinel,
        ],
        [
            'url' => 'https://doi.org/10.1234/example',
            'citation' => 'Usable fallback citation',
        ],
    ]);

    expect(legacyCitationCacheService()->find('10.1234/example'))
        ->toBe('Usable fallback citation');
})->with([
    'invalid URL' => 'invalid URL',
    'resolver error' => 'error code: 520',
    'empty text' => '   ',
]);

it('converts legacy HTML to normalized plain text and removes active content', function (): void {
    DB::connection('legacy_metaworks')->table('citationcache')->insert([
        'url' => 'http://doi.org/10.1234/html',
        'citation' => "Doe, J. &amp; Smith, A.\n<i>Dataset title</i>  <script>alert('x')</script> GFZ.",
    ]);

    expect(legacyCitationCacheService()->find('10.1234/html'))
        ->toBe('Doe, J. & Smith, A. Dataset title GFZ.');
});

it('returns only valid normalized DOI keys from a batch lookup', function (): void {
    DB::connection('legacy_metaworks')->table('citationcache')->insert([
        [
            'url' => 'http://doi.org/10.1234/one',
            'citation' => 'Citation one',
        ],
        [
            'url' => '10.1234/two',
            'citation' => 'Citation two',
        ],
    ]);

    expect(legacyCitationCacheService()->findMany([
        '10.1234/ONE',
        'https://doi.org/10.1234/two',
        'not-a-doi',
        '',
    ]))->toBe([
        '10.1234/one' => 'Citation one',
        '10.1234/two' => 'Citation two',
    ]);
});

it('uses one indexed query when every DOI has a canonical cache hit', function (): void {
    DB::connection('legacy_metaworks')->table('citationcache')->insert([
        [
            'url' => 'http://doi.org/10.1234/one',
            'citation' => 'Citation one',
        ],
        [
            'url' => 'http://doi.org/10.1234/two',
            'citation' => 'Citation two',
        ],
    ]);

    $connection = DB::connection('legacy_metaworks');
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    legacyCitationCacheService()->findMany(['10.1234/one', '10.1234/two']);

    $citationQueries = array_values(array_filter(
        $connection->getQueryLog(),
        static fn (array $query): bool => str_contains($query['query'], 'citationcache'),
    ));

    expect($citationQueries)->toHaveCount(1);
});

it('opens a circuit breaker after a legacy database failure', function (): void {
    Schema::connection('legacy_metaworks')->drop('citationcache');
    Log::shouldReceive('warning')
        ->once()
        ->with(
            'Legacy citation cache lookup failed; falling back to DOI metadata.',
            Mockery::on(fn (array $context): bool => $context['doi_count'] === 1 && $context['error'] !== ''),
        );

    $service = legacyCitationCacheService();

    expect($service->find('10.1234/example'))->toBeNull()
        ->and($service->find('10.1234/another'))->toBeNull()
        ->and($service->findMany(['10.1234/third']))->toBe([]);
});
