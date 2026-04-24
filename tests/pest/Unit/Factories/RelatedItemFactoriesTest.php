<?php

declare(strict_types=1);

use App\Models\RelatedItem;
use App\Models\RelatedItemContributor;
use App\Models\RelatedItemContributorAffiliation;
use App\Models\RelatedItemCreator;
use App\Models\RelatedItemCreatorAffiliation;
use App\Models\RelatedItemTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// RelatedItemFactory
// ---------------------------------------------------------------------------
describe('RelatedItemFactory', function () {
    it('creates a related item with default attributes', function () {
        /** @var RelatedItem $item */
        $item = RelatedItem::factory()->create();

        expect($item->resource_id)->not->toBeNull()
            ->and($item->related_item_type)->toBe('JournalArticle')
            ->and($item->relation_type_id)->not->toBeNull()
            ->and($item->publication_year)->toBeInt()
            ->and($item->volume)->toBeString()
            ->and($item->issue)->toBeString()
            ->and($item->first_page)->toBeString()
            ->and($item->last_page)->toBeString()
            ->and($item->publisher)->toBeString()
            ->and($item->position)->toBe(0)
            ->and($item->identifier)->toBeNull()
            ->and($item->identifier_type)->toBeNull();
    });

    it('reuses the Cites relation type across invocations', function () {
        $a = RelatedItem::factory()->create();
        $b = RelatedItem::factory()->create();

        expect($a->relation_type_id)->toBe($b->relation_type_id);
    });

    it('applies the withIdentifier state with defaults', function () {
        /** @var RelatedItem $item */
        $item = RelatedItem::factory()->withIdentifier()->create();

        expect($item->identifier)->toBe('10.1234/example')
            ->and($item->identifier_type)->toBe('DOI');
    });

    it('applies the withIdentifier state with custom values', function () {
        /** @var RelatedItem $item */
        $item = RelatedItem::factory()
            ->withIdentifier('https://example.org/123', 'URL')
            ->create();

        expect($item->identifier)->toBe('https://example.org/123')
            ->and($item->identifier_type)->toBe('URL');
    });

    it('applies the book state', function () {
        /** @var RelatedItem $item */
        $item = RelatedItem::factory()->book()->create();

        expect($item->related_item_type)->toBe('Book')
            ->and($item->volume)->toBeNull()
            ->and($item->issue)->toBeNull()
            ->and($item->edition)->toBe('1st');
    });
});

// ---------------------------------------------------------------------------
// RelatedItemTitleFactory
// ---------------------------------------------------------------------------
describe('RelatedItemTitleFactory', function () {
    it('creates a MainTitle by default', function () {
        /** @var RelatedItemTitle $title */
        $title = RelatedItemTitle::factory()->create();

        expect($title->title_type)->toBe('MainTitle')
            ->and($title->language)->toBe('en')
            ->and($title->title)->toBeString()
            ->and($title->related_item_id)->not->toBeNull();
    });

    it('applies the subtitle state', function () {
        /** @var RelatedItemTitle $title */
        $title = RelatedItemTitle::factory()->subtitle()->create();

        expect($title->title_type)->toBe('Subtitle');
    });
});

// ---------------------------------------------------------------------------
// RelatedItemCreatorFactory
// ---------------------------------------------------------------------------
describe('RelatedItemCreatorFactory', function () {
    it('creates a Personal creator by default', function () {
        /** @var RelatedItemCreator $creator */
        $creator = RelatedItemCreator::factory()->create();

        expect($creator->name_type)->toBe('Personal')
            ->and($creator->given_name)->toBeString()
            ->and($creator->family_name)->toBeString()
            ->and($creator->name)->toContain(', ')
            ->and($creator->name_identifier)->toBeNull()
            ->and($creator->name_identifier_scheme)->toBeNull();
    });

    it('applies the organizational state without explicit name', function () {
        /** @var RelatedItemCreator $creator */
        $creator = RelatedItemCreator::factory()->organizational()->create();

        expect($creator->name_type)->toBe('Organizational')
            ->and($creator->given_name)->toBeNull()
            ->and($creator->family_name)->toBeNull()
            ->and($creator->name)->toBeString()
            ->and($creator->name)->not->toBe('');
    });

    it('applies the organizational state with a custom name', function () {
        /** @var RelatedItemCreator $creator */
        $creator = RelatedItemCreator::factory()
            ->organizational('GFZ Helmholtz Centre')
            ->create();

        expect($creator->name_type)->toBe('Organizational')
            ->and($creator->name)->toBe('GFZ Helmholtz Centre')
            ->and($creator->given_name)->toBeNull();
    });

    it('applies the withOrcid state with defaults', function () {
        /** @var RelatedItemCreator $creator */
        $creator = RelatedItemCreator::factory()->withOrcid()->create();

        expect($creator->name_identifier)->toBe('0000-0002-1825-0097')
            ->and($creator->name_identifier_scheme)->toBe('ORCID')
            ->and($creator->scheme_uri)->toBe('https://orcid.org');
    });

    it('applies the withOrcid state with a custom ORCID', function () {
        /** @var RelatedItemCreator $creator */
        $creator = RelatedItemCreator::factory()
            ->withOrcid('0000-0001-2345-6789')
            ->create();

        expect($creator->name_identifier)->toBe('0000-0001-2345-6789')
            ->and($creator->name_identifier_scheme)->toBe('ORCID');
    });
});

// ---------------------------------------------------------------------------
// RelatedItemCreatorAffiliationFactory
// ---------------------------------------------------------------------------
describe('RelatedItemCreatorAffiliationFactory', function () {
    it('creates a plain affiliation by default', function () {
        /** @var RelatedItemCreatorAffiliation $aff */
        $aff = RelatedItemCreatorAffiliation::factory()->create();

        expect($aff->name)->toBeString()
            ->and($aff->affiliation_identifier)->toBeNull()
            ->and($aff->scheme)->toBeNull()
            ->and($aff->scheme_uri)->toBeNull()
            ->and($aff->related_item_creator_id)->not->toBeNull();
    });

    it('applies the withRor state with defaults', function () {
        /** @var RelatedItemCreatorAffiliation $aff */
        $aff = RelatedItemCreatorAffiliation::factory()->withRor()->create();

        expect($aff->affiliation_identifier)->toBe('https://ror.org/04wxnsj81')
            ->and($aff->scheme)->toBe('ROR')
            ->and($aff->scheme_uri)->toBe('https://ror.org');
    });

    it('applies the withRor state with a custom ROR identifier', function () {
        /** @var RelatedItemCreatorAffiliation $aff */
        $aff = RelatedItemCreatorAffiliation::factory()
            ->withRor('https://ror.org/012345678')
            ->create();

        expect($aff->affiliation_identifier)->toBe('https://ror.org/012345678')
            ->and($aff->scheme)->toBe('ROR');
    });
});

// ---------------------------------------------------------------------------
// RelatedItemContributorFactory
// ---------------------------------------------------------------------------
describe('RelatedItemContributorFactory', function () {
    it('creates an Editor contributor by default', function () {
        /** @var RelatedItemContributor $contrib */
        $contrib = RelatedItemContributor::factory()->create();

        expect($contrib->contributor_type)->toBe('Editor')
            ->and($contrib->name_type)->toBe('Personal')
            ->and($contrib->given_name)->toBeString()
            ->and($contrib->family_name)->toBeString()
            ->and($contrib->name)->toContain(', ')
            ->and($contrib->related_item_id)->not->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// RelatedItemContributorAffiliationFactory
// ---------------------------------------------------------------------------
describe('RelatedItemContributorAffiliationFactory', function () {
    it('creates a plain contributor affiliation by default', function () {
        /** @var RelatedItemContributorAffiliation $aff */
        $aff = RelatedItemContributorAffiliation::factory()->create();

        expect($aff->name)->toBeString()
            ->and($aff->related_item_contributor_id)->not->toBeNull()
            ->and($aff->position)->toBe(0);
    });
});
