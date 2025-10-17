import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    checkDOIRegistration,
    validateDate,
    validateDOIFormat,
    validateEmail,
    validateORCID,
    validateRequired,
    validateSemanticVersion,
    validateTextLength,
    validateTitleUniqueness,
    validateYear,
} from '@/utils/validation-rules';

describe('Validation Rules Utilities', () => {
    describe('validateDOIFormat', () => {
        it('should validate direct DOI format', () => {
            const result = validateDOIFormat('10.1234/example');
            expect(result.isValid).toBe(true);
            expect(result.normalizedDOI).toBe('10.1234/example');
        });

        it('should validate URL DOI format', () => {
            const result = validateDOIFormat('https://doi.org/10.1234/example');
            expect(result.isValid).toBe(true);
            expect(result.normalizedDOI).toBe('10.1234/example');
        });

        it('should normalize URL format to direct format', () => {
            const result = validateDOIFormat('https://doi.org/10.5281/zenodo.123456');
            expect(result.isValid).toBe(true);
            expect(result.normalizedDOI).toBe('10.5281/zenodo.123456');
        });

        it('should accept http protocol', () => {
            const result = validateDOIFormat('http://doi.org/10.1234/test');
            expect(result.isValid).toBe(true);
            expect(result.normalizedDOI).toBe('10.1234/test');
        });

        it('should reject invalid DOI format', () => {
            const result = validateDOIFormat('not-a-doi');
            expect(result.isValid).toBe(false);
            expect(result.error).toContain('Invalid DOI format');
        });

        it('should reject empty DOI', () => {
            const result = validateDOIFormat('');
            expect(result.isValid).toBe(false);
            expect(result.error).toBe('DOI is required');
        });

        it('should handle DOI with complex suffix', () => {
            const result = validateDOIFormat('10.1234/complex-suffix/with/slashes');
            expect(result.isValid).toBe(true);
            expect(result.normalizedDOI).toBe('10.1234/complex-suffix/with/slashes');
        });

        it('should trim whitespace', () => {
            const result = validateDOIFormat('  10.1234/example  ');
            expect(result.isValid).toBe(true);
            expect(result.normalizedDOI).toBe('10.1234/example');
        });
    });

    describe('checkDOIRegistration', () => {
        beforeEach(() => {
            vi.clearAllMocks();
        });

        it('should return true for registered DOI', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                status: 200,
            });

            const result = await checkDOIRegistration('10.1234/example');
            expect(result.isRegistered).toBe(true);
            expect(fetch).toHaveBeenCalledWith(
                'https://api.datacite.org/dois/10.1234%2Fexample',
                expect.objectContaining({
                    method: 'GET',
                    headers: {
                        Accept: 'application/vnd.api+json',
                    },
                }),
            );
        });

        it('should return false for unregistered DOI', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                status: 404,
            });

            const result = await checkDOIRegistration('10.1234/notfound');
            expect(result.isRegistered).toBe(false);
            expect(result.error).toBeUndefined();
        });

        it('should handle API errors gracefully', async () => {
            global.fetch = vi.fn().mockResolvedValue({
                status: 500,
            });

            const result = await checkDOIRegistration('10.1234/error');
            expect(result.isRegistered).toBe(false);
            expect(result.error).toBe('Could not verify DOI registration status');
        });

        it('should handle network errors', async () => {
            global.fetch = vi.fn().mockRejectedValue(new Error('Network error'));

            const result = await checkDOIRegistration('10.1234/network-error');
            expect(result.isRegistered).toBe(false);
            expect(result.error).toBe('Network error while checking DOI registration');
        });
    });

    describe('validateYear', () => {
        it('should validate year within range', () => {
            const result = validateYear(2000);
            expect(result.isValid).toBe(true);
        });

        it('should validate current year', () => {
            const currentYear = new Date().getFullYear();
            const result = validateYear(currentYear);
            expect(result.isValid).toBe(true);
        });

        it('should validate next year', () => {
            const nextYear = new Date().getFullYear() + 1;
            const result = validateYear(nextYear);
            expect(result.isValid).toBe(true);
        });

        it('should reject year before 1900', () => {
            const result = validateYear(1899);
            expect(result.isValid).toBe(false);
            expect(result.error).toContain('1900');
        });

        it('should reject year more than current + 1', () => {
            const tooFarFuture = new Date().getFullYear() + 2;
            const result = validateYear(tooFarFuture);
            expect(result.isValid).toBe(false);
        });

        it('should accept year as string', () => {
            const result = validateYear('2020');
            expect(result.isValid).toBe(true);
        });

        it('should reject non-numeric string', () => {
            const result = validateYear('not-a-year');
            expect(result.isValid).toBe(false);
            expect(result.error).toContain('valid number');
        });

        it('should validate boundary year 1900', () => {
            const result = validateYear(1900);
            expect(result.isValid).toBe(true);
        });
    });

    describe('validateSemanticVersion', () => {
        it('should validate basic semantic version', () => {
            const result = validateSemanticVersion('1.2.3');
            expect(result.isValid).toBe(true);
        });

        it('should validate version with pre-release', () => {
            const result = validateSemanticVersion('1.0.0-alpha');
            expect(result.isValid).toBe(true);
        });

        it('should validate version with build metadata', () => {
            const result = validateSemanticVersion('1.0.0+20130313144700');
            expect(result.isValid).toBe(true);
        });

        it('should validate complex version', () => {
            const result = validateSemanticVersion('1.0.0-beta.1+exp.sha.5114f85');
            expect(result.isValid).toBe(true);
        });

        it('should allow empty version (optional field)', () => {
            const result = validateSemanticVersion('');
            expect(result.isValid).toBe(true);
        });

        it('should reject invalid format', () => {
            const result = validateSemanticVersion('1.2');
            expect(result.isValid).toBe(false);
            expect(result.error).toContain('Invalid semantic version');
        });

        it('should reject version with leading zeros', () => {
            const result = validateSemanticVersion('01.2.3');
            expect(result.isValid).toBe(false);
        });

        it('should validate version 0.0.0', () => {
            const result = validateSemanticVersion('0.0.0');
            expect(result.isValid).toBe(true);
        });

        it('should trim whitespace', () => {
            const result = validateSemanticVersion('  1.2.3  ');
            expect(result.isValid).toBe(true);
        });
    });

    describe('validateORCID', () => {
        it('should validate correct ORCID format', () => {
            const result = validateORCID('0000-0002-1825-0097');
            expect(result.isValid).toBe(true);
            expect(result.normalizedORCID).toBe('0000-0002-1825-0097');
        });

        it('should validate ORCID with X checksum', () => {
            // Using a valid ORCID with X checksum: 0000-0002-1694-233X
            const result = validateORCID('0000-0002-1694-233X');
            expect(result.isValid).toBe(true);
        });

        it('should normalize ORCID with URL prefix', () => {
            const result = validateORCID('https://orcid.org/0000-0002-1825-0097');
            expect(result.isValid).toBe(true);
            expect(result.normalizedORCID).toBe('0000-0002-1825-0097');
        });

        it('should normalize ORCID with http prefix', () => {
            const result = validateORCID('http://orcid.org/0000-0002-1825-0097');
            expect(result.isValid).toBe(true);
            expect(result.normalizedORCID).toBe('0000-0002-1825-0097');
        });

        it('should allow empty ORCID (optional field)', () => {
            const result = validateORCID('');
            expect(result.isValid).toBe(true);
        });

        it('should reject invalid format', () => {
            const result = validateORCID('0000-0002-1825');
            expect(result.isValid).toBe(false);
            expect(result.error).toContain('Invalid ORCID format');
        });

        it('should reject invalid checksum', () => {
            const result = validateORCID('0000-0002-1825-0098');
            expect(result.isValid).toBe(false);
            expect(result.error).toContain('Invalid ORCID checksum');
        });

        it('should reject ORCID with letters in wrong position', () => {
            const result = validateORCID('000A-0002-1825-0097');
            expect(result.isValid).toBe(false);
        });

        it('should trim whitespace', () => {
            const result = validateORCID('  0000-0002-1825-0097  ');
            expect(result.isValid).toBe(true);
        });
    });

    describe('validateEmail', () => {
        it('should validate correct email format', () => {
            const result = validateEmail('test@example.com');
            expect(result.isValid).toBe(true);
        });

        it('should validate email with subdomain', () => {
            const result = validateEmail('user@mail.example.com');
            expect(result.isValid).toBe(true);
        });

        it('should validate email with plus sign', () => {
            const result = validateEmail('user+tag@example.com');
            expect(result.isValid).toBe(true);
        });

        it('should validate email with dots', () => {
            const result = validateEmail('first.last@example.com');
            expect(result.isValid).toBe(true);
        });

        it('should reject invalid email format', () => {
            const result = validateEmail('invalid-email');
            expect(result.isValid).toBe(false);
            expect(result.error).toBe('Invalid email format');
        });

        it('should reject empty email', () => {
            const result = validateEmail('');
            expect(result.isValid).toBe(false);
            expect(result.error).toBe('Email is required');
        });

        it('should reject email without @', () => {
            const result = validateEmail('userexample.com');
            expect(result.isValid).toBe(false);
        });

        it('should reject email without domain', () => {
            const result = validateEmail('user@');
            expect(result.isValid).toBe(false);
        });

        it('should trim whitespace', () => {
            const result = validateEmail('  test@example.com  ');
            expect(result.isValid).toBe(true);
        });
    });

    describe('validateDate', () => {
        it('should validate valid date', () => {
            const result = validateDate('2020-01-01');
            expect(result.isValid).toBe(true);
        });

        it('should validate date at minimum boundary (1900-01-01)', () => {
            const result = validateDate('1900-01-01');
            expect(result.isValid).toBe(true);
        });

        it('should reject date before 1900', () => {
            const result = validateDate('1899-12-31');
            expect(result.isValid).toBe(false);
            expect(result.error).toContain('1900');
        });

        it('should reject future date by default', () => {
            const futureDate = new Date();
            futureDate.setFullYear(futureDate.getFullYear() + 1);
            const result = validateDate(futureDate.toISOString());
            expect(result.isValid).toBe(false);
            expect(result.error).toContain('cannot be in the future');
        });

        it('should allow future date when specified', () => {
            const futureDate = new Date();
            futureDate.setFullYear(futureDate.getFullYear() + 1);
            const result = validateDate(futureDate.toISOString(), { allowFuture: true });
            expect(result.isValid).toBe(true);
        });

        it('should reject invalid date format', () => {
            const result = validateDate('not-a-date');
            expect(result.isValid).toBe(false);
            expect(result.error).toBe('Invalid date format');
        });

        it('should reject empty date', () => {
            const result = validateDate('');
            expect(result.isValid).toBe(false);
            expect(result.error).toBe('Date is required');
        });

        it('should respect custom minDate', () => {
            const minDate = new Date('2000-01-01');
            const result = validateDate('1999-12-31', { minDate });
            expect(result.isValid).toBe(false);
        });

        it('should respect custom maxDate', () => {
            const maxDate = new Date('2020-12-31');
            const result = validateDate('2021-01-01', { maxDate, allowFuture: true });
            expect(result.isValid).toBe(false);
        });

        it('should validate today', () => {
            const today = new Date().toISOString();
            const result = validateDate(today);
            expect(result.isValid).toBe(true);
        });
    });

    describe('validateTextLength', () => {
        it('should validate text within range', () => {
            const result = validateTextLength('Hello World', { min: 5, max: 20 });
            expect(result.isValid).toBe(true);
        });

        it('should reject text below minimum', () => {
            const result = validateTextLength('Hi', { min: 5 });
            expect(result.isValid).toBe(false);
            expect(result.error).toContain('at least 5 characters');
        });

        it('should reject text above maximum', () => {
            const result = validateTextLength('This is a very long text', { max: 10 });
            expect(result.isValid).toBe(false);
            expect(result.error).toContain('must not exceed 10 characters');
        });

        it('should provide warning when approaching maximum', () => {
            const result = validateTextLength('123456789', { max: 10 });
            expect(result.isValid).toBe(true);
            expect(result.warning).toBeDefined();
            expect(result.warning).toContain('approaching maximum length');
        });

        it('should use custom field name in messages', () => {
            const result = validateTextLength('', { min: 1, fieldName: 'Title' });
            expect(result.error).toContain('Title');
        });

        it('should handle undefined text', () => {
            const result = validateTextLength(undefined as unknown as string, { min: 1 });
            expect(result.isValid).toBe(false);
        });

        it('should trim text before checking length', () => {
            const result = validateTextLength('  Hello  ', { min: 5, max: 10 });
            expect(result.isValid).toBe(true);
        });

        it('should validate text at exact minimum', () => {
            const result = validateTextLength('12345', { min: 5 });
            expect(result.isValid).toBe(true);
        });

        it('should validate text at exact maximum', () => {
            const result = validateTextLength('1234567890', { max: 10 });
            expect(result.isValid).toBe(true);
        });

        it('should show current length in error message', () => {
            const result = validateTextLength('Hi', { min: 5 });
            expect(result.error).toContain('(current: 2)');
        });
    });

    describe('validateRequired', () => {
        it('should validate non-empty string', () => {
            const result = validateRequired('Hello', 'Name');
            expect(result.isValid).toBe(true);
        });

        it('should reject empty string', () => {
            const result = validateRequired('', 'Name');
            expect(result.isValid).toBe(false);
            expect(result.error).toBe('Name is required');
        });

        it('should reject null value', () => {
            const result = validateRequired(null, 'Email');
            expect(result.isValid).toBe(false);
            expect(result.error).toBe('Email is required');
        });

        it('should reject undefined value', () => {
            const result = validateRequired(undefined, 'Phone');
            expect(result.isValid).toBe(false);
        });

        it('should reject whitespace-only string', () => {
            const result = validateRequired('   ', 'Description');
            expect(result.isValid).toBe(false);
        });

        it('should use default field name if not provided', () => {
            const result = validateRequired('');
            expect(result.error).toBe('Field is required');
        });

        it('should validate string with leading/trailing whitespace', () => {
            const result = validateRequired('  Hello  ', 'Name');
            expect(result.isValid).toBe(true);
        });
    });

    describe('validateTitleUniqueness', () => {
        it('should validate unique main titles', () => {
            const titles = [
                { title: 'First Title', type: 'main' },
                { title: 'Second Title', type: 'main' },
            ];
            const result = validateTitleUniqueness(titles);
            expect(result.isValid).toBe(true);
            expect(Object.keys(result.errors)).toHaveLength(0);
        });

        it('should detect duplicate main titles', () => {
            const titles = [
                { title: 'Same Title', type: 'main' },
                { title: 'Same Title', type: 'main' },
            ];
            const result = validateTitleUniqueness(titles);
            expect(result.isValid).toBe(false);
            expect(result.errors[1]).toBe('Main title must be unique');
        });

        it('should be case-insensitive', () => {
            const titles = [
                { title: 'My Title', type: 'main' },
                { title: 'my title', type: 'main' },
            ];
            const result = validateTitleUniqueness(titles);
            expect(result.isValid).toBe(false);
        });

        it('should ignore non-main titles', () => {
            const titles = [
                { title: 'Main Title', type: 'main' },
                { title: 'Main Title', type: 'alternative' },
            ];
            const result = validateTitleUniqueness(titles);
            expect(result.isValid).toBe(true);
        });

        it('should trim whitespace before comparison', () => {
            const titles = [
                { title: '  Title  ', type: 'main' },
                { title: 'Title', type: 'main' },
            ];
            const result = validateTitleUniqueness(titles);
            expect(result.isValid).toBe(false);
        });

        it('should handle empty titles array', () => {
            const result = validateTitleUniqueness([]);
            expect(result.isValid).toBe(true);
        });

        it('should detect multiple duplicates', () => {
            const titles = [
                { title: 'Title A', type: 'main' },
                { title: 'Title A', type: 'main' },
                { title: 'Title B', type: 'main' },
                { title: 'Title B', type: 'main' },
            ];
            const result = validateTitleUniqueness(titles);
            expect(result.isValid).toBe(false);
            expect(Object.keys(result.errors)).toHaveLength(2);
        });
    });
});
