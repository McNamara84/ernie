<?php

declare(strict_types=1);

use App\Services\IgsnCsvParserService;

beforeEach(function () {
    $this->parser = new IgsnCsvParserService;
});

describe('CSV Header Parsing', function () {
    it('parses valid CSV headers', function () {
        $csv = "igsn|title|name\n10.58052/IGSN.1234|Test Title|Sample Name";

        $result = $this->parser->parse($csv);

        expect($result['headers'])->toEqual(['igsn', 'title', 'name']);
    });

    it('trims whitespace from headers', function () {
        $csv = " igsn | title | name \n10.58052/IGSN.1234|Test Title|Sample Name";

        $result = $this->parser->parse($csv);

        expect($result['headers'])->toEqual(['igsn', 'title', 'name']);
    });

    it('returns error for missing required headers', function () {
        $csv = "igsn|title\n10.58052/IGSN.1234|Test Title";

        $result = $this->parser->parse($csv);

        expect($result['errors'])->toHaveCount(1)
            ->and($result['errors'][0]['message'])->toContain('name');
    });
});

describe('CSV Data Parsing', function () {
    it('parses a simple CSV row', function () {
        $csv = <<<'CSV'
igsn|title|name
10.58052/IGSN.1234|Test Title|Sample Name
CSV;

        $result = $this->parser->parse($csv);

        expect($result['errors'])->toBeEmpty()
            ->and($result['rows'])->toHaveCount(1)
            ->and($result['rows'][0]['igsn'])->toBe('10.58052/IGSN.1234')
            ->and($result['rows'][0]['title'])->toBe('Test Title')
            ->and($result['rows'][0]['name'])->toBe('Sample Name');
    });

    it('parses multiple rows', function () {
        $csv = <<<'CSV'
igsn|title|name
10.58052/IGSN.1234|Title 1|Name 1
10.58052/IGSN.5678|Title 2|Name 2
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'])->toHaveCount(2);
    });

    it('handles empty rows gracefully', function () {
        $csv = <<<'CSV'
igsn|title|name
10.58052/IGSN.1234|Title 1|Name 1

10.58052/IGSN.5678|Title 2|Name 2
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'])->toHaveCount(2);
    });

    it('tracks row numbers correctly', function () {
        $csv = <<<'CSV'
igsn|title|name
10.58052/IGSN.1234|Title 1|Name 1
10.58052/IGSN.5678|Title 2|Name 2
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_row_number'])->toBe(2)
            ->and($result['rows'][1]['_row_number'])->toBe(3);
    });
});

describe('Multi-Value Field Parsing', function () {
    it('parses semicolon-separated sample_other_names', function () {
        $csv = <<<'CSV'
igsn|title|name|sample_other_names
10.58052/IGSN.1234|Title|Name|Alias 1; Alias 2; Alias 3
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['sample_other_names'])
            ->toBe(['Alias 1', 'Alias 2', 'Alias 3']);
    });

    it('parses comma-separated geological_age', function () {
        $csv = <<<'CSV'
igsn|title|name|geological_age
10.58052/IGSN.1234|Title|Name|Jurassic, Cretaceous
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['geological_age'])
            ->toBe(['Jurassic', 'Cretaceous']);
    });

    it('parses comma-separated geological_unit', function () {
        $csv = <<<'CSV'
igsn|title|name|geological_unit
10.58052/IGSN.1234|Title|Name|Formation A, Formation B
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['geological_unit'])
            ->toBe(['Formation A', 'Formation B']);
    });

    it('handles empty multi-value fields', function () {
        $csv = <<<'CSV'
igsn|title|name|sample_other_names|geological_age
10.58052/IGSN.1234|Title|Name||
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['sample_other_names'])->toBe([])
            ->and($result['rows'][0]['geological_age'])->toBe([]);
    });
});

describe('Contributor Parsing', function () {
    it('parses a single contributor', function () {
        $csv = <<<'CSV'
igsn|title|name|contributor|contributorType
10.58052/IGSN.1234|Title|Name|John Doe|ContactPerson
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_contributors'])->toHaveCount(1)
            ->and($result['rows'][0]['_contributors'][0]['name'])->toBe('John Doe')
            ->and($result['rows'][0]['_contributors'][0]['type'])->toBe('ContactPerson');
    });

    it('parses multiple contributors with identifiers', function () {
        $csv = <<<'CSV'
igsn|title|name|contributor|contributorType|identifier|identifierType
10.58052/IGSN.1234|Title|Name|John Doe; Jane Smith|ContactPerson; DataManager|0000-0001-2345-6789; 0000-0002-3456-7890|ORCID; ORCID
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_contributors'])->toHaveCount(2)
            ->and($result['rows'][0]['_contributors'][0]['identifier'])->toBe('https://orcid.org/0000-0001-2345-6789')
            ->and($result['rows'][0]['_contributors'][1]['identifier'])->toBe('https://orcid.org/0000-0002-3456-7890');
    });
});

describe('Creator (Collector) Parsing', function () {
    it('parses collector name and splits into family/given', function () {
        $csv = <<<'CSV'
igsn|title|name|collector
10.58052/IGSN.1234|Title|Name|Doe, John
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['familyName'])->toBe('Doe')
            ->and($result['rows'][0]['_creator']['givenName'])->toBe('John');
    });

    it('parses collector with ORCID and affiliation', function () {
        $csv = <<<'CSV'
igsn|title|name|collector|collector_identifier|collector_affiliation|collector_affiliation_identifier
10.58052/IGSN.1234|Title|Name|Doe, John|0000-0001-2345-6789|GFZ Potsdam|https://ror.org/04z8jg394
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['orcid'])->toBe('https://orcid.org/0000-0001-2345-6789')
            ->and($result['rows'][0]['_creator']['affiliation'])->toBe('GFZ Potsdam')
            ->and($result['rows'][0]['_creator']['ror'])->toBe('https://ror.org/04z8jg394');
    });
});

describe('GeoLocation Parsing', function () {
    it('parses latitude, longitude and elevation', function () {
        $csv = <<<'CSV'
igsn|title|name|latitude|longitude|elevation|elevationUnit
10.58052/IGSN.1234|Title|Name|52.5200|13.4050|50.5|m
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_geo_location']['latitude'])->toBe(52.52)
            ->and($result['rows'][0]['_geo_location']['longitude'])->toBe(13.405)
            ->and($result['rows'][0]['_geo_location']['elevation'])->toBe(50.5)
            ->and($result['rows'][0]['_geo_location']['elevationUnit'])->toBe('m');
    });

    it('parses locality/location_name as place', function () {
        $csv = <<<'CSV'
igsn|title|name|locality
10.58052/IGSN.1234|Title|Name|Berlin, Germany
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_geo_location']['place'])->toBe('Berlin, Germany');
    });
});

describe('Related Identifiers Parsing', function () {
    it('parses a single related identifier', function () {
        $csv = <<<'CSV'
igsn|title|name|relatedIdentifier|relatedIdentifierType|relationtype
10.58052/IGSN.1234|Title|Name|10.1234/test|DOI|IsDerivedFrom
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_related_identifiers'])->toHaveCount(1)
            ->and($result['rows'][0]['_related_identifiers'][0]['identifier'])->toBe('10.1234/test')
            ->and($result['rows'][0]['_related_identifiers'][0]['type'])->toBe('DOI')
            ->and($result['rows'][0]['_related_identifiers'][0]['relationType'])->toBe('IsDerivedFrom');
    });

    it('parses multiple related identifiers', function () {
        $csv = <<<'CSV'
igsn|title|name|relatedIdentifier|relatedIdentifierType|relationtype
10.58052/IGSN.1234|Title|Name|10.1234/test1; 10.5678/test2|DOI; DOI|IsDerivedFrom; IsPartOf
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_related_identifiers'])->toHaveCount(2);
    });
});

describe('Funding References Parsing', function () {
    it('parses funder name and identifier', function () {
        $csv = <<<'CSV'
igsn|title|name|funderName|funderIdentifier
10.58052/IGSN.1234|Title|Name|DFG|http://dx.doi.org/10.13039/501100001659
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_funding_references'])->toHaveCount(1)
            ->and($result['rows'][0]['_funding_references'][0]['name'])->toBe('DFG')
            ->and($result['rows'][0]['_funding_references'][0]['identifier'])->toBe('http://dx.doi.org/10.13039/501100001659');
    });

    it('parses multiple funders', function () {
        $csv = <<<'CSV'
igsn|title|name|funderName|funderIdentifier
10.58052/IGSN.1234|Title|Name|DFG; EU|http://id1; http://id2
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_funding_references'])->toHaveCount(2);
    });
});

describe('Collection Date Parsing', function () {
    it('parses start and end dates', function () {
        $dates = $this->parser->parseCollectionDates('2024-01-15', '2024-02-20');

        expect($dates['start'])->toBe('2024-01-15')
            ->and($dates['end'])->toBe('2024-02-20');
    });

    it('handles date with only year', function () {
        $dates = $this->parser->parseCollectionDates('2024', '');

        expect($dates['start'])->toBe('2024')
            ->and($dates['end'])->toBeNull();
    });

    it('returns null for empty dates', function () {
        $dates = $this->parser->parseCollectionDates('', '');

        expect($dates['start'])->toBeNull()
            ->and($dates['end'])->toBeNull();
    });
});

describe('Error Handling', function () {
    it('returns error for empty CSV', function () {
        $result = $this->parser->parse('');

        expect($result['errors'])->not->toBeEmpty();
    });

    it('returns error for header-only CSV', function () {
        $result = $this->parser->parse('igsn|title|name');

        expect($result['errors'])->not->toBeEmpty();
    });
});
