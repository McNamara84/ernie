import { describe, expect, it } from 'vitest';

import { normalizeDoiKey, resolveIdentifierUrl } from '@/pages/LandingPages/lib/resolveIdentifierUrl';

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

    it('resolves RAiD to raid.org', () => {
        expect(resolveIdentifierUrl('10.25518/raid.12345', 'RAiD')).toBe(
            'https://raid.org/10.25518/raid.12345',
        );
    });

    it('returns null for unsupported identifier types', () => {
        expect(resolveIdentifierUrl('12345', 'EAN13')).toBeNull();
        expect(resolveIdentifierUrl('12345', 'PMID')).toBeNull();
        expect(resolveIdentifierUrl('12345', 'LSID')).toBeNull();
        expect(resolveIdentifierUrl('12345', 'UnknownType')).toBeNull();
    });

    it('returns null for empty identifier string', () => {
        expect(resolveIdentifierUrl('', 'DOI')).toBeNull();
    });

    it('returns null for whitespace-only identifier string', () => {
        expect(resolveIdentifierUrl('   ', 'DOI')).toBeNull();
        expect(resolveIdentifierUrl('\t', 'URL')).toBeNull();
    });

    it('returns null for dangerous URL schemes (javascript:)', () => {
        expect(resolveIdentifierUrl('javascript:alert(1)', 'URL')).toBeNull();
    });

    it('returns null for data: URL scheme', () => {
        expect(resolveIdentifierUrl('data:text/html,<script>alert(1)</script>', 'URL')).toBeNull();
    });

    it('returns null for relative URLs', () => {
        expect(resolveIdentifierUrl('/some/path', 'URL')).toBeNull();
    });

    it('allows https URLs', () => {
        expect(resolveIdentifierUrl('https://example.com/data', 'URL')).toBe('https://example.com/data');
    });

    it('allows http URLs', () => {
        expect(resolveIdentifierUrl('http://example.com/data', 'URL')).toBe('http://example.com/data');
    });

    describe('DOI URL normalization', () => {
        it('strips https://doi.org/ prefix from DOI', () => {
            expect(resolveIdentifierUrl('https://doi.org/10.5880/GFZ.1.1.2024.002', 'DOI')).toBe(
                'https://doi.org/10.5880/GFZ.1.1.2024.002',
            );
        });

        it('strips http://doi.org/ prefix from DOI', () => {
            expect(resolveIdentifierUrl('http://doi.org/10.5880/GFZ.1.1.2024.002', 'DOI')).toBe(
                'https://doi.org/10.5880/GFZ.1.1.2024.002',
            );
        });

        it('strips https://dx.doi.org/ prefix from DOI', () => {
            expect(resolveIdentifierUrl('https://dx.doi.org/10.5880/GFZ.1.1.2024.002', 'DOI')).toBe(
                'https://doi.org/10.5880/GFZ.1.1.2024.002',
            );
        });

        it('strips http://dx.doi.org/ prefix from DOI', () => {
            expect(resolveIdentifierUrl('http://dx.doi.org/10.5880/GFZ.1.1.2024.002', 'DOI')).toBe(
                'https://doi.org/10.5880/GFZ.1.1.2024.002',
            );
        });
    });

    describe('Handle URL normalization', () => {
        it('strips https://hdl.handle.net/ prefix from Handle', () => {
            expect(resolveIdentifierUrl('https://hdl.handle.net/10013/epic.12345', 'Handle')).toBe(
                'https://hdl.handle.net/10013/epic.12345',
            );
        });

        it('strips http://hdl.handle.net/ prefix from Handle', () => {
            expect(resolveIdentifierUrl('http://hdl.handle.net/10013/epic.12345', 'Handle')).toBe(
                'https://hdl.handle.net/10013/epic.12345',
            );
        });
    });
});

describe('normalizeDoiKey', () => {
    it('returns bare DOI unchanged', () => {
        expect(normalizeDoiKey('10.5880/GFZ.1.1.2024.002')).toBe('10.5880/GFZ.1.1.2024.002');
    });

    it('strips https://doi.org/ prefix', () => {
        expect(normalizeDoiKey('https://doi.org/10.5880/GFZ.1.1.2024.002')).toBe('10.5880/GFZ.1.1.2024.002');
    });

    it('strips https://dx.doi.org/ prefix', () => {
        expect(normalizeDoiKey('https://dx.doi.org/10.5880/GFZ.1.1.2024.002')).toBe('10.5880/GFZ.1.1.2024.002');
    });

    it('strips http://doi.org/ prefix', () => {
        expect(normalizeDoiKey('http://doi.org/10.5880/GFZ.1.1.2024.002')).toBe('10.5880/GFZ.1.1.2024.002');
    });

    it('trims whitespace', () => {
        expect(normalizeDoiKey('  10.5880/test  ')).toBe('10.5880/test');
    });

    it('strips prefix and trims combined', () => {
        expect(normalizeDoiKey('  https://dx.doi.org/10.5880/test  ')).toBe('10.5880/test');
    });

    it('returns empty string for empty input', () => {
        expect(normalizeDoiKey('')).toBe('');
        expect(normalizeDoiKey('   ')).toBe('');
    });
});
