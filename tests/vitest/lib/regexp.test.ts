import { describe, expect, it, vi } from 'vitest';

import { escapeForRegExp } from '@/lib/regexp';

describe('escapeForRegExp', () => {
    it('escapes regex metacharacters', () => {
        const pattern = new RegExp(`^${escapeForRegExp('C++ (beta)?')}$`);

        expect(pattern.test('C++ (beta)?')).toBe(true);
        expect(pattern.test('C beta')).toBe(false);
    });

    it('uses RegExp.escape when available', () => {
        const regExpWithEscape = RegExp as RegExpConstructor & { escape?: (value: string) => string };
        const originalEscape = regExpWithEscape.escape;
        const escapeSpy = vi.fn((value: string) => `native:${value}`);
        regExpWithEscape.escape = escapeSpy;

        try {
            expect(escapeForRegExp('value')).toBe('native:value');
            expect(escapeSpy).toHaveBeenCalledWith('value');
        } finally {
            regExpWithEscape.escape = originalEscape;
        }
    });
});