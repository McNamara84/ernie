<?php

declare(strict_types=1);

use App\Console\Commands\GetCgiSimpleLithology;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(GetCgiSimpleLithology::class);

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'simple_lithology.min_concepts' => 1,
        'simple_lithology.max_concepts' => 10,
    ]);
});

it('downloads and reports a validated CGI Simple Lithology vocabulary', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains((string) $request['query'], 'dateModified')) {
            return Http::response([
                'results' => ['bindings' => []],
            ], 200, ['Content-Type' => 'application/sparql-results+json']);
        }

        return Http::response([
            'results' => ['bindings' => [[
                'concept' => [
                    'type' => 'uri',
                    'value' => 'http://resource.geosciml.org/classifier/cgi/lithology/basalt',
                ],
                'prefLabel' => ['type' => 'literal', 'xml:lang' => 'en', 'value' => 'Basalt'],
            ]]],
        ], 200, ['Content-Type' => 'application/sparql-results+json']);
    });

    $this->artisan('get-cgi-simple-lithology')
        ->expectsOutputToContain('Fetching CGI Simple Lithology')
        ->expectsOutputToContain('Unique concepts')
        ->expectsOutputToContain('Source SHA-256')
        ->assertExitCode(Command::SUCCESS);

    Storage::disk('local')->assertExists('cgi-simple-lithology.json');
});

it('returns failure without replacing an existing file when the remote response is invalid', function (): void {
    Storage::disk('local')->put('cgi-simple-lithology.json', '{"known":"good"}');
    Http::fake(fn () => Http::response(
        ['results' => ['bindings' => []]],
        200,
        ['Content-Type' => 'application/sparql-results+json'],
    ));

    $this->artisan('get-cgi-simple-lithology')
        ->expectsOutputToContain('CGI Simple Lithology update failed')
        ->assertExitCode(Command::FAILURE);

    expect(Storage::disk('local')->get('cgi-simple-lithology.json'))->toBe('{"known":"good"}');
});
