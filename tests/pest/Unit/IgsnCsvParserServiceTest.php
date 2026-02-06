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

describe('Size Parsing', function () {
    it('parses multiple semicolon-separated size values with units', function () {
        $csv = <<<'CSV'
igsn|title|name|size|size_unit
10.58052/IGSN.1234|Title|Name|0.9; 146|Drilled Length [m]; Core Diameter [mm]
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_sizes'])
            ->toHaveCount(2);

        // First size entry
        $first = $result['rows'][0]['_sizes'][0];
        expect($first['numeric_value'])->toBe('0.9')
            ->and($first['unit'])->toBe('m')
            ->and($first['type'])->toBe('Drilled Length');

        // Second size entry
        $second = $result['rows'][0]['_sizes'][1];
        expect($second['numeric_value'])->toBe('146')
            ->and($second['unit'])->toBe('mm')
            ->and($second['type'])->toBe('Core Diameter');
    });

    it('parses a single size value with unit', function () {
        $csv = <<<'CSV'
igsn|title|name|size|size_unit
10.58052/IGSN.1234|Title|Name|851.88|Total Cored Length [m]
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_sizes'])
            ->toHaveCount(1);

        $entry = $result['rows'][0]['_sizes'][0];
        expect($entry['numeric_value'])->toBe('851.88')
            ->and($entry['unit'])->toBe('m')
            ->and($entry['type'])->toBe('Total Cored Length');
    });

    it('parses size without unit', function () {
        $csv = <<<'CSV'
igsn|title|name|size
10.58052/IGSN.1234|Title|Name|250
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_sizes'])
            ->toHaveCount(1);

        $entry = $result['rows'][0]['_sizes'][0];
        expect($entry['numeric_value'])->toBe('250')
            ->and($entry['unit'])->toBeNull()
            ->and($entry['type'])->toBeNull();
    });

    it('handles empty size field', function () {
        $csv = <<<'CSV'
igsn|title|name|size|size_unit
10.58052/IGSN.1234|Title|Name||
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_sizes'])->toBeEmpty();
    });

    it('handles size with zero value', function () {
        $csv = <<<'CSV'
igsn|title|name|size|size_unit
10.58052/IGSN.1234|Title|Name|0|core length [m]
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_sizes'])
            ->toHaveCount(1);

        $entry = $result['rows'][0]['_sizes'][0];
        expect($entry['numeric_value'])->toBe('0')
            ->and($entry['unit'])->toBe('m')
            ->and($entry['type'])->toBe('core length');
    });

    it('handles no size column at all', function () {
        $csv = <<<'CSV'
igsn|title|name
10.58052/IGSN.1234|Title|Name
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_sizes'])->toBeEmpty();
    });

    it('parses unit string without brackets as type only', function () {
        $csv = <<<'CSV'
igsn|title|name|size|size_unit
10.58052/IGSN.1234|Title|Name|5|meters
CSV;

        $result = $this->parser->parse($csv);

        $entry = $result['rows'][0]['_sizes'][0];
        expect($entry['numeric_value'])->toBe('5')
            ->and($entry['unit'])->toBeNull()
            ->and($entry['type'])->toBe('meters');
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
    it('parses collector name and splits into family/given (comma format)', function () {
        $csv = <<<'CSV'
igsn|title|name|collector
10.58052/IGSN.1234|Title|Name|Doe, John
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['familyName'])->toBe('Doe')
            ->and($result['rows'][0]['_creator']['givenName'])->toBe('John');
    });

    it('parses collector name in "GivenName FamilyName" format', function () {
        $csv = <<<'CSV'
igsn|title|name|collector
10.58052/IGSN.1234|Title|Name|John Doe
CSV;

        $result = $this->parser->parse($csv);

        // Format: "GivenName FamilyName" - last word is family name
        expect($result['rows'][0]['_creator']['familyName'])->toBe('Doe')
            ->and($result['rows'][0]['_creator']['givenName'])->toBe('John');
    });

    it('handles collector name with multiple given names', function () {
        $csv = <<<'CSV'
igsn|title|name|collector
10.58052/IGSN.1234|Title|Name|John Paul Smith
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['familyName'])->toBe('Smith')
            ->and($result['rows'][0]['_creator']['givenName'])->toBe('John Paul');
    });

    it('handles single-word collector name as family name', function () {
        $csv = <<<'CSV'
igsn|title|name|collector
10.58052/IGSN.1234|Title|Name|Darwin
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['familyName'])->toBe('Darwin')
            ->and($result['rows'][0]['_creator']['givenName'])->toBeNull();
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

    it('parses ORCID from orcid column (real CSV format)', function () {
        $csv = <<<'CSV'
igsn|title|name|collector|orcid|affiliation|ror
10.58052/IGSN.1234|Title|Name|Gabriel, Gerald|0000-0001-9404-882X|Leibniz Institute|https://ror.org/05txczf44
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['orcid'])->toBe('https://orcid.org/0000-0001-9404-882X')
            ->and($result['rows'][0]['_creator']['affiliation'])->toBe('Leibniz Institute')
            ->and($result['rows'][0]['_creator']['ror'])->toBe('https://ror.org/05txczf44');
    });

    it('normalizes ORCID that is already a full URL', function () {
        $csv = <<<'CSV'
igsn|title|name|collector|orcid
10.58052/IGSN.1234|Title|Name|Doe, John|https://orcid.org/0000-0001-2345-6789
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['orcid'])->toBe('https://orcid.org/0000-0001-2345-6789');
    });

    it('returns null for empty collector', function () {
        $csv = <<<'CSV'
igsn|title|name|collector
10.58052/IGSN.1234|Title|Name|
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['familyName'])->toBeNull()
            ->and($result['rows'][0]['_creator']['givenName'])->toBeNull();
    });

    it('parses separate givenName and familyName columns', function () {
        $csv = <<<'CSV'
igsn|title|name|givenName|familyName
10.58052/IGSN.1234|Title|Name|John|Doe
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['givenName'])->toBe('John')
            ->and($result['rows'][0]['_creator']['familyName'])->toBe('Doe');
    });

    it('prefers separate givenName/familyName over collector field', function () {
        $csv = <<<'CSV'
igsn|title|name|collector|givenName|familyName
10.58052/IGSN.1234|Title|Name|Should Be Ignored|Max|Mustermann
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['givenName'])->toBe('Max')
            ->and($result['rows'][0]['_creator']['familyName'])->toBe('Mustermann');
    });

    it('falls back to collector when givenName/familyName are empty', function () {
        $csv = <<<'CSV'
igsn|title|name|collector|givenName|familyName
10.58052/IGSN.1234|Title|Name|Fallback, User||
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['familyName'])->toBe('Fallback')
            ->and($result['rows'][0]['_creator']['givenName'])->toBe('User');
    });

    it('handles only givenName without familyName', function () {
        $csv = <<<'CSV'
igsn|title|name|givenName|familyName
10.58052/IGSN.1234|Title|Name|Madonna|
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['givenName'])->toBe('Madonna')
            ->and($result['rows'][0]['_creator']['familyName'])->toBeNull();
    });

    it('handles only familyName without givenName', function () {
        $csv = <<<'CSV'
igsn|title|name|givenName|familyName
10.58052/IGSN.1234|Title|Name||Darwin
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['givenName'])->toBeNull()
            ->and($result['rows'][0]['_creator']['familyName'])->toBe('Darwin');
    });

    it('combines givenName/familyName with ORCID and affiliation', function () {
        $csv = <<<'CSV'
igsn|title|name|givenName|familyName|orcid|affiliation|ror
10.58052/IGSN.1234|Title|Name|Max|Mustermann|0000-0002-1234-5678|GFZ Potsdam|https://ror.org/04z8jg394
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_creator']['givenName'])->toBe('Max')
            ->and($result['rows'][0]['_creator']['familyName'])->toBe('Mustermann')
            ->and($result['rows'][0]['_creator']['orcid'])->toBe('https://orcid.org/0000-0002-1234-5678')
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

    it('uses primary_location_name as fallback when locality is missing', function () {
        $csv = <<<'CSV'
igsn|title|name|primary_location_name
10.58052/IGSN.1234|Title|Name|Munich Research Site
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_geo_location']['place'])->toBe('Munich Research Site');
    });

    it('prefers locality over primary_location_name when both present', function () {
        $csv = <<<'CSV'
igsn|title|name|locality|primary_location_name
10.58052/IGSN.1234|Title|Name|Berlin|Munich Research Site
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_geo_location']['place'])->toBe('Berlin');
    });

    it('combines locality, city, province, country into place', function () {
        $csv = <<<'CSV'
igsn|title|name|locality|city|province|country
10.58052/IGSN.1234|Title|Name|Winterstettenstadt|Biberach|Baden-Württemberg|Germany
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_geo_location']['place'])
            ->toBe('Winterstettenstadt, Biberach, Baden-Württemberg, Germany');
    });

    it('handles missing city and province gracefully', function () {
        $csv = <<<'CSV'
igsn|title|name|locality|country
10.58052/IGSN.1234|Title|Name|Research Station|Antarctica
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_geo_location']['place'])->toBe('Research Station, Antarctica');
    });

    it('parses elevation with snake_case unit column', function () {
        $csv = <<<'CSV'
igsn|title|name|latitude|longitude|elevation|elevation_unit
10.58052/IGSN.1234|Title|Name|52.5200|13.4050|100.0|meters
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_geo_location']['elevation'])->toBe(100.0)
            ->and($result['rows'][0]['_geo_location']['elevationUnit'])->toBe('meters');
    });

    it('returns null for missing geo location data', function () {
        $csv = <<<'CSV'
igsn|title|name
10.58052/IGSN.1234|Title|Name
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_geo_location']['latitude'])->toBeNull()
            ->and($result['rows'][0]['_geo_location']['longitude'])->toBeNull()
            ->and($result['rows'][0]['_geo_location']['elevation'])->toBeNull()
            ->and($result['rows'][0]['_geo_location']['place'])->toBeNull();
    });

    it('parses negative coordinates correctly', function () {
        $csv = <<<'CSV'
igsn|title|name|latitude|longitude
10.58052/IGSN.1234|Title|Name|-33.8688|151.2093
CSV;

        $result = $this->parser->parse($csv);

        // Sydney coordinates
        expect($result['rows'][0]['_geo_location']['latitude'])->toBe(-33.8688)
            ->and($result['rows'][0]['_geo_location']['longitude'])->toBe(151.2093);
    });

    it('handles location_description in place field', function () {
        $csv = <<<'CSV'
igsn|title|name|location_description
10.58052/IGSN.1234|Title|Name|50m depth in borehole ICDP5068
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_geo_location']['place'])->toBe('50m depth in borehole ICDP5068');
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

    it('parses parent_igsn as relatedIdentifier with IsPartOf relation', function () {
        $csv = <<<'CSV'
igsn|title|name|parent_igsn
10.58052/IGSN.CHILD|Child Sample|Child Name|10.58052/IGSN.PARENT
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_related_identifiers'])->toHaveCount(1)
            ->and($result['rows'][0]['_related_identifiers'][0]['identifier'])->toBe('10.58052/IGSN.PARENT')
            ->and($result['rows'][0]['_related_identifiers'][0]['type'])->toBe('IGSN')
            ->and($result['rows'][0]['_related_identifiers'][0]['relationType'])->toBe('IsPartOf');
    });

    it('includes parent_igsn with other relatedIdentifiers', function () {
        $csv = <<<'CSV'
igsn|title|name|parent_igsn|relatedIdentifier|relatedIdentifierType|relationtype
10.58052/IGSN.CHILD|Child Sample|Child Name|10.58052/IGSN.PARENT|10.1234/paper|DOI|IsCitedBy
CSV;

        $result = $this->parser->parse($csv);

        // parent_igsn should be first, then other relatedIdentifiers
        expect($result['rows'][0]['_related_identifiers'])->toHaveCount(2)
            ->and($result['rows'][0]['_related_identifiers'][0]['identifier'])->toBe('10.58052/IGSN.PARENT')
            ->and($result['rows'][0]['_related_identifiers'][0]['type'])->toBe('IGSN')
            ->and($result['rows'][0]['_related_identifiers'][0]['relationType'])->toBe('IsPartOf')
            ->and($result['rows'][0]['_related_identifiers'][1]['identifier'])->toBe('10.1234/paper')
            ->and($result['rows'][0]['_related_identifiers'][1]['type'])->toBe('DOI')
            ->and($result['rows'][0]['_related_identifiers'][1]['relationType'])->toBe('IsCitedBy');
    });

    it('ignores empty parent_igsn', function () {
        $csv = <<<'CSV'
igsn|title|name|parent_igsn
10.58052/IGSN.1234|Title|Name|
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_related_identifiers'])->toBeEmpty();
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

    it('detects ROR type from funderIdentifier', function () {
        $csv = <<<'CSV'
igsn|title|name|funderName|funderIdentifier
10.58052/IGSN.1234|Test Sample|Sample A|Deutsche Forschungsgemeinschaft|https://ror.org/018mejw64
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'])->toHaveCount(1)
            ->and($result['rows'][0]['_funding_references'])->toHaveCount(1)
            ->and($result['rows'][0]['_funding_references'][0]['identifierType'])->toBe('ROR');
    });

    it('detects Crossref Funder ID type', function () {
        $csv = <<<'CSV'
igsn|title|name|funderName|funderIdentifier
10.58052/IGSN.1234|Test Sample|Sample A|European Commission|https://doi.org/10.13039/501100000780
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_funding_references'][0]['identifierType'])->toBe('Crossref Funder ID');
    });

    it('detects ISNI type from formatted string', function () {
        $csv = <<<'CSV'
igsn|title|name|funderName|funderIdentifier
10.58052/IGSN.1234|Test Sample|Sample A|Some Funder|0000 0001 2162 673X
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_funding_references'][0]['identifierType'])->toBe('ISNI');
    });

    it('detects GRID type', function () {
        $csv = <<<'CSV'
igsn|title|name|funderName|funderIdentifier
10.58052/IGSN.1234|Test Sample|Sample A|Some Funder|https://www.grid.ac/institutes/grid.123456.7
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_funding_references'][0]['identifierType'])->toBe('GRID');
    });

    it('returns null identifierType when no funderIdentifier', function () {
        $csv = <<<'CSV'
igsn|title|name|funderName|funderIdentifier
10.58052/IGSN.1234|Test Sample|Sample A|Some Funder|
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_funding_references'][0]['identifier'])->toBeNull()
            ->and($result['rows'][0]['_funding_references'][0]['identifierType'])->toBeNull();
    });

    it('returns Other for unknown identifier format', function () {
        $csv = <<<'CSV'
igsn|title|name|funderName|funderIdentifier
10.58052/IGSN.1234|Test Sample|Sample A|Some Funder|CUSTOM-ID-12345
CSV;

        $result = $this->parser->parse($csv);

        expect($result['rows'][0]['_funding_references'][0]['identifierType'])->toBe('Other');
    });
});

describe('Collection Date Parsing', function () {
    it('parses start and end dates in full date format (YYYY-MM-DD)', function () {
        $dates = $this->parser->parseCollectionDates('2024-01-15', '2024-02-20');

        expect($dates['start'])->toBe('2024-01-15')
            ->and($dates['end'])->toBe('2024-02-20');
    });

    it('parses year-month format (YYYY-MM)', function () {
        $dates = $this->parser->parseCollectionDates('2024-03', '2024-06');

        expect($dates['start'])->toBe('2024-03')
            ->and($dates['end'])->toBe('2024-06');
    });

    it('handles date with only year (YYYY)', function () {
        $dates = $this->parser->parseCollectionDates('2024', '');

        expect($dates['start'])->toBe('2024')
            ->and($dates['end'])->toBeNull();
    });

    it('handles year-only format for both start and end dates', function () {
        $dates = $this->parser->parseCollectionDates('2020', '2024');

        expect($dates['start'])->toBe('2020')
            ->and($dates['end'])->toBe('2024');
    });

    it('handles only end date without start date', function () {
        $dates = $this->parser->parseCollectionDates('', '2024-12-31');

        expect($dates['start'])->toBeNull()
            ->and($dates['end'])->toBe('2024-12-31');
    });

    it('returns null for empty dates', function () {
        $dates = $this->parser->parseCollectionDates('', '');

        expect($dates['start'])->toBeNull()
            ->and($dates['end'])->toBeNull();
    });

    it('normalizes alternative date formats to ISO format', function () {
        // Some CSV files might use different date formats
        $dates = $this->parser->parseCollectionDates('January 15, 2024', '');

        expect($dates['start'])->toBe('2024-01-15');
    });

    it('handles whitespace around dates', function () {
        $dates = $this->parser->parseCollectionDates('  2024-01-15  ', '  2024-02-20  ');

        expect($dates['start'])->toBe('2024-01-15')
            ->and($dates['end'])->toBe('2024-02-20');
    });

    it('handles mixed precision dates (year start, full date end)', function () {
        $dates = $this->parser->parseCollectionDates('2024', '2024-06-30');

        expect($dates['start'])->toBe('2024')
            ->and($dates['end'])->toBe('2024-06-30');
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
