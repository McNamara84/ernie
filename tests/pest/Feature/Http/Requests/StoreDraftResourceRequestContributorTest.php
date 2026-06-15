<?php

declare(strict_types=1);

use App\Http\Requests\StoreDraftResourceRequest;
use App\Models\TitleType;
use App\Models\User;

covers(StoreDraftResourceRequest::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    TitleType::factory()->count(5)->create();
});

describe('licenses validation (draft)', function () {
    it('accepts null licenses in draft mode', function () {
        $data = [
            'titles' => [
                ['title' => 'Draft Resource', 'titleType' => 'main-title'],
            ],
            'licenses' => null,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $data);

        $response->assertJsonMissingValidationErrors(['licenses']);
    });

    it('rejects non-array licenses without throwing during draft normalization', function () {
        $data = [
            'titles' => [
                ['title' => 'Draft Resource', 'titleType' => 'main-title'],
            ],
            'licenses' => 'CC-BY-4.0',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['licenses']);
    });
});

describe('contributor email/website validation (draft)', function () {
    it('accepts empty and ROR-only author affiliations in draft mode', function () {
        $data = [
            'titles' => [
                ['title' => 'Draft Resource', 'titleType' => 'main-title'],
            ],
            'authors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Draft',
                    'lastName' => 'Author',
                    'isContact' => false,
                    'affiliations' => [
                        ['value' => '', 'rorId' => ''],
                        ['value' => '', 'rorId' => 'https://ror.org/04wxnsj81'],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $data);

        $response->assertJsonMissingValidationErrors([
            'authors.0.affiliations',
            'authors.0.affiliations.0.value',
            'authors.0.affiliations.1.value',
        ]);
    });

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

    it('accepts missing, empty, and ROR-only contributor affiliations in draft mode', function () {
        $data = [
            'titles' => [
                ['title' => 'Draft Resource', 'titleType' => 'main-title'],
            ],
            'contributors' => [
                [
                    'type' => 'person',
                    'firstName' => 'Contributor',
                    'lastName' => 'Without Affiliation',
                    'roles' => ['DataCollector'],
                ],
                [
                    'type' => 'person',
                    'firstName' => 'Contributor',
                    'lastName' => 'With Empty Affiliation',
                    'roles' => ['DataCollector'],
                    'affiliations' => [
                        ['value' => '', 'rorId' => ''],
                        ['value' => '', 'rorId' => 'https://ror.org/04wxnsj81'],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/editor/resources/draft', $data);

        $response->assertJsonMissingValidationErrors([
            'contributors.0.affiliations',
            'contributors.0.affiliations.0.value',
            'contributors.1.affiliations',
            'contributors.1.affiliations.0.value',
            'contributors.1.affiliations.1.value',
        ]);
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
