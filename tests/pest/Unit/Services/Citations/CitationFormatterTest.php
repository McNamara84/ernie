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
});
