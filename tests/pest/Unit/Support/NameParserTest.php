<?php

use App\Support\NameParser;

/**
 * Tests for NameParser utility class.
 *
 * This class handles parsing of names from the old metaworks database,
 * which stores names in two different formats:
 * 1. Separated: firstname and lastname in separate fields
 * 2. Combined: "Lastname, Firstname" format in single name field
 */
describe('NameParser', function () {
    describe('parsePersonName with explicit firstname and lastname', function () {
        it('uses explicit names when both are provided', function () {
            // Real data from metaworks dataset 4: Barthelmes, Franz
            $result = NameParser::parsePersonName('Barthelmes, Franz', 'Franz', 'Barthelmes');

            expect($result)->toBe([
                'firstName' => 'Franz',
                'lastName' => 'Barthelmes',
            ]);
        });

        it('uses explicit lastname even if firstname is null', function () {
            $result = NameParser::parsePersonName('Some Name', null, 'OnlyLastName');

            expect($result)->toBe([
                'firstName' => '',
                'lastName' => 'OnlyLastName',
            ]);
        });

        it('uses explicit firstname even if lastname is null', function () {
            $result = NameParser::parsePersonName('Some Name', 'OnlyFirstName', null);

            expect($result)->toBe([
                'firstName' => 'OnlyFirstName',
                'lastName' => '',
            ]);
        });

        it('prefers explicit names over comma-separated name string', function () {
            // Even if name contains comma, explicit names take precedence
            $result = NameParser::parsePersonName('Doe, John', 'Jane', 'Smith');

            expect($result)->toBe([
                'firstName' => 'Jane',
                'lastName' => 'Smith',
            ]);
        });
    });

    describe('parsePersonName with comma-separated name string', function () {
        it('parses "Lastname, Firstname" format correctly', function () {
            // Real data from metaworks dataset 4: Förste, Christoph
            $result = NameParser::parsePersonName('Förste, Christoph', null, null);

            expect($result)->toBe([
                'firstName' => 'Christoph',
                'lastName' => 'Förste',
            ]);
        });

        it('handles names with middle initials', function () {
            // Real data from metaworks dataset 4: Bruinsma, Sean.L.
            $result = NameParser::parsePersonName('Bruinsma, Sean.L.', null, null);

            expect($result)->toBe([
                'firstName' => 'Sean.L.',
                'lastName' => 'Bruinsma',
            ]);
        });

        it('trims whitespace around comma-separated parts', function () {
            $result = NameParser::parsePersonName('LastName  ,   FirstName  ', null, null);

            expect($result)->toBe([
                'firstName' => 'FirstName',
                'lastName' => 'LastName',
            ]);
        });

        it('handles comma with no first name part', function () {
            $result = NameParser::parsePersonName('OnlyLastName,', null, null);

            expect($result)->toBe([
                'firstName' => '',
                'lastName' => 'OnlyLastName',
            ]);
        });

        it('handles names with multiple commas by splitting on first comma only', function () {
            $result = NameParser::parsePersonName('LastName, FirstName, Jr.', null, null);

            expect($result)->toBe([
                'firstName' => 'FirstName, Jr.',
                'lastName' => 'LastName',
            ]);
        });
    });

    describe('parsePersonName with single name (no comma)', function () {
        it('treats single name as lastName only', function () {
            // Real data from metaworks dataset 8: institution name
            $result = NameParser::parsePersonName('UNESCO', null, null);

            expect($result)->toBe([
                'firstName' => '',
                'lastName' => 'UNESCO',
            ]);
        });

        it('handles names with spaces as single lastName', function () {
            // Real data from metaworks dataset 8: Centre for Early Warning System
            $result = NameParser::parsePersonName('Centre for Early Warning System', null, null);

            expect($result)->toBe([
                'firstName' => '',
                'lastName' => 'Centre for Early Warning System',
            ]);
        });

        it('treats names with hyphens as single lastName', function () {
            $result = NameParser::parsePersonName('Jean-Pierre', null, null);

            expect($result)->toBe([
                'firstName' => '',
                'lastName' => 'Jean-Pierre',
            ]);
        });
    });

    describe('parsePersonName with null or empty values', function () {
        it('returns empty strings when all inputs are null', function () {
            $result = NameParser::parsePersonName(null, null, null);

            expect($result)->toBe([
                'firstName' => '',
                'lastName' => '',
            ]);
        });

        it('returns empty strings when name is empty string', function () {
            $result = NameParser::parsePersonName('', null, null);

            expect($result)->toBe([
                'firstName' => '',
                'lastName' => '',
            ]);
        });

        it('returns empty firstName when only lastname is provided via explicit field', function () {
            $result = NameParser::parsePersonName(null, null, 'OnlyLast');

            expect($result)->toBe([
                'firstName' => '',
                'lastName' => 'OnlyLast',
            ]);
        });
    });

    describe('real-world test cases from metaworks database', function () {
        it('correctly parses dataset 4 authors and contributors', function () {
            // Barthelmes, Franz (has explicit names)
            expect(NameParser::parsePersonName('Barthelmes, Franz', 'Franz', 'Barthelmes'))->toBe([
                'firstName' => 'Franz',
                'lastName' => 'Barthelmes',
            ]);

            // Reißland, Sven (has explicit names)
            expect(NameParser::parsePersonName('Reißland, Sven', 'Sven', 'Reißland'))->toBe([
                'firstName' => 'Sven',
                'lastName' => 'Reißland',
            ]);

            // Förste, Christoph (name only, needs parsing)
            expect(NameParser::parsePersonName('Förste, Christoph', null, null))->toBe([
                'firstName' => 'Christoph',
                'lastName' => 'Förste',
            ]);

            // Bruinsma, Sean.L. (name only, needs parsing)
            expect(NameParser::parsePersonName('Bruinsma, Sean.L.', null, null))->toBe([
                'firstName' => 'Sean.L.',
                'lastName' => 'Bruinsma',
            ]);
        });

        it('correctly parses dataset 8 contributors', function () {
            // Centre for Early Warning System (institution, no comma)
            expect(NameParser::parsePersonName('Centre for Early Warning System', null, null))->toBe([
                'firstName' => '',
                'lastName' => 'Centre for Early Warning System',
            ]);

            // Ullah, Waheed (has explicit names)
            expect(NameParser::parsePersonName('Ullah, Waheed', 'Waheed', 'Ullah'))->toBe([
                'firstName' => 'Waheed',
                'lastName' => 'Ullah',
            ]);

            // Pittore, Massimiliano (has explicit names)
            expect(NameParser::parsePersonName('Pittore, Massimiliano', 'Massimiliano', 'Pittore'))->toBe([
                'firstName' => 'Massimiliano',
                'lastName' => 'Pittore',
            ]);
        });

        it('correctly parses dataset 2396 contributors', function () {
            // CELTIC Cardiff (institution, no comma)
            expect(NameParser::parsePersonName('CELTIC Cardiff', null, null))->toBe([
                'firstName' => '',
                'lastName' => 'CELTIC Cardiff',
            ]);

            // Spencer, Laura M. (has explicit names)
            expect(NameParser::parsePersonName('Spencer, Laura M.', 'Laura M.', 'Spencer'))->toBe([
                'firstName' => 'Laura M.',
                'lastName' => 'Spencer',
            ]);
        });

        it('handles German umlauts and special characters correctly', function () {
            // Läuchli, Charlotte (from real data)
            expect(NameParser::parsePersonName('Läuchli, Charlotte', null, null))->toBe([
                'firstName' => 'Charlotte',
                'lastName' => 'Läuchli',
            ]);

            // Müller, Jürgen
            expect(NameParser::parsePersonName('Müller, Jürgen', null, null))->toBe([
                'firstName' => 'Jürgen',
                'lastName' => 'Müller',
            ]);
        });
    });

    describe('isPerson', function () {
        it('returns true for names with firstName', function () {
            $parsedName = ['firstName' => 'John', 'lastName' => 'Doe'];

            expect(NameParser::isPerson($parsedName))->toBeTrue();
        });

        it('returns false for names without firstName (institutions)', function () {
            $parsedName = ['firstName' => '', 'lastName' => 'UNESCO'];

            expect(NameParser::isPerson($parsedName))->toBeFalse();
        });

        it('correctly identifies comma-separated person names', function () {
            // Parse a comma-separated name and check if it's identified as person
            $parsedName = NameParser::parsePersonName('Förste, Christoph', null, null);

            expect(NameParser::isPerson($parsedName))->toBeTrue();
        });

        it('correctly identifies institution names without comma', function () {
            // Parse an institution name and check if it's identified as institution
            $parsedName = NameParser::parsePersonName('Centre for Early Warning System', null, null);

            expect(NameParser::isPerson($parsedName))->toBeFalse();
        });

        it('correctly identifies person with explicit names', function () {
            $parsedName = NameParser::parsePersonName('Barthelmes, Franz', 'Franz', 'Barthelmes');

            expect(NameParser::isPerson($parsedName))->toBeTrue();
        });

        it('works with empty parsed name', function () {
            $parsedName = NameParser::parsePersonName(null, null, null);

            expect(NameParser::isPerson($parsedName))->toBeFalse();
        });
    });
});
