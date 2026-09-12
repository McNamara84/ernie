<?php

declare(strict_types=1);

use App\Models\OldDataset;
use App\Services\LegacyCreatorNameService;

covers(LegacyCreatorNameService::class);

it('maps SUMARIO creator spellings and valid ORCIDs to DataCite names', function (): void {
    $dataset = Mockery::mock(OldDataset::class)->makePartial();
    $dataset->shouldReceive('getAuthors')->once()->andReturn([
        [
            'givenName' => 'Philipp S.',
            'familyName' => 'Sommer',
            'name' => 'Sommer, Philipp S.',
            'orcid' => 'https://orcid.org/0000-0001-6171-7716',
        ],
        [
            'givenName' => null,
            'familyName' => 'Legacy Family',
            'name' => '',
            'orcid' => 'invalid',
        ],
    ]);

    expect((new LegacyCreatorNameService)->dataCiteCreators($dataset))->toBe([
        [
            'name' => 'Sommer, Philipp S.',
            'nameType' => 'Personal',
            'givenName' => 'Philipp S.',
            'familyName' => 'Sommer',
            'nameIdentifiers' => [[
                'nameIdentifier' => '0000-0001-6171-7716',
                'nameIdentifierScheme' => 'ORCID',
                'schemeUri' => 'https://orcid.org/',
            ]],
        ],
        [
            'name' => 'Legacy Family',
            'nameType' => 'Personal',
            'familyName' => 'Legacy Family',
        ],
    ]);
});
