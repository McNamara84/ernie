<?php

declare(strict_types=1);

use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Services\DataCite\Mapping\DataCiteFundingReferenceMapper;

covers(DataCiteFundingReferenceMapper::class);

it('maps minimal funding reference data', function (): void {
    $funding = new FundingReference;
    $funding->funder_name = 'German Research Foundation';

    expect(app(DataCiteFundingReferenceMapper::class)->toArray($funding))->toBe([
        'funderName' => 'German Research Foundation',
    ]);
});

it('maps funder identifiers and award metadata', function (): void {
    $type = new FunderIdentifierType;
    $type->name = 'ROR';

    $funding = new FundingReference;
    $funding->funder_name = 'GFZ Potsdam';
    $funding->funder_identifier = 'https://ror.org/04z8jg394';
    $funding->scheme_uri = 'https://ror.org/';
    $funding->award_number = '12345';
    $funding->award_uri = 'https://example.org/award/12345';
    $funding->award_title = 'Research Award';
    $funding->setRelation('funderIdentifierType', $type);

    expect(app(DataCiteFundingReferenceMapper::class)->toArray($funding))->toBe([
        'funderName' => 'GFZ Potsdam',
        'funderIdentifier' => 'https://ror.org/04z8jg394',
        'funderIdentifierType' => 'ROR',
        'schemeUri' => 'https://ror.org/',
        'awardNumber' => '12345',
        'awardUri' => 'https://example.org/award/12345',
        'awardTitle' => 'Research Award',
    ]);
});