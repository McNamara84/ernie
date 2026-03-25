import { describe, expect, it } from 'vitest';

import { resolveIdentifierUrl } from '@/pages/LandingPages/lib/resolveIdentifierUrl';

describe('resolveIdentifierUrl', () => {
    it('resolves DOI to doi.org', () => {
        expect(resolveIdentifierUrl('10.5880/GFZ.1.1.2024.002', 'DOI')).toBe(
            'https://doi.org/10.5880/GFZ.1.1.2024.002',
        );
    });

    it('returns URL identifier directly', () => {
        expect(resolveIdentifierUrl('https://example.com/data', 'URL')).toBe('https://example.com/data');
    });

    it('resolves Handle to hdl.handle.net', () => {
        expect(resolveIdentifierUrl('10013/epic.12345', 'Handle')).toBe(
            'https://hdl.handle.net/10013/epic.12345',
        );
    });

    it('resolves arXiv to arxiv.org', () => {
        expect(resolveIdentifierUrl('2301.01234', 'arXiv')).toBe('https://arxiv.org/abs/2301.01234');
    });

    it('resolves IGSN to igsn.org', () => {
        expect(resolveIdentifierUrl('ICDP5054EHW1001', 'IGSN')).toBe('https://igsn.org/ICDP5054EHW1001');
    });

    it('resolves ISBN to worldcat', () => {
        expect(resolveIdentifierUrl('978-3-16-148410-0', 'ISBN')).toBe(
            'https://search.worldcat.org/isbn/978-3-16-148410-0',
        );
    });

    it('resolves ISSN to issn.org', () => {
        expect(resolveIdentifierUrl('0378-5955', 'ISSN')).toBe(
            'https://portal.issn.org/resource/ISSN/0378-5955',
        );
    });

    it('resolves URN to nbn-resolving.org', () => {
        expect(resolveIdentifierUrl('urn:nbn:de:kobv:b4-200905193913', 'URN')).toBe(
            'https://nbn-resolving.org/urn:nbn:de:kobv:b4-200905193913',
        );
    });

    it('resolves RAiD to doi.org', () => {
        expect(resolveIdentifierUrl('10.25518/raid.12345', 'RAiD')).toBe(
            'https://doi.org/10.25518/raid.12345',
        );
    });

    it('returns null for unsupported identifier types', () => {
        expect(resolveIdentifierUrl('12345', 'EAN13')).toBeNull();
        expect(resolveIdentifierUrl('12345', 'PMID')).toBeNull();
        expect(resolveIdentifierUrl('12345', 'LSID')).toBeNull();
        expect(resolveIdentifierUrl('12345', 'UnknownType')).toBeNull();
    });

    it('handles empty identifier string', () => {
        expect(resolveIdentifierUrl('', 'DOI')).toBe('https://doi.org/');
    });
});
