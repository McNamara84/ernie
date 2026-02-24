<?php

declare(strict_types=1);

use App\Http\Controllers\LandingPageDomainController;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\User;

covers(LandingPageDomainController::class);

uses()->group('landing-pages', 'domains');

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->curator = User::factory()->curator()->create();
});

describe('Domain Listing', function () {
    test('authenticated users can list domains', function () {
        LandingPageDomain::factory()->withDomain('https://example.org/')->create();
        LandingPageDomain::factory()->withDomain('https://data.gfz.de/')->create();

        $response = $this->actingAs($this->curator)
            ->getJson('/api/landing-page-domains/list');

        $response->assertOk()
            ->assertJsonCount(2, 'domains')
            ->assertJsonStructure([
                'domains' => [['id', 'domain']],
            ]);
    });

    test('domains are ordered alphabetically', function () {
        LandingPageDomain::factory()->withDomain('https://zebra.org/')->create();
        LandingPageDomain::factory()->withDomain('https://alpha.org/')->create();

        $response = $this->actingAs($this->curator)
            ->getJson('/api/landing-page-domains/list');

        $response->assertOk();
        $domains = $response->json('domains');
        expect($domains[0]['domain'])->toBe('https://alpha.org/');
        expect($domains[1]['domain'])->toBe('https://zebra.org/');
    });

    test('unauthenticated users cannot list domains', function () {
        $response = $this->getJson('/api/landing-page-domains/list');

        $response->assertUnauthorized();
    });
});

describe('Domain Creation', function () {
    test('admin can create a domain', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => 'https://geofon.gfz.de/',
            ]);

        $response->assertCreated()
            ->assertJson([
                'message' => 'Domain added successfully.',
                'domain' => [
                    'domain' => 'https://geofon.gfz.de/',
                ],
            ]);

        expect(LandingPageDomain::where('domain', 'https://geofon.gfz.de/')->exists())->toBeTrue();
    });

    test('domain without trailing slash gets normalized', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => 'https://data.gfz.de',
            ]);

        $response->assertCreated();
        expect(LandingPageDomain::first()->domain)->toBe('https://data.gfz.de/');
    });

    test('duplicate domain returns 422', function () {
        LandingPageDomain::factory()->withDomain('https://example.org/')->create();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => 'https://example.org/',
            ]);

        $response->assertUnprocessable();
    });

    test('normalized duplicate domain returns 422', function () {
        LandingPageDomain::factory()->withDomain('https://example.org/')->create();

        // Same domain without trailing slash — should be caught after normalization
        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => 'https://example.org',
            ]);

        $response->assertUnprocessable();
    });

    test('invalid URL is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => 'not-a-url',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    });

    test('non-http scheme is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => 'ftp://files.example.org/',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    });

    test('empty domain is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => '',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    });

    test('curator cannot create a domain', function () {
        $response = $this->actingAs($this->curator)
            ->postJson('/api/landing-page-domains', [
                'domain' => 'https://example.org/',
            ]);

        $response->assertForbidden();
    });

    test('domain with path is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => 'https://example.org/some/path',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    });

    test('domain with query string is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => 'https://example.org/?q=search',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    });

    test('domain with fragment is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => 'https://example.org/#section',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    });

    test('domain with credentials is rejected', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => 'https://user:pass@example.org/',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    });

    test('domain input is trimmed before validation', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/landing-page-domains', [
                'domain' => '  https://trimmed.org  ',
            ]);

        $response->assertCreated();
        expect(LandingPageDomain::first()->domain)->toBe('https://trimmed.org/');
    });
});

describe('Domain Deletion', function () {
    test('admin can delete an unused domain', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://example.org/')->create();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/landing-page-domains/{$domain->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Domain deleted successfully.']);

        expect(LandingPageDomain::find($domain->id))->toBeNull();
    });

    test('cannot delete a domain used by a landing page', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://example.org/')->create();

        LandingPage::factory()->external()->create([
            'external_domain_id' => $domain->id,
            'external_path' => 'test/path',
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/landing-page-domains/{$domain->id}");

        $response->assertUnprocessable()
            ->assertJson(['error' => 'domain_in_use']);

        expect(LandingPageDomain::find($domain->id))->not->toBeNull();
    });

    test('curator cannot delete a domain', function () {
        $domain = LandingPageDomain::factory()->create();

        $response = $this->actingAs($this->curator)
            ->deleteJson("/api/landing-page-domains/{$domain->id}");

        $response->assertForbidden();
    });

    test('returns 404 for non-existent domain', function () {
        $response = $this->actingAs($this->admin)
            ->deleteJson('/api/landing-page-domains/99999');

        $response->assertNotFound();
    });
});
