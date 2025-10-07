<?php

declare(strict_types=1);

/**
 * Test Contributors Loading from Old Database
 *
 * These tests validate the functionality of loading contributors from the legacy SUMARIOPMD database
 * through the /old-datasets/{id}/contributors endpoint.
 *
 * Test datasets used (real data from metaworks database):
 * - Dataset 4: Mixed persons (some with firstname/lastname, some with name only)
 * - Dataset 8: Institution + multiple persons (with duplicates)
 * - Dataset 2396: Institution with HostingInstitution role + person
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    actingAs(User::factory()->create(['email_verified_at' => now()]));
});

describe('OldDataset Contributors API', function () {
    it('loads contributors from database for dataset 4 with mixed person types', function () {
        // Dataset 4 contains 4 persons: 2 with firstname/lastname, 2 with name only
        $response = $this->getJson('/old-datasets/4/contributors');

        $response->assertOk()
            ->assertJsonStructure([
                'contributors' => [
                    '*' => [
                        'type',
                        'givenName',
                        'familyName',
                        'name',
                        'institutionName',
                        'affiliations' => [
                            '*' => ['value', 'rorId'],
                        ],
                        'roles',
                        'orcid',
                        'orcidType',
                    ],
                ],
            ])
            ->assertJsonCount(4, 'contributors');

        // First contributor: Person with firstname/lastname
        $response->assertJsonPath('contributors.0.type', 'person')
            ->assertJsonPath('contributors.0.givenName', 'Franz')
            ->assertJsonPath('contributors.0.familyName', 'Barthelmes')
            ->assertJsonPath('contributors.0.orcid', '0000-0001-5253-2859')
            ->assertJsonPath('contributors.0.roles.0', 'data-curator');

        // Second contributor: Person with firstname/lastname
        $response->assertJsonPath('contributors.1.type', 'person')
            ->assertJsonPath('contributors.1.givenName', 'Sven')
            ->assertJsonPath('contributors.1.familyName', 'Reißland')
            ->assertJsonPath('contributors.1.orcid', '0000-0001-6293-5336')
            ->assertJsonPath('contributors.1.roles.0', 'data-manager');

        // Third contributor: Person with name only (requires frontend parsing)
        $response->assertJsonPath('contributors.2.type', 'person')
            ->assertJsonPath('contributors.2.name', 'Förste, Christoph')
            ->assertJsonPath('contributors.2.givenName', null)
            ->assertJsonPath('contributors.2.familyName', null)
            ->assertJsonPath('contributors.2.roles.0', 'contact-person');

        // Fourth contributor: Person with name only
        $response->assertJsonPath('contributors.3.type', 'person')
            ->assertJsonPath('contributors.3.name', 'Bruinsma, Sean.L.')
            ->assertJsonPath('contributors.3.givenName', null)
            ->assertJsonPath('contributors.3.familyName', null)
            ->assertJsonPath('contributors.3.roles.0', 'contact-person');
    });

    it('loads contributors from database for dataset 8 with institution and persons', function () {
        // Dataset 8 contains 1 institution + 6 persons (with duplicates)
        $response = $this->getJson('/old-datasets/8/contributors');

        $response->assertOk()
            ->assertJsonCount(7, 'contributors');

        // First contributor: Institution (Distributor role)
        $response->assertJsonPath('contributors.0.type', 'institution')
            ->assertJsonPath('contributors.0.institutionName', 'Centre for Early Warning System')
            ->assertJsonPath('contributors.0.roles.0', 'distributor');

        // Second contributor: Person with name only
        $response->assertJsonPath('contributors.1.type', 'person')
            ->assertJsonPath('contributors.1.name', 'Ullah, Shahid')
            ->assertJsonPath('contributors.1.roles.0', 'contact-person');

        // Third contributor: Person with firstname/lastname and ORCID
        $response->assertJsonPath('contributors.2.type', 'person')
            ->assertJsonPath('contributors.2.givenName', 'Dino')
            ->assertJsonPath('contributors.2.familyName', 'Bindi')
            ->assertJsonPath('contributors.2.orcid', '0000-0002-8619-2220')
            ->assertJsonPath('contributors.2.roles.0', 'contact-person');

        // Fourth contributor: Person with ORCID but name only
        $response->assertJsonPath('contributors.3.type', 'person')
            ->assertJsonPath('contributors.3.name', 'Pittore, Massimiliano')
            ->assertJsonPath('contributors.3.orcid', '0000-0003-4940-3444')
            ->assertJsonPath('contributors.3.roles.0', 'data-curator');
    });

    it('loads contributors from database for dataset 2396 with institution', function () {
        // Dataset 2396 contains 1 institution + 1 person
        $response = $this->getJson('/old-datasets/2396/contributors');

        $response->assertOk()
            ->assertJsonCount(2, 'contributors');

        // First contributor: Institution with HostingInstitution role
        $response->assertJsonPath('contributors.0.type', 'institution')
            ->assertJsonPath('contributors.0.institutionName', 'CELTIC - Cardiff Earth Laboratory for Trace Element and Isotope Chemistry, School of Earth and Environmental Sciences, Cardiff University, UK')
            ->assertJsonPath('contributors.0.roles.0', 'hosting-institution');

        // Second contributor: Person
        $response->assertJsonPath('contributors.1.type', 'person')
            ->assertJsonPath('contributors.1.name', 'Spencer, Laura M.')
            ->assertJsonPath('contributors.1.roles.0', 'contact-person');
    });

    it('correctly maps roles from old to new database format', function () {
        // Dataset 4 has various role types
        $response = $this->getJson('/old-datasets/4/contributors');

        $response->assertOk();

        $contributors = $response->json('contributors');
        $allRoles = collect($contributors)->pluck('roles')->flatten()->unique()->values();

        // Check that old roles are mapped to new format
        expect($allRoles->toArray())->toContain('data-curator', 'data-manager', 'contact-person');
        expect($allRoles->toArray())->not->toContain('DataCurator', 'DataManager', 'ContactPerson');
    });

    it('includes affiliations with ROR IDs where available', function () {
        // Dataset 4 has affiliations with ROR IDs
        $response = $this->getJson('/old-datasets/4/contributors');

        $response->assertOk();

        $firstContributor = $response->json('contributors.0');
        expect($firstContributor['affiliations'])->not->toBeEmpty();
        expect($firstContributor['affiliations'][0])->toHaveKey('value');
        expect($firstContributor['affiliations'][0])->toHaveKey('rorId');
    });

    it('handles contributors without ORCIDs gracefully', function () {
        $response = $this->getJson('/old-datasets/4/contributors');

        $response->assertOk();

        // Third and fourth contributors have no ORCID
        $response->assertJsonPath('contributors.2.orcid', null)
            ->assertJsonPath('contributors.2.orcidType', null)
            ->assertJsonPath('contributors.3.orcid', null)
            ->assertJsonPath('contributors.3.orcidType', null);
    });

    it('preserves contributor order by agent_order', function () {
        $response = $this->getJson('/old-datasets/4/contributors');

        $response->assertOk();

        $contributors = $response->json('contributors');

        // Verify the order matches expected order
        expect($contributors[0]['familyName'])->toBe('Barthelmes');
        expect($contributors[1]['familyName'])->toBe('Reißland');
        expect($contributors[2]['name'])->toContain('Förste');
        expect($contributors[3]['name'])->toContain('Bruinsma');
    });

    it('returns 404 for non-existent dataset', function () {
        $response = $this->getJson('/old-datasets/999999/contributors');

        $response->assertNotFound();
    });

    it('handles duplicate contributors in same dataset', function () {
        // Dataset 8 has duplicate contributors (Ullah, Shahid appears 2x; Pittore, Massimiliano appears 2x)
        $response = $this->getJson('/old-datasets/8/contributors');

        $response->assertOk()
            ->assertJsonCount(7, 'contributors'); // All contributors including duplicates

        $contributors = $response->json('contributors');
        $names = collect($contributors)->pluck('name')->filter();

        // Count occurrences
        $ullahCount = $names->filter(fn ($name) => str_contains($name, 'Ullah, Shahid'))->count();
        $pittoreCount = $names->filter(fn ($name) => str_contains($name, 'Pittore, Massimiliano'))->count();

        expect($ullahCount)->toBe(2);
        expect($pittoreCount)->toBe(2);
    });
});
