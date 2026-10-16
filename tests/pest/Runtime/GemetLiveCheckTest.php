<?php

declare(strict_types=1);

use App\Models\User;
use GuzzleHttp\TransferStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Explicit opt-in only: this test contacts the real public EEA GEMET service.
uses(TestCase::class, RefreshDatabase::class);

it('checks the live GEMET provider through the settings endpoint and then uses the warm cache', function (): void {
    $this->withoutVite();
    $localVocabulary = Storage::exists('gemet-thesaurus.json') ? Storage::get('gemet-thesaurus.json') : null;
    $disk = 'gemet-live-'.bin2hex(random_bytes(6));
    Storage::fake($disk);
    config(['filesystems.default' => $disk]);
    if ($localVocabulary !== null) {
        Storage::put('gemet-thesaurus.json', $localVocabulary);
    }
    Cache::flush();
    Queue::fake();
    $transfers = [];
    Http::globalOptions(['on_stats' => function (TransferStats $stats) use (&$transfers): void {
        parse_str($stats->getEffectiveUri()->getQuery(), $query);
        $transfers[] = [
            'uri' => $query['concept_uri'] ?? $query['thesaurus_uri'] ?? null,
            'status' => $stats->getResponse()?->getStatusCode(),
            'seconds' => $stats->getTransferTime(),
            'connect_seconds' => $stats->getHandlerStat('connect_time'),
            'tls_seconds' => $stats->getHandlerStat('appconnect_time'),
            'curl_error' => $stats->getHandlerErrorData(),
        ];
    }]);
    $this->actingAs(User::factory()->admin()->create());
    $started = hrtime(true);
    $response = $this->postJson('/thesauri/gemet/check');
    $report = [
        'checked_at_utc' => gmdate(DATE_ATOM),
        'php' => PHP_VERSION,
        'curl' => curl_version()['version'],
        'cold_status' => $response->status(),
        'cold_elapsed_seconds' => (hrtime(true) - $started) / 1e9,
        'response' => $response->json(),
        'transfers' => $transfers,
    ];
    if ($response->status() === 200) {
        $requests = count($transfers);
        $started = hrtime(true);
        $warm = $this->postJson('/thesauri/gemet/check');
        $report['warm_status'] = $warm->status();
        $report['warm_elapsed_seconds'] = (hrtime(true) - $started) / 1e9;
        $report['warm_requests'] = count($transfers) - $requests;
        $warm->assertOk()->assertJsonPath('remoteCount', $response->json('remoteCount'));
        expect($report['warm_requests'])->toBe(0);
    }
    file_put_contents(storage_path('logs/issue-1476-gemet-live.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    fwrite(STDOUT, json_encode(array_diff_key($report, ['transfers' => true]), JSON_THROW_ON_ERROR).PHP_EOL);
    $response->assertOk();
    expect($response->json('remoteCount'))->toBeGreaterThan(0);
    if ($localVocabulary !== null) {
        expect(Storage::get('gemet-thesaurus.json'))->toBe($localVocabulary);
    }
    Queue::assertNothingPushed();
});
