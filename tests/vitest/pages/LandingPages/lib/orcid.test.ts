import { describe, expect, it } from 'vitest';

import { resolveOrcidUrl } from '@/pages/LandingPages/lib/orcid';

describe('resolveOrcidUrl', () => {
    it.each([
        ['bare identifier', '0000-0002-1825-0097', 'https://orcid.org/0000-0002-1825-0097'],
        ['canonical URL', 'https://orcid.org/0000-0002-1825-0097', 'https://orcid.org/0000-0002-1825-0097'],
        ['HTTP URL', 'http://orcid.org/0000-0002-1825-0097', 'https://orcid.org/0000-0002-1825-0097'],
        ['www URL', 'https://www.orcid.org/0000-0002-1825-0097', 'https://orcid.org/0000-0002-1825-0097'],
        ['uppercase URL', 'HTTPS://WWW.ORCID.ORG/0000-0002-1825-0097', 'https://orcid.org/0000-0002-1825-0097'],
        ['protocol-less URL', 'orcid.org/0000-0002-1825-0097', 'https://orcid.org/0000-0002-1825-0097'],
        ['protocol-less www URL', 'www.orcid.org/0000-0002-1825-0097', 'https://orcid.org/0000-0002-1825-0097'],
        ['surrounding whitespace and trailing slash', '  https://orcid.org/0000-0002-1825-0097/  ', 'https://orcid.org/0000-0002-1825-0097'],
        ['lowercase checksum character', '0000-0002-1694-233x', 'https://orcid.org/0000-0002-1694-233X'],
    ])('normalizes a %s', (_description, identifier, expected) => {
        expect(resolveOrcidUrl(identifier)).toBe(expected);
    });

    it.each([
        ['null', null],
        ['undefined', undefined],
        ['an empty string', ''],
        ['whitespace', '   '],
        ['an incomplete ORCID URL', 'https://orcid.org/'],
        ['a checksum-invalid bare identifier', '0000-0002-1825-0098'],
        ['a checksum-invalid canonical URL', 'https://orcid.org/0000-0002-1825-0098'],
        ['an invalid identifier', 'not-an-orcid'],
        ['a foreign URL', 'https://example.com/0000-0002-1825-0097'],
        ['an identifier with extra path segments', '0000-0002-1825-0097/profile'],
    ])('rejects %s', (_description, identifier) => {
        expect(resolveOrcidUrl(identifier)).toBeNull();
    });
});
