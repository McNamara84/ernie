import { describe, expect, it } from 'vitest';

import { resolveIdentifierUrl } from '@/pages/LandingPages/lib/resolveIdentifierUrl';

import cases from '../../fixtures/identifiers/url-cases.json';

describe('shared server/client identifier resolution', () => {
    it.each(cases)('$type: $value', ({ value, type, url }) => {
        expect(resolveIdentifierUrl(value, type)).toBe(url);
    });
});
