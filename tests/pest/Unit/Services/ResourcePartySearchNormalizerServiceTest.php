<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Models\Person;
use App\Services\Resources\ResourcePartySearchNormalizerService;

it('builds case-insensitive person terms in both name orders and compact forms', function (): void {
    $normalizer = new ResourcePartySearchNormalizerService;
    $person = new Person(['given_name' => '  Péter-Jane ', 'family_name' => 'HANS']);

    expect($normalizer->entityTerms($person))->toBe([
        'péter jane',
        'hans',
        'péter jane hans',
        'hans péter jane',
        'péterjanehans',
        'hanspéterjane',
    ])->and($normalizer->partyMatches($person, 'HANS Péter-Jane'))->toBeTrue()
        ->and($normalizer->partyMatches($person, 'HansPéterJane'))->toBeTrue()
        ->and($normalizer->partyMatches($person, 'Pter'))->toBeFalse();
});

it('normalizes institutions while preserving literal email matching', function (): void {
    $normalizer = new ResourcePartySearchNormalizerService;
    $institution = new Institution(['name' => 'GFZ – Data_Services']);

    expect($normalizer->entityTerms($institution))->toBe(['gfz data services', 'gfzdataservices'])
        ->and($normalizer->partyMatches($institution, 'DATAservices'))->toBeTrue()
        ->and($normalizer->partyMatches($institution, 'data@example.test'))->toBeFalse()
        ->and($normalizer->emailMatches('Hans@Peter.Egal', '@PETER.EGAL'))->toBeTrue()
        ->and($normalizer->emailMatches('Hans@Peter.Egal', 'Peter'))->toBeTrue();
});

it('escapes SQL LIKE wildcard characters', function (): void {
    $normalizer = new ResourcePartySearchNormalizerService;

    expect($normalizer->likePattern('100%_done!'))->toBe('%100!%!_done!!%');
});
