<?php

declare(strict_types=1);

use App\Http\Requests\StoreDraftResourceRequest;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

covers(StoreDraftResourceRequest::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    TitleType::factory()->count(5)->create();
});

describe('contributor email/website validation (draft)', function () {
    it('accepts contributor email and website fields in draft mode', function () {
        $data = [
            'titles' => [
                ['title' => 'Draft Resource', 'titleType' => 'main-title'],
            ],
            'contributors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Contact',
                    'lastName' => 'Person',
                    'roles' => ['Contact Person'],
                    'email' => 'contact@example.org',
                    'website' => 'https://example.org',
                    'affiliations' => [],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $data);

        $response->assertJsonMissingValidationErrors(['contributors.0.email', 'contributors.0.website']);
    });

    it('does not require email for Contact Person in draft mode', function () {
        $data = [
            'titles' => [
                ['title' => 'Draft Resource', 'titleType' => 'main-title'],
            ],
            'contributors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Contact',
                    'lastName' => 'Person',
                    'roles' => ['Contact Person'],
                    'email' => '',
                    'affiliations' => [],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $data);

        $response->assertJsonMissingValidationErrors(['contributors.0.email']);
    });

    it('rejects invalid email format in draft mode', function () {
        $data = [
            'titles' => [
                ['title' => 'Draft Resource', 'titleType' => 'main-title'],
            ],
            'contributors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Bad',
                    'lastName' => 'Email',
                    'roles' => ['Contact Person'],
                    'email' => 'not-an-email',
                    'affiliations' => [],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $data);

        $response->assertJsonValidationErrors(['contributors.0.email']);
    });

    it('rejects invalid website URL in draft mode', function () {
        $data = [
            'titles' => [
                ['title' => 'Draft Resource', 'titleType' => 'main-title'],
            ],
            'contributors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Bad',
                    'lastName' => 'Website',
                    'roles' => ['Contact Person'],
                    'email' => 'ok@example.org',
                    'website' => 'not-a-url',
                    'affiliations' => [],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $data);

        $response->assertJsonValidationErrors(['contributors.0.website']);
    });
});
