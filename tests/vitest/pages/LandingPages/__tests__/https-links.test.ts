import { describe, expect, it } from 'vitest';

import { splitHttpsLinks } from '@/pages/LandingPages/lib/https-links';

describe('splitHttpsLinks', () => {
    it('linkifies only complete HTTPS URLs and preserves surrounding text', () => {
        expect(splitHttpsLinks('See https://example.org/data and http://legacy.example.org/data.')).toEqual([
            { type: 'text', value: 'See ' },
            { type: 'link', value: 'https://example.org/data' },
            { type: 'text', value: ' and http://legacy.example.org/data.' },
        ]);
    });

    it('detaches sentence punctuation and unbalanced closing brackets', () => {
        expect(splitHttpsLinks('See (https://example.org/a_(b)). Next: https://example.org/two!')).toEqual([
            { type: 'text', value: 'See (' },
            { type: 'link', value: 'https://example.org/a_(b)' },
            { type: 'text', value: '). Next: ' },
            { type: 'link', value: 'https://example.org/two' },
            { type: 'text', value: '!' },
        ]);
    });

    it('keeps malformed HTTPS candidates and HTML-like content as text', () => {
        expect(splitHttpsLinks('<strong>https://</strong> &amp; https://?')).toEqual([
            { type: 'text', value: '<strong>https://</strong> &amp; https://?' },
        ]);
    });

    it('returns one text segment when no HTTPS URL exists and no segments for empty text', () => {
        expect(splitHttpsLinks('Plain text only.')).toEqual([{ type: 'text', value: 'Plain text only.' }]);
        expect(splitHttpsLinks('')).toEqual([]);
    });
});
