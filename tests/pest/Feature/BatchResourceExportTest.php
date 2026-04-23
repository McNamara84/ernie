<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->curator()->create();
});

describe('BatchResourceExportController@export', function () {
    test('requires authentication', function () {
        $resource = Resource::factory()->create();

        $response = $this->postJson('/resources/batch-export', [
            'ids' => [$resource->id],
            'format' => 'datacite-json',
        ]);

        expect($response->status())->toBeIn([302, 401, 403]);
    });

    test('validates that ids are required', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-export', [
                'format' => 'datacite-json',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['ids']);
    });

    test('validates that format is required and one of allowed values', function () {
        $resource = Resource::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-export', [
                'ids' => [$resource->id],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['format']);

        $response2 = $this->actingAs($this->user)
            ->postJson('/resources/batch-export', [
                'ids' => [$resource->id],
                'format' => 'bogus',
            ]);

        $response2->assertStatus(422)->assertJsonValidationErrors(['format']);
    });

    test('rejects non-existent resource ids', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-export', [
                'ids' => [999999],
                'format' => 'datacite-json',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['ids.0']);
    });

    test('enforces maximum batch size of 100', function () {
        $ids = range(1, 101);

        $response = $this->actingAs($this->user)
            ->postJson('/resources/batch-export', [
                'ids' => $ids,
                'format' => 'datacite-json',
            ]);

        $response->assertStatus(422);
    });

    test('returns a zip archive for the datacite-json format', function () {
        $resources = Resource::factory()->count(2)->create();

        $response = $this->actingAs($this->user)
            ->post('/resources/batch-export', [
                'ids' => $resources->pluck('id')->all(),
                'format' => 'datacite-json',
            ]);

        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('application/zip');
        expect($response->headers->get('content-disposition'))->toContain('resources-export-datacite-json-');

        $zipPath = tempnam(sys_get_temp_dir(), 'ernie-test-zip-');
        file_put_contents($zipPath, $response->streamedContent() ?: $response->getContent());

        $zip = new ZipArchive;
        expect($zip->open($zipPath))->toBeTrue();
        expect($zip->numFiles)->toBe(2);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        foreach ($names as $name) {
            expect($name)->toEndWith('.json');
        }
        $zip->close();
        @unlink($zipPath);
    });

    test('returns a zip archive for the datacite-xml format', function () {
        $resource = Resource::factory()->create();

        $response = $this->actingAs($this->user)
            ->post('/resources/batch-export', [
                'ids' => [$resource->id],
                'format' => 'datacite-xml',
            ]);

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('resources-export-datacite-xml-');

        $zipPath = tempnam(sys_get_temp_dir(), 'ernie-test-zip-');
        file_put_contents($zipPath, $response->streamedContent() ?: $response->getContent());

        $zip = new ZipArchive;
        expect($zip->open($zipPath))->toBeTrue();
        expect($zip->numFiles)->toBe(1);
        expect($zip->getNameIndex(0))->toEndWith('.xml');
        $zip->close();
        @unlink($zipPath);
    });

    test('returns a zip archive for the jsonld format', function () {
        $resource = Resource::factory()->create();

        $response = $this->actingAs($this->user)
            ->post('/resources/batch-export', [
                'ids' => [$resource->id],
                'format' => 'jsonld',
            ]);

        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('resources-export-jsonld-');

        $zipPath = tempnam(sys_get_temp_dir(), 'ernie-test-zip-');
        file_put_contents($zipPath, $response->streamedContent() ?: $response->getContent());

        $zip = new ZipArchive;
        expect($zip->open($zipPath))->toBeTrue();
        expect($zip->numFiles)->toBe(1);
        expect($zip->getNameIndex(0))->toEndWith('.jsonld');
        $zip->close();
        @unlink($zipPath);
    });

    test('includes DOI in archive entry name when available', function () {
        $resource = Resource::factory()->create(['doi' => '10.1234/abcd']);

        $response = $this->actingAs($this->user)
            ->post('/resources/batch-export', [
                'ids' => [$resource->id],
                'format' => 'datacite-json',
            ]);

        $zipPath = tempnam(sys_get_temp_dir(), 'ernie-test-zip-');
        file_put_contents($zipPath, $response->streamedContent() ?: $response->getContent());

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $name = $zip->getNameIndex(0);
        $zip->close();
        @unlink($zipPath);

        expect($name)->toContain("resource-{$resource->id}-")->toContain('10.1234-abcd');
    });
});
