import { describe, expect, it } from 'vitest';

import { getSelectAllState } from '@/lib/select-all';

describe('getSelectAllState', () => {
    it('returns allChecked when every value is true', () => {
        const result = getSelectAllState([true, true, true]);
        expect(result).toEqual({ allChecked: true, noneChecked: false, indeterminate: false });
    });

    it('returns noneChecked when every value is false', () => {
        const result = getSelectAllState([false, false, false]);
        expect(result).toEqual({ allChecked: false, noneChecked: true, indeterminate: false });
    });

    it('returns indeterminate when values are mixed', () => {
        const result = getSelectAllState([true, false, true]);
        expect(result).toEqual({ allChecked: false, noneChecked: false, indeterminate: true });
    });

    it('returns allChecked and noneChecked for an empty array', () => {
        const result = getSelectAllState([]);
        expect(result).toEqual({ allChecked: true, noneChecked: true, indeterminate: false });
    });

    it('handles a single true value', () => {
        const result = getSelectAllState([true]);
        expect(result).toEqual({ allChecked: true, noneChecked: false, indeterminate: false });
    });

    it('handles a single false value', () => {
        const result = getSelectAllState([false]);
        expect(result).toEqual({ allChecked: false, noneChecked: true, indeterminate: false });
    });

    it('handles a large array of all true', () => {
        const result = getSelectAllState(Array(100).fill(true));
        expect(result.allChecked).toBe(true);
        expect(result.indeterminate).toBe(false);
    });

    it('handles a large array with one false', () => {
        const values = Array(99).fill(true);
        values.push(false);
        const result = getSelectAllState(values);
        expect(result.allChecked).toBe(false);
        expect(result.noneChecked).toBe(false);
        expect(result.indeterminate).toBe(true);
    });
});
