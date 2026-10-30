import fixtures from '@tests/fixtures/identifiers/swhid-cases.json';
import { describe, expect, it } from 'vitest';

import { validateIdentifierFormat } from '@/lib/doi-validation';
import { detectIdentifierType, normalizeIdentifier } from '@/lib/identifier-type-detection';
import { resolveIdentifierUrl } from '@/pages/LandingPages/lib/resolveIdentifierUrl';

describe('SWHID resolver and editor integration', () => {
    it.each(fixtures.cases)('$name', ({ input, valid, normalized, candidate, resolverUrl }) => {
        expect(resolveIdentifierUrl(input, 'SWHID')).toBe(resolverUrl);
        expect(validateIdentifierFormat(input, 'SWHID').isValid).toBe(valid);
        expect(normalizeIdentifier(input, 'SWHID')).toBe(normalized);
        if (candidate) expect(detectIdentifierType(input)).toBe('SWHID');
        else expect(detectIdentifierType(input)).not.toBe('SWHID');
    });
});
