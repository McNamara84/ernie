import { describe, expect, it } from 'vitest';

import {
    allowsFutureEditorDate,
    isAvailableDateType,
    isDateRangeCapable,
    isEditableDateType,
    normalizeDateTypeSlug,
} from '@/components/curation/utils/date-rules';

describe('date-rules', () => {
    it.each(['collected', 'Collected', ' valid ', 'OTHER', 'Available'])('permits future dates for %s', (type) => {
        expect(allowsFutureEditorDate(type)).toBe(true);
    });

    it.each(['created', 'Copyrighted', 'submitted', 'withdrawn', 'accepted', 'issued', 'updated', 'coverage', 'unknown', '', null, undefined])(
        'does not permit future dates for %s',
        (type) => {
            expect(allowsFutureEditorDate(type)).toBe(false);
        },
    );

    it.each(['collected', 'valid', 'other'])('keeps future %s dates separate from the Available embargo policy', (type) => {
        expect(allowsFutureEditorDate(type)).toBe(true);
        expect(isAvailableDateType(type)).toBe(false);
    });

    it('normalizes date type slugs defensively', () => {
        expect(normalizeDateTypeSlug('  Collected  ')).toBe('collected');
        expect(normalizeDateTypeSlug(null)).toBe('');
        expect(normalizeDateTypeSlug(undefined)).toBe('');
    });

    it('allows date periods only for Created, Collected, Valid, and Other', () => {
        expect(isDateRangeCapable('Created')).toBe(true);
        expect(isDateRangeCapable('Collected')).toBe(true);
        expect(isDateRangeCapable('valid')).toBe(true);
        expect(isDateRangeCapable('OTHER')).toBe(true);
        expect(isDateRangeCapable('available')).toBe(false);
        expect(isDateRangeCapable('coverage')).toBe(false);
    });

    it('keeps system-managed and coverage date types out of the editable Dates section', () => {
        expect(isEditableDateType('accepted')).toBe(false);
        expect(isEditableDateType('issued')).toBe(false);
        expect(isEditableDateType('updated')).toBe(false);
        expect(isEditableDateType('coverage')).toBe(false);
        expect(isEditableDateType('created')).toBe(true);
        expect(isEditableDateType('collected')).toBe(true);
    });
});
