<?php

declare(strict_types=1);

use App\Models\ThesaurusSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('updates MSL laboratories through the real command and serves the same local wrapper to ERNIE and ELMO', function (): void {
    Storage::fake('local');
    Cache::flush();
    config()->set([
        'services.ernie.api_key' => 'workflow-api-key',
        'msl.github_api_base' => 'https://api.example.test',
        'msl.repository' => 'UtrechtUniversity/msl_vocabularies',
        'msl.ref' => 'main',
        'msl.laboratories_base_path' => 'vocabularies/labs',
        'msl.laboratories_filename' => 'laboratories.json',
        'msl.http_retries' => 1,
        'msl.http_retry_delay_ms' => 0,
    ]);

    $sourceBody = json_encode([
        [
            'identifier' => 'workflow-lab',
            'name' => 'Workflow Laboratory',
            'display_name' => 'Workflow Laboratory — Utrecht University',
            'affiliation_name' => 'Utrecht University',
            'affiliation_ror' => 'https://ror.org/04pp8hn57',
            'scientific_domain' => 'Geosciences',
            'country' => 'Netherlands',
        ],
    ], JSON_THROW_ON_ERROR);
    $blobSha = sha1('blob '.strlen($sourceBody)."\0".$sourceBody);

    Http::fakeSequence()
        ->push([
            ['type' => 'dir', 'name' => '1.1'],
            ['type' => 'dir', 'name' => '1.2'],
            ['type' => 'file', 'name' => 'laboratories.json'],
        ])
        ->push([
            'type' => 'file',
            'sha' => $blobSha,
            'download_url' => 'https://raw.example.test/laboratories.json',
        ])
        ->push($sourceBody, 200, ['Content-Type' => 'application/json']);

    ThesaurusSetting::query()->updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_MSL_LABORATORIES],
        [
            'display_name' => 'MSL Laboratories',
            'is_active' => true,
            'is_elmo_active' => true,
            'version' => '1.1',
        ]
    );

    $this->artisan('get-msl-laboratories')
        ->expectsOutputToContain('MSL laboratories vocabulary updated successfully.')
        ->assertExitCode(Command::SUCCESS);

    Http::assertSentCount(3);
    Storage::assertExists('msl-laboratories.json');
    expect(ThesaurusSetting::query()
        ->where('type', ThesaurusSetting::TYPE_MSL_LABORATORIES)
        ->value('version'))->toBe('1.2');

    $user = User::factory()->create(['email_verified_at' => now()]);
    $ernie = $this->actingAs($user)
        ->getJson('/vocabularies/msl-laboratories')
        ->assertOk();
    $elmo = $this->withHeaders(['X-API-Key' => 'workflow-api-key'])
        ->getJson('/api/v1/vocabularies/msl-laboratories')
        ->assertOk();

    expect($ernie->json())->toBe($elmo->json())
        ->and($ernie->json('version'))->toBe('1.2')
        ->and($ernie->json('total'))->toBe(1)
        ->and($ernie->json('data.0.identifier'))->toBe('workflow-lab')
        ->and($ernie->json())->not->toHaveKey('source');
});
