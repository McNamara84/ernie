<?php

use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\IdentifierType;
use App\Models\RelationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed all relevant seeders
    $this->artisan('db:seed', ['--class' => 'DateTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ContributorTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'IdentifierTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'RelationTypeSeeder']);
});

describe('DataCite Schema 4.6 Compliance', function () {
    describe('DateType', function () {
        it('has all required dateTypes including Coverage', function () {
            $required = [
                'Accepted',
                'Available',
                'Copyrighted',
                'Collected',
                'Coverage',  // New in Schema 4.6
                'Created',
                'Issued',
                'Submitted',
                'Updated',
                'Valid',
                'Withdrawn',
                'Other',
            ];

            foreach ($required as $slug) {
                expect(DateType::where('slug', $slug)->exists())
                    ->toBeTrue("Missing dateType: {$slug}");
            }
        });

        it('has Coverage dateType for temporal content coverage', function () {
            $coverage = DateType::where('slug', 'Coverage')->first();

            expect($coverage)->not->toBeNull();
            expect($coverage->name)->toBe('Coverage');
        });
    });

    describe('ContributorType', function () {
        it('has Translator contributorType', function () {
            $translator = ContributorType::where('slug', 'Translator')->first();

            expect($translator)->not->toBeNull();
            expect($translator->name)->toBe('Translator');
        });

        it('has all required contributorTypes', function () {
            $required = [
                'ContactPerson',
                'DataCollector',
                'DataCurator',
                'DataManager',
                'Distributor',
                'Editor',
                'HostingInstitution',
                'Producer',
                'ProjectLeader',
                'ProjectManager',
                'ProjectMember',
                'RegistrationAgency',
                'RegistrationAuthority',
                'RelatedPerson',
                'Researcher',
                'ResearchGroup',
                'RightsHolder',
                'Sponsor',
                'Supervisor',
                'Translator',  // New in Schema 4.6
                'WorkPackageLeader',
                'Other',
            ];

            foreach ($required as $slug) {
                expect(ContributorType::where('slug', $slug)->exists())
                    ->toBeTrue("Missing contributorType: {$slug}");
            }
        });
    });

    describe('IdentifierType (relatedIdentifierType)', function () {
        it('has CSTR identifierType', function () {
            $cstr = IdentifierType::where('slug', 'CSTR')->first();

            expect($cstr)->not->toBeNull();
            expect($cstr->name)->toBe('CSTR');
        });

        it('has RRID identifierType', function () {
            $rrid = IdentifierType::where('slug', 'RRID')->first();

            expect($rrid)->not->toBeNull();
            expect($rrid->name)->toBe('RRID');
        });

        it('has all required identifierTypes', function () {
            $required = [
                'ARK',
                'arXiv',
                'bibcode',
                'CSTR',  // New in Schema 4.6
                'DOI',
                'EAN13',
                'EISSN',
                'Handle',
                'IGSN',
                'ISBN',
                'ISSN',
                'ISTC',
                'LISSN',
                'LSID',
                'PMID',
                'PURL',
                'RRID',  // New in Schema 4.6
                'UPC',
                'URL',
                'URN',
                'w3id',
            ];

            foreach ($required as $slug) {
                expect(IdentifierType::where('slug', $slug)->exists())
                    ->toBeTrue("Missing identifierType: {$slug}");
            }
        });
    });

    describe('RelationType', function () {
        it('has HasTranslation relationType', function () {
            $hasTranslation = RelationType::where('slug', 'HasTranslation')->first();

            expect($hasTranslation)->not->toBeNull();
            expect($hasTranslation->name)->toBe('Has Translation');
        });

        it('has IsTranslationOf relationType', function () {
            $isTranslationOf = RelationType::where('slug', 'IsTranslationOf')->first();

            expect($isTranslationOf)->not->toBeNull();
            expect($isTranslationOf->name)->toBe('Is Translation Of');
        });

        it('has all required relationTypes', function () {
            $required = [
                'IsCitedBy',
                'Cites',
                'IsSupplementTo',
                'IsSupplementedBy',
                'IsTranslationOf',  // New in Schema 4.6
                'IsContinuedBy',
                'Continues',
                'IsDescribedBy',
                'Describes',
                'HasMetadata',
                'IsMetadataFor',
                'HasVersion',
                'IsVersionOf',
                'IsNewVersionOf',
                'IsPreviousVersionOf',
                'IsPartOf',
                'HasPart',
                'HasTranslation',  // New in Schema 4.6
                'IsPublishedIn',
                'IsReferencedBy',
                'References',
                'IsDocumentedBy',
                'Documents',
                'IsCompiledBy',
                'Compiles',
                'IsVariantFormOf',
                'IsOriginalFormOf',
                'IsIdenticalTo',
                'IsReviewedBy',
                'Reviews',
                'IsDerivedFrom',
                'IsSourceOf',
                'IsRequiredBy',
                'Requires',
                'IsObsoletedBy',
                'Obsoletes',
                'IsCollectedBy',
                'Collects',
            ];

            foreach ($required as $slug) {
                expect(RelationType::where('slug', $slug)->exists())
                    ->toBeTrue("Missing relationType: {$slug}");
            }
        });
    });
});
