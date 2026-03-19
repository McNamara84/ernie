import { describe, expect, it } from 'vitest';

import { getSelectAllState } from '@/lib/select-all';

describe('getSelectAllState', () => {
    it('returns isEmpty for empty array', () => {
        const state = getSelectAllState([]);
        expect(state.isEmpty).toBe(true);
        expect(state.allChecked).toBe(false);
        expect(state.noneChecked).toBe(true);
        expect(state.indeterminate).toBe(false);
    });

    it('returns allChecked when all true', () => {
        const state = getSelectAllState([true, true, true]);
        expect(state.allChecked).toBe(true);
        expect(state.noneChecked).toBe(false);
        expect(state.indeterminate).toBe(false);
        expect(state.isEmpty).toBe(false);
    });

    it('returns noneChecked when all false', () => {
        const state = getSelectAllState([false, false, false]);
        expect(state.allChecked).toBe(false);
        expect(state.noneChecked).toBe(true);
        expect(state.indeterminate).toBe(false);
    });

    it('returns indeterminate when mixed', () => {
        const state = getSelectAllState([true, false, true]);
        expect(state.allChecked).toBe(false);
        expect(state.noneChecked).toBe(false);
        expect(state.indeterminate).toBe(true);
    });

    it('handles single true value', () => {
        const state = getSelectAllState([true]);
        expect(state.allChecked).toBe(true);
        expect(state.noneChecked).toBe(false);
    });

    it('handles single false value', () => {
        const state = getSelectAllState([false]);
        expect(state.noneChecked).toBe(true);
        expect(state.allChecked).toBe(false);
    });
});
