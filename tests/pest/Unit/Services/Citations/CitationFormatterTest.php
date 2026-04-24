<?php

declare(strict_types=1);

use App\Models\RelatedItem;
use App\Models\RelatedItemCreator;
use App\Models\RelatedItemTitle;
use App\Services\Citations\CitationFormatter;

covers(CitationFormatter::class);

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('formats APA with two authors using ampersand', function () {
    $item = RelatedItem::factory()->create([
        'related_item_type' => 'JournalArticle',
        'publication_year' => 2023,
        'volume' => '12',
        'issue' => '3',
        'first_page' => '101',
        'last_page' => '115',
        'publisher' => 'Journal of Science',
    ]);
    RelatedItemTitle::factory()->create([
        'related_item_id' => $item->id,
        'title' => 'An Important Paper',
        'title_type' => 'MainTitle',
    ]);
    RelatedItemCreator::factory()->create([
        'related_item_id' => $item->id,
        'name' => 'Müller, Anna',
        'given_name' => 'Anna',
        'family_name' => 'Müller',
        'position' => 0,
    ]);
    RelatedItemCreator::factory()->create([
        'related_item_id' => $item->id,
        'name' => 'Schmidt, Ben',
        'given_name' => 'Ben',
        'family_name' => 'Schmidt',
        'position' => 1,
    ]);

    $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));

    expect($out)
        ->toContain('Müller, A., & Schmidt, B.')
        ->toContain('(2023)')
        ->toContain('An Important Paper')
        ->toContain('Journal of Science')
        ->toContain('12(3)')
        ->toContain('101-115');
});

it('formats an APA citation for a single-author journal article with DOI', function () {
    $item = RelatedItem::factory()->create([
        'related_item_type' => 'JournalArticle',
        'publication_year' => 2021,
        'volume' => '7',
        'issue' => '2',
        'first_page' => '50',
        'last_page' => '60',
        'publisher' => 'Earth Journal',
        'identifier' => '10.1234/xyz',
        'identifier_type' => 'DOI',
    ]);
    RelatedItemTitle::factory()->create([
        'related_item_id' => $item->id,
        'title' => 'Plate tectonics revisited',
        'title_type' => 'MainTitle',
    ]);
    RelatedItemCreator::factory()->create([
        'related_item_id' => $item->id,
        'name' => 'Doe, Jane',
        'given_name' => 'Jane',
        'family_name' => 'Doe',
    ]);

    $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));

    expect($out)->toContain('Doe, J.')
        ->toContain('(2021)')
        ->toContain('Plate tectonics revisited')
        ->toContain('Earth Journal')
        ->toContain('50-60')
        ->toContain('https://doi.org/10.1234/xyz');
});

it('formats APA with (n.d.) when publication year missing', function () {
    $item = RelatedItem::factory()->create([
        'related_item_type' => 'Book',
        'publication_year' => null,
        'publisher' => 'GFZ Press',
    ]);
    RelatedItemTitle::factory()->create([
        'related_item_id' => $item->id,
        'title' => 'Geology',
        'title_type' => 'MainTitle',
    ]);
    RelatedItemCreator::factory()->create([
        'related_item_id' => $item->id,
        'name' => 'Smith, Bob',
        'given_name' => 'Bob',
        'family_name' => 'Smith',
    ]);

    $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));
    expect($out)->toContain('(n.d.)')
        ->toContain('Geology')
        ->toContain('GFZ Press');
});

it('formats APA with [untitled] when main title missing', function () {
    $item = RelatedItem::factory()->create();

    $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));
    expect($out)->toContain('[untitled]');
});

it('uses et al. for more than 20 authors in APA', function () {
    $item = RelatedItem::factory()->create(['related_item_type' => 'JournalArticle']);
    RelatedItemTitle::factory()->create([
        'related_item_id' => $item->id,
        'title' => 'Mega paper',
        'title_type' => 'MainTitle',
    ]);
    for ($i = 0; $i < 25; $i++) {
        RelatedItemCreator::factory()->create([
            'related_item_id' => $item->id,
            'name' => "Author{$i}, F",
            'given_name' => 'F',
            'family_name' => "Author{$i}",
            'position' => $i,
        ]);
    }

    $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));
    expect($out)->toContain('…'); // APA 7: ellipsis for >20 authors
});

it('formats an organizational creator correctly', function () {
    $item = RelatedItem::factory()->create();
    RelatedItemTitle::factory()->create([
        'related_item_id' => $item->id,
        'title' => 'Annual Report',
        'title_type' => 'MainTitle',
    ]);
    RelatedItemCreator::factory()->create([
        'related_item_id' => $item->id,
        'name_type' => 'Organizational',
        'name' => 'GFZ Helmholtz Centre',
    ]);

    $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));
    expect($out)->toContain('GFZ Helmholtz Centre');
});

describe('IEEE formatting', function () {
    it('formats a journal article in IEEE', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'JournalArticle',
            'publication_year' => 2020,
            'volume' => '15',
            'issue' => '4',
            'first_page' => '200',
            'last_page' => '215',
            'publisher' => 'Geo J.',
            'identifier' => '10.1/abc',
            'identifier_type' => 'DOI',
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'Subduction',
            'title_type' => 'MainTitle',
        ]);
        RelatedItemCreator::factory()->create([
            'related_item_id' => $item->id,
            'name' => 'Doe, Jane',
            'given_name' => 'Jane',
            'family_name' => 'Doe',
        ]);

        $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']), CitationFormatter::STYLE_IEEE);

        expect($out)->toContain('J. Doe')
            ->toContain('"Subduction,"')
            ->toContain('vol. 15')
            ->toContain('no. 4')
            ->toContain('pp. 200-215')
            ->toContain('2020')
            ->toContain('doi: https://doi.org/10.1/abc');
    });

    it('uses et al. for more than 6 authors in IEEE', function () {
        $item = RelatedItem::factory()->create();
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'X',
            'title_type' => 'MainTitle',
        ]);
        for ($i = 0; $i < 8; $i++) {
            RelatedItemCreator::factory()->create([
                'related_item_id' => $item->id,
                'name' => "A{$i}, B",
                'given_name' => 'B',
                'family_name' => "A{$i}",
                'position' => $i,
            ]);
        }

        $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']), CitationFormatter::STYLE_IEEE);
        expect($out)->toContain('et al.');
    });

    it('returns publisher and year for a book (non-container) in IEEE', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'Book',
            'publication_year' => 2019,
            'publisher' => 'Springer',
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'Geology Textbook',
            'title_type' => 'MainTitle',
        ]);
        RelatedItemCreator::factory()->create([
            'related_item_id' => $item->id,
            'name' => 'Smith, A',
            'given_name' => 'A',
            'family_name' => 'Smith',
        ]);

        $out = (new CitationFormatter())->format(
            $item->fresh(['titles', 'creators']),
            CitationFormatter::STYLE_IEEE
        );

        expect($out)
            ->toContain('A. Smith,')
            ->toContain('"Geology Textbook,"')
            ->toContain('Springer,')
            ->toContain('2019.');
    });

    it('omits creators segment in IEEE when no creators exist', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'Report',
            'publication_year' => 2022,
            'publisher' => null,
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'Anon Report',
            'title_type' => 'MainTitle',
        ]);

        $out = (new CitationFormatter())->format(
            $item->fresh(['titles', 'creators']),
            CitationFormatter::STYLE_IEEE
        );

        expect($out)->toStartWith('"Anon Report,"')
            ->toContain('2022');
    });
});

describe('APA edge cases', function () {
    it('formats volume-only without issue for non-container', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'Report',
            'publication_year' => 2024,
            'volume' => '5',
            'issue' => null,
            'first_page' => null,
            'last_page' => null,
            'publisher' => 'GFZ',
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'Report Title',
            'title_type' => 'MainTitle',
        ]);

        $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));

        expect($out)->toContain('Report Title (5).')
            ->toContain('GFZ.');
    });

    it('formats issue-only as "(issue)" when no volume', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'Report',
            'publication_year' => 2024,
            'volume' => null,
            'issue' => '7',
            'first_page' => null,
            'last_page' => null,
            'publisher' => null,
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'X',
            'title_type' => 'MainTitle',
        ]);

        $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));

        expect($out)->toContain('X ((7)).');
    });

    it('formats a single first page without last page', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'Report',
            'publication_year' => 2024,
            'volume' => null,
            'issue' => null,
            'first_page' => '42',
            'last_page' => null,
            'publisher' => null,
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'Y',
            'title_type' => 'MainTitle',
        ]);

        $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));

        expect($out)->toContain('Y, 42.');
    });

    it('keeps the DOI as-is when the identifier is already a URL', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'Report',
            'publication_year' => 2024,
            'identifier' => 'https://doi.org/10.1/already',
            'identifier_type' => 'DOI',
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'Z',
            'title_type' => 'MainTitle',
        ]);

        $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));

        expect($out)->toContain('https://doi.org/10.1/already')
            ->not->toContain('https://doi.org/https://');
    });

    it('strips a leading slash from a bare DOI', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'Report',
            'publication_year' => 2024,
            'identifier' => '/10.1234/slash',
            'identifier_type' => 'DOI',
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'S',
            'title_type' => 'MainTitle',
        ]);

        $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));

        expect($out)->toContain('https://doi.org/10.1234/slash');
    });

    it('omits creators segment when no creators exist', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'Report',
            'publication_year' => 2024,
            'publisher' => null,
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'Anon',
            'title_type' => 'MainTitle',
        ]);

        $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));

        expect($out)->toStartWith('(2024).')
            ->toContain('Anon');
    });

    it('falls back to name when family/given are missing', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'Report',
            'publication_year' => 2024,
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'T',
            'title_type' => 'MainTitle',
        ]);
        RelatedItemCreator::factory()->create([
            'related_item_id' => $item->id,
            'name_type' => 'Personal',
            'name' => 'Madonna',
            'given_name' => null,
            'family_name' => null,
        ]);

        $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));

        expect($out)->toContain('Madonna (2024).');
    });

    it('computes multi-part initials from hyphenated given names', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'Report',
            'publication_year' => 2024,
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'T',
            'title_type' => 'MainTitle',
        ]);
        RelatedItemCreator::factory()->create([
            'related_item_id' => $item->id,
            'name_type' => 'Personal',
            'name' => 'Picard, Jean-Luc',
            'given_name' => 'Jean-Luc',
            'family_name' => 'Picard',
        ]);

        $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));

        expect($out)->toContain('Picard, J. L.');
    });

    it('uppercases multibyte initials safely', function () {
        $item = RelatedItem::factory()->create([
            'related_item_type' => 'Report',
            'publication_year' => 2024,
        ]);
        RelatedItemTitle::factory()->create([
            'related_item_id' => $item->id,
            'title' => 'T',
            'title_type' => 'MainTitle',
        ]);
        RelatedItemCreator::factory()->create([
            'related_item_id' => $item->id,
            'name_type' => 'Personal',
            'name' => 'Ångström, Örjan',
            'given_name' => 'örjan',
            'family_name' => 'Ångström',
        ]);

        $out = (new CitationFormatter())->format($item->fresh(['titles', 'creators']));

        expect($out)->toContain('Ångström, Ö.');
    });
});

