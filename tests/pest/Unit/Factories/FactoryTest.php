<?php

declare(strict_types=1);

use App\Enums\ContributorCategory;
use App\Models\ContactMessage;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\Language;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceInstrument;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use App\Models\User;
use Database\Factories\ContributorTypeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// ContactMessageFactory
// ---------------------------------------------------------------------------
describe('ContactMessageFactory', function () {
    it('creates a contact message with default attributes', function () {
        $message = ContactMessage::factory()->create();

        expect($message->resource_id)->not->toBeNull()
            ->and($message->sender_name)->toBeString()
            ->and($message->sender_email)->toContain('@')
            ->and($message->message)->toBeString()
            ->and($message->ip_address)->toBeString();
    });

    it('creates a pending message', function () {
        $message = ContactMessage::factory()->pending()->create();

        expect($message->sent_at)->toBeNull();
    });

    it('creates a sent message', function () {
        $message = ContactMessage::factory()->sent()->create();

        expect($message->sent_at)->not->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// ContributorTypeFactory
// ---------------------------------------------------------------------------
describe('ContributorTypeFactory', function () {
    it('creates a contributor type with default attributes', function () {
        /** @var ContributorType $type */
        $type = ContributorTypeFactory::new()->create();

        expect($type->name)->toBeString()
            ->and($type->slug)->toBeString()
            ->and($type->category)->toBeInstanceOf(ContributorCategory::class)
            ->and($type->is_active)->toBeTrue()
            ->and($type->is_elmo_active)->toBeTrue();
    });

    it('creates a contact person type', function () {
        /** @var ContributorType $type */
        $type = ContributorTypeFactory::new()->contactPerson()->create();

        expect($type->name)->toBe('Contact Person')
            ->and($type->slug)->toBe('ContactPerson')
            ->and($type->category)->toBe(ContributorCategory::PERSON);
    });

    it('creates a researcher type', function () {
        /** @var ContributorType $type */
        $type = ContributorTypeFactory::new()->researcher()->create();

        expect($type->name)->toBe('Researcher')
            ->and($type->category)->toBe(ContributorCategory::PERSON);
    });

    it('creates an institution category type', function () {
        /** @var ContributorType $type */
        $type = ContributorTypeFactory::new()->institution()->create();

        expect($type->category)->toBe(ContributorCategory::INSTITUTION);
    });

    it('creates a both category type', function () {
        /** @var ContributorType $type */
        $type = ContributorTypeFactory::new()->both()->create();

        expect($type->category)->toBe(ContributorCategory::BOTH);
    });

    it('creates an inactive type', function () {
        /** @var ContributorType $type */
        $type = ContributorTypeFactory::new()->inactive()->create();

        expect($type->is_active)->toBeFalse();
    });

    it('creates an elmo inactive type', function () {
        /** @var ContributorType $type */
        $type = ContributorTypeFactory::new()->elmoInactive()->create();

        expect($type->is_elmo_active)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// DateTypeFactory
// ---------------------------------------------------------------------------
describe('DateTypeFactory', function () {
    it('creates a date type with default attributes', function () {
        $type = DateType::factory()->create();

        expect($type->name)->toBeString()
            ->and($type->slug)->toBeString()
            ->and($type->is_active)->toBeTrue();
    });

    it('creates an inactive date type', function () {
        $type = DateType::factory()->inactive()->create();

        expect($type->is_active)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// DescriptionFactory
// ---------------------------------------------------------------------------
describe('DescriptionFactory', function () {
    it('creates a description with default attributes', function () {
        $desc = Description::factory()->create();

        expect($desc->value)->toBeString()
            ->and($desc->language)->toBe('en')
            ->and($desc->resource_id)->not->toBeNull()
            ->and($desc->description_type_id)->not->toBeNull();
    });

    it('creates an abstract description', function () {
        $desc = Description::factory()->abstract()->create();
        $type = DescriptionType::findOrFail($desc->description_type_id);

        expect($type->slug)->toBe('Abstract');
    });

    it('creates a methods description', function () {
        $desc = Description::factory()->methods()->create();
        $type = DescriptionType::findOrFail($desc->description_type_id);

        expect($type->slug)->toBe('Methods');
    });

    it('creates a technical info description', function () {
        $desc = Description::factory()->technicalInfo()->create();
        $type = DescriptionType::findOrFail($desc->description_type_id);

        expect($type->slug)->toBe('TechnicalInfo');
    });
});

// ---------------------------------------------------------------------------
// GeoLocationFactory
// ---------------------------------------------------------------------------
describe('GeoLocationFactory', function () {
    it('creates a geo location with default attributes', function () {
        $geo = GeoLocation::factory()->create();

        expect($geo->place)->toBeString()
            ->and($geo->resource_id)->not->toBeNull()
            ->and($geo->point_longitude)->toBeNull()
            ->and($geo->point_latitude)->toBeNull();
    });

    it('creates a geo location with a point', function () {
        $geo = GeoLocation::factory()->withPoint(13.0658, 52.3792)->create();

        expect((float) $geo->point_longitude)->toBe(13.0658)
            ->and((float) $geo->point_latitude)->toBe(52.3792);
    });

    it('creates a geo location with a bounding box', function () {
        $geo = GeoLocation::factory()->withBox(-10.0, 20.0, -5.0, 15.0)->create();

        expect((float) $geo->west_bound_longitude)->toBe(-10.0)
            ->and((float) $geo->east_bound_longitude)->toBe(20.0)
            ->and((float) $geo->south_bound_latitude)->toBe(-5.0)
            ->and((float) $geo->north_bound_latitude)->toBe(15.0);
    });

    it('creates a geo location with a polygon', function () {
        $geo = GeoLocation::factory()->withPolygon()->create();

        expect($geo->polygon_points)->toBeArray();
        expect(count((array) $geo->polygon_points))->toBeGreaterThanOrEqual(3);
    });

    it('creates a geo location with a line', function () {
        $geo = GeoLocation::factory()->withLine()->create();

        expect($geo->polygon_points)->toBeArray();
        expect(count((array) $geo->polygon_points))->toBeGreaterThanOrEqual(2);
    });
});

// ---------------------------------------------------------------------------
// InstitutionFactory
// ---------------------------------------------------------------------------
describe('InstitutionFactory', function () {
    it('creates an institution with default attributes', function () {
        $inst = Institution::factory()->create();

        expect($inst->name)->toBeString()
            ->and($inst->name_identifier)->toBeNull()
            ->and($inst->name_identifier_scheme)->toBeNull();
    });

    it('creates an institution with ROR', function () {
        $inst = Institution::factory()->withRor()->create();

        expect($inst->name_identifier)->toStartWith('https://ror.org/')
            ->and($inst->name_identifier_scheme)->toBe('ROR')
            ->and($inst->scheme_uri)->toBe('https://ror.org/');
    });

    it('creates an institution with a specific ROR', function () {
        $inst = Institution::factory()->withRor('https://ror.org/04z8jg394')->create();

        expect($inst->name_identifier)->toBe('https://ror.org/04z8jg394');
    });
});

// ---------------------------------------------------------------------------
// LandingPageFactory
// ---------------------------------------------------------------------------
describe('LandingPageFactory', function () {
    it('creates a landing page with default attributes', function () {
        $lp = LandingPage::factory()->create();

        expect($lp->resource_id)->not->toBeNull()
            ->and($lp->slug)->toBeString()
            ->and($lp->template)->toBe('default_gfz')
            ->and($lp->preview_token)->toHaveLength(64);
    });

    it('creates a draft landing page', function () {
        $lp = LandingPage::factory()->draft()->create();

        expect($lp->is_published)->toBeFalse()
            ->and($lp->published_at)->toBeNull();
    });

    it('creates a published landing page', function () {
        $lp = LandingPage::factory()->published()->create();

        expect($lp->is_published)->toBeTrue()
            ->and($lp->published_at)->not->toBeNull();
    });

    it('creates a landing page without DOI', function () {
        $lp = LandingPage::factory()->withoutDoi()->create();

        expect($lp->doi_prefix)->toBeNull();
    });

    it('creates a landing page with a specific DOI', function () {
        $lp = LandingPage::factory()->withDoi('10.5880/gfz.2025.001')->create();

        expect($lp->doi_prefix)->toBe('10.5880/gfz.2025.001');
    });

    it('creates an external landing page', function () {
        $lp = LandingPage::factory()->external()->create();

        expect($lp->template)->toBe('external')
            ->and($lp->external_domain_id)->not->toBeNull()
            ->and($lp->external_path)->toBeString();
    });
});

// ---------------------------------------------------------------------------
// LandingPageDomainFactory
// ---------------------------------------------------------------------------
describe('LandingPageDomainFactory', function () {
    it('creates a domain with default attributes', function () {
        $domain = LandingPageDomain::factory()->create();

        expect($domain->domain)->toStartWith('https://');
    });

    it('creates a domain with a specific URL', function () {
        $domain = LandingPageDomain::factory()->withDomain('https://example.com/')->create();

        expect($domain->domain)->toBe('https://example.com/');
    });
});

// ---------------------------------------------------------------------------
// PersonFactory
// ---------------------------------------------------------------------------
describe('PersonFactory', function () {
    it('creates a person with default attributes', function () {
        $person = Person::factory()->create();

        expect($person->given_name)->toBeString()
            ->and($person->family_name)->toBeString()
            ->and($person->name_identifier)->toBeNull();
    });

    it('creates a person with ORCID', function () {
        $person = Person::factory()->withOrcid()->create();

        expect($person->name_identifier)->toStartWith('https://orcid.org/')
            ->and($person->name_identifier_scheme)->toBe('ORCID')
            ->and($person->scheme_uri)->toBe('https://orcid.org/');
    });

    it('creates a person with specific ORCID', function () {
        $person = Person::factory()->withOrcid('https://orcid.org/0000-0001-2345-6789')->create();

        expect($person->name_identifier)->toBe('https://orcid.org/0000-0001-2345-6789');
    });
});

// ---------------------------------------------------------------------------
// PublisherFactory
// ---------------------------------------------------------------------------
describe('PublisherFactory', function () {
    it('creates a publisher with default attributes', function () {
        $pub = Publisher::factory()->create();

        expect($pub->name)->toBeString()
            ->and($pub->language)->toBe('en')
            ->and($pub->is_default)->toBeFalse();
    });

    it('creates a GFZ publisher', function () {
        $pub = Publisher::factory()->gfz()->create();

        expect($pub->name)->toBe('GFZ Data Services')
            ->and($pub->identifier_scheme)->toBe('re3data')
            ->and($pub->is_default)->toBeTrue();
    });

    it('creates a default publisher', function () {
        $pub = Publisher::factory()->default()->create();

        expect($pub->is_default)->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// ResourceFactory
// ---------------------------------------------------------------------------
describe('ResourceFactory', function () {
    it('creates a resource with default attributes', function () {
        $resource = Resource::factory()->create();

        expect($resource->doi)->toBeString()
            ->and($resource->identifier_type)->toBe('DOI')
            ->and($resource->publication_year)->toBeInt()
            ->and($resource->resource_type_id)->not->toBeNull()
            ->and($resource->language_id)->not->toBeNull()
            ->and($resource->publisher_id)->not->toBeNull();
    });

    it('creates a resource with specific DOI', function () {
        $resource = Resource::factory()->withDoi('10.5880/gfz.test.001')->create();

        expect($resource->doi)->toBe('10.5880/gfz.test.001');
    });

    it('creates a resource with specific publication year', function () {
        $resource = Resource::factory()->withPublicationYear(2025)->create();

        expect($resource->publication_year)->toBe(2025);
    });
});

// ---------------------------------------------------------------------------
// ResourceCreatorFactory
// ---------------------------------------------------------------------------
describe('ResourceCreatorFactory', function () {
    it('creates a resource creator with default attributes', function () {
        $creator = ResourceCreator::factory()->create();

        expect($creator->resource_id)->not->toBeNull()
            ->and($creator->creatorable_type)->toBe(Person::class)
            ->and($creator->creatorable_id)->not->toBeNull()
            ->and($creator->position)->toBe(1);
    });

    it('creates a creator for a specific person', function () {
        $person = Person::factory()->create(['family_name' => 'Einstein']);
        $creator = ResourceCreator::factory()->forPerson($person)->create();

        expect($creator->creatorable_type)->toBe(Person::class)
            ->and($creator->creatorable_id)->toBe($person->id);
    });

    it('creates a creator for an institution', function () {
        $inst = Institution::factory()->create(['name' => 'GFZ']);
        $creator = ResourceCreator::factory()->forInstitution($inst)->create();

        expect($creator->creatorable_type)->toBe(Institution::class)
            ->and($creator->creatorable_id)->toBe($inst->id);
    });

    it('creates a creator at a specific position', function () {
        $creator = ResourceCreator::factory()->position(3)->create();

        expect($creator->position)->toBe(3);
    });
});

// ---------------------------------------------------------------------------
// ResourceContributorFactory
// ---------------------------------------------------------------------------
describe('ResourceContributorFactory', function () {
    it('creates a resource contributor with default attributes', function () {
        $contributor = ResourceContributor::factory()->create();

        expect($contributor->resource_id)->not->toBeNull()
            ->and($contributor->contributorable_type)->toBe(Person::class)
            ->and($contributor->contributorable_id)->not->toBeNull();
    });

    it('creates a contributor for a specific person', function () {
        $person = Person::factory()->create(['family_name' => 'Curie']);
        $contributor = ResourceContributor::factory()->forPerson($person)->create();

        expect($contributor->contributorable_type)->toBe(Person::class)
            ->and($contributor->contributorable_id)->toBe($person->id);
    });

    it('creates a contributor for an institution', function () {
        $inst = Institution::factory()->create(['name' => 'DKRZ']);
        $contributor = ResourceContributor::factory()->forInstitution($inst)->create();

        expect($contributor->contributorable_type)->toBe(Institution::class)
            ->and($contributor->contributorable_id)->toBe($inst->id);
    });

    it('creates a contributor at a specific position', function () {
        $contributor = ResourceContributor::factory()->atPosition(5)->create();

        expect($contributor->position)->toBe(5);
    });
});

// ---------------------------------------------------------------------------
// ResourceInstrumentFactory
// ---------------------------------------------------------------------------
describe('ResourceInstrumentFactory', function () {
    it('creates a resource instrument with default attributes', function () {
        $instrument = ResourceInstrument::factory()->create();

        expect($instrument->resource_id)->not->toBeNull()
            ->and($instrument->instrument_pid)->toStartWith('http://hdl.handle.net/')
            ->and($instrument->instrument_pid_type)->toBe('Handle')
            ->and($instrument->instrument_name)->toBeString();
    });
});

// ---------------------------------------------------------------------------
// RightFactory
// ---------------------------------------------------------------------------
describe('RightFactory', function () {
    it('creates a right with default attributes', function () {
        $right = Right::factory()->create();

        expect($right->identifier)->toBeString()
            ->and($right->name)->toBeString()
            ->and($right->uri)->toStartWith('https://')
            ->and($right->is_active)->toBeTrue();
    });

    it('creates a CC-BY-4.0 license', function () {
        $right = Right::factory()->ccBy4()->create();

        expect($right->identifier)->toBe('CC-BY-4.0')
            ->and($right->name)->toContain('Creative Commons Attribution');
    });

    it('creates a CC0 license', function () {
        $right = Right::factory()->cc0()->create();

        expect($right->identifier)->toBe('CC0-1.0');
    });

    it('creates a MIT license', function () {
        $right = Right::factory()->mit()->create();

        expect($right->identifier)->toBe('MIT');
    });
});

// ---------------------------------------------------------------------------
// SubjectFactory
// ---------------------------------------------------------------------------
describe('SubjectFactory', function () {
    it('creates a subject with default attributes', function () {
        $subject = Subject::factory()->create();

        expect($subject->value)->toBeString()
            ->and($subject->language)->toBe('en')
            ->and($subject->subject_scheme)->toBeNull();
    });

    it('creates a GCMD keyword', function () {
        $subject = Subject::factory()->gcmd()->create();

        expect($subject->subject_scheme)->toBe('GCMD Science Keywords')
            ->and($subject->scheme_uri)->toContain('gcmd.earthdata.nasa.gov');
    });

    it('creates an MSL keyword', function () {
        $subject = Subject::factory()->msl()->create();

        expect($subject->subject_scheme)->toBe('EPOS MSL vocabulary')
            ->and($subject->scheme_uri)->toContain('epos-msl.uu.nl');
    });
});

// ---------------------------------------------------------------------------
// TitleFactory
// ---------------------------------------------------------------------------
describe('TitleFactory', function () {
    it('creates a title with default attributes', function () {
        $title = Title::factory()->create();

        expect($title->value)->toBeString()
            ->and($title->language)->toBe('en')
            ->and($title->title_type_id)->not->toBeNull();
    });

    it('creates an alternative title', function () {
        $title = Title::factory()->alternativeTitle()->create();
        $type = TitleType::findOrFail($title->title_type_id);

        expect($type->slug)->toBe('AlternativeTitle');
    });

    it('creates a subtitle', function () {
        $title = Title::factory()->subtitle()->create();
        $type = TitleType::findOrFail($title->title_type_id);

        expect($type->slug)->toBe('Subtitle');
    });

    it('creates a translated title', function () {
        $title = Title::factory()->translatedTitle()->create();
        $type = TitleType::findOrFail($title->title_type_id);

        expect($type->slug)->toBe('TranslatedTitle');
    });
});

// ---------------------------------------------------------------------------
// UserFactory
// ---------------------------------------------------------------------------
describe('UserFactory', function () {
    it('creates a user with default attributes', function () {
        $user = User::factory()->create();

        expect($user->name)->toBeString()
            ->and($user->email)->toContain('@')
            ->and($user->is_active)->toBeTrue();
    });

    it('creates an admin user', function () {
        $user = User::factory()->admin()->create();

        expect($user->role->value)->toBe('admin');
    });

    it('creates a deactivated user', function () {
        $user = User::factory()->deactivated()->create();

        expect($user->is_active)->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// LanguageFactory
// ---------------------------------------------------------------------------
describe('LanguageFactory', function () {
    it('creates a language with default attributes', function () {
        $lang = Language::factory()->create();

        expect($lang->code)->toBeString()
            ->and($lang->name)->toBeString()
            ->and($lang->active)->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// ResourceTypeFactory
// ---------------------------------------------------------------------------
describe('ResourceTypeFactory', function () {
    it('creates a resource type with default attributes', function () {
        $type = ResourceType::factory()->create();

        expect($type->name)->toBeString()
            ->and($type->slug)->toBeString()
            ->and($type->is_active)->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// TitleTypeFactory
// ---------------------------------------------------------------------------
describe('TitleTypeFactory', function () {
    it('creates a title type with default attributes', function () {
        $type = TitleType::factory()->create();

        expect($type->name)->toBeString()
            ->and($type->slug)->toBeString();
    });
});
