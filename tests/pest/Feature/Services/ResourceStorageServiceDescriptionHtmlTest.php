<?php

declare(strict_types=1);

use App\Models\ResourceType;
use App\Models\Right;
use App\Models\User;
use App\Services\ResourceStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

describe('ResourceStorageService – Description HTML handling', function () {
    beforeEach(function (): void {
        $this->service = app(ResourceStorageService::class);
        $this->user = User::factory()->create();

        $this->seed(\Database\Seeders\TitleTypeSeeder::class);
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\DescriptionTypeSeeder::class);
        Right::create(['identifier' => 'CC-BY-4.0', 'name' => 'Creative Commons Attribution 4.0']);

        $this->resourceType = ResourceType::firstOrFail();
        $this->buildResourceData = function (array $descriptions): array {
            return [
                'resourceId' => null,
                'year' => 2024,
                'resourceType' => $this->resourceType->id,
                'titles' => [
                    ['title' => 'HTML Description Resource', 'titleType' => 'MainTitle'],
                ],
                'licenses' => ['CC-BY-4.0'],
                'authors' => [
                    ['type' => 'person', 'firstName' => 'Ada', 'lastName' => 'Lovelace', 'position' => 0],
                ],
                'descriptions' => $descriptions,
            ];
        };
    });

    it('stores sanitized landing page html separately from plain text', function (): void {
        $data = ($this->buildResourceData)([
            [
                'descriptionType' => 'abstract',
                'description' => '<p>Abstract with <strong>formatting</strong> and <a href="https://example.org/file">download link</a>.</p>',
            ],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        $description = $resource->descriptions()->firstOrFail();

        expect($description->landing_page_html)->toBe('<p>Abstract with <strong>formatting</strong> and <a href="https://example.org/file">download link</a>.</p>')
            ->and($description->value)->toBe('Abstract with formatting and download link (https://example.org/file).');
    });

    it('keeps plain text descriptions without creating landing page html', function (): void {
        $data = ($this->buildResourceData)([
            [
                'descriptionType' => 'methods',
                'description' => 'Plain methods text only.',
            ],
        ]);

        [$resource] = $this->service->store($data, $this->user->id);

        $description = $resource->descriptions()->firstOrFail();

        expect($description->landing_page_html)->toBeNull()
            ->and($description->value)->toBe('Plain methods text only.');
    });

    it('rejects descriptions that become empty after sanitization', function (): void {
        $data = ($this->buildResourceData)([
            [
                'descriptionType' => 'other',
                'description' => '<script>alert("x")</script>',
            ],
        ]);

        (void) $this->service->store($data, $this->user->id);
    })->throws(ValidationException::class);
});