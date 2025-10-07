/**
 * Vitest Tests for Name Parsing Utilities
 * 
 * Tests the parsing logic for contributor names from old database,
 * using real examples from the metaworks database.
 */

import { describe, it, expect } from 'vitest';
import { parseContributorName } from '@/utils/nameParser';

describe('parseContributorName', () => {
    describe('with explicit givenName and familyName', () => {
        it('should use explicit names when both are provided', () => {
            // Real data from dataset 4: Barthelmes, Franz
            const result = parseContributorName(
                'Barthelmes, Franz',
                'Franz',
                'Barthelmes'
            );

            expect(result).toEqual({
                firstName: 'Franz',
                lastName: 'Barthelmes',
            });
        });

        it('should use explicit familyName even if givenName is null', () => {
            // Edge case: only familyName provided
            const result = parseContributorName(
                'Some Name',
                null,
                'OnlyLastName'
            );

            expect(result).toEqual({
                firstName: '',
                lastName: 'OnlyLastName',
            });
        });

        it('should use explicit givenName even if familyName is null', () => {
            // Edge case: only givenName provided
            const result = parseContributorName(
                'Some Name',
                'OnlyFirstName',
                null
            );

            expect(result).toEqual({
                firstName: 'OnlyFirstName',
                lastName: '',
            });
        });
    });

    describe('with comma-separated name string', () => {
        it('should parse "LastName, FirstName" format correctly', () => {
            // Real data from dataset 4: Förste, Christoph
            const result = parseContributorName(
                'Förste, Christoph',
                null,
                null
            );

            expect(result).toEqual({
                firstName: 'Christoph',
                lastName: 'Förste',
            });
        });

        it('should handle names with middle initials', () => {
            // Real data from dataset 4: Bruinsma, Sean.L.
            const result = parseContributorName(
                'Bruinsma, Sean.L.',
                null,
                null
            );

            expect(result).toEqual({
                firstName: 'Sean.L.',
                lastName: 'Bruinsma',
            });
        });

        it('should trim whitespace around comma-separated parts', () => {
            const result = parseContributorName(
                'LastName  ,   FirstName  ',
                null,
                null
            );

            expect(result).toEqual({
                firstName: 'FirstName',
                lastName: 'LastName',
            });
        });

        it('should handle comma with no first name part', () => {
            const result = parseContributorName(
                'OnlyLastName,',
                null,
                null
            );

            expect(result).toEqual({
                firstName: '',
                lastName: 'OnlyLastName',
            });
        });
    });

    describe('with single name (no comma)', () => {
        it('should treat single name as lastName only', () => {
            // Real data from dataset 8: institution name
            const result = parseContributorName(
                'UNESCO',
                null,
                null
            );

            expect(result).toEqual({
                firstName: '',
                lastName: 'UNESCO',
            });
        });

        it('should handle names with spaces as single lastName', () => {
            // Real data from dataset 8: Centre for Early Warning System
            const result = parseContributorName(
                'Centre for Early Warning System',
                null,
                null
            );

            expect(result).toEqual({
                firstName: '',
                lastName: 'Centre for Early Warning System',
            });
        });
    });

    describe('with null or empty values', () => {
        it('should return empty strings when all inputs are null', () => {
            const result = parseContributorName(null, null, null);

            expect(result).toEqual({
                firstName: '',
                lastName: '',
            });
        });

        it('should return empty strings when name is empty string', () => {
            const result = parseContributorName('', null, null);

            expect(result).toEqual({
                firstName: '',
                lastName: '',
            });
        });
    });

    describe('real-world test cases from metaworks database', () => {
        it('should correctly parse dataset 4 contributors', () => {
            // Barthelmes, Franz (has explicit names)
            expect(parseContributorName('Barthelmes, Franz', 'Franz', 'Barthelmes')).toEqual({
                firstName: 'Franz',
                lastName: 'Barthelmes',
            });

            // Reißland, Sven (has explicit names)
            expect(parseContributorName('Reißland, Sven', 'Sven', 'Reißland')).toEqual({
                firstName: 'Sven',
                lastName: 'Reißland',
            });

            // Förste, Christoph (name only, needs parsing)
            expect(parseContributorName('Förste, Christoph', null, null)).toEqual({
                firstName: 'Christoph',
                lastName: 'Förste',
            });

            // Bruinsma, Sean.L. (name only, needs parsing)
            expect(parseContributorName('Bruinsma, Sean.L.', null, null)).toEqual({
                firstName: 'Sean.L.',
                lastName: 'Bruinsma',
            });
        });

        it('should correctly parse dataset 8 contributors', () => {
            // Centre for Early Warning System (institution, no comma)
            expect(parseContributorName('Centre for Early Warning System', null, null)).toEqual({
                firstName: '',
                lastName: 'Centre for Early Warning System',
            });

            // Ullah, Waheed (has explicit names)
            expect(parseContributorName('Ullah, Waheed', 'Waheed', 'Ullah')).toEqual({
                firstName: 'Waheed',
                lastName: 'Ullah',
            });

            // Pittore, Massimiliano (has explicit names)
            expect(parseContributorName('Pittore, Massimiliano', 'Massimiliano', 'Pittore')).toEqual({
                firstName: 'Massimiliano',
                lastName: 'Pittore',
            });
        });

        it('should correctly parse dataset 2396 contributors', () => {
            // CELTIC Cardiff (institution, no comma)
            expect(parseContributorName('CELTIC Cardiff', null, null)).toEqual({
                firstName: '',
                lastName: 'CELTIC Cardiff',
            });

            // Spencer, Laura M. (has explicit names)
            expect(parseContributorName('Spencer, Laura M.', 'Laura M.', 'Spencer')).toEqual({
                firstName: 'Laura M.',
                lastName: 'Spencer',
            });
        });
    });
});
