import { fromJSON, toJSON } from 'seroval';
import { describe, expect, it } from 'vitest';

describe('Query devtools runtime dependencies', () => {
    it('round-trips query data with the patched Seroval version used by Solid.js', () => {
        const data = {
            updatedAt: new Date('2026-10-06T00:00:00Z'),
            resources: new Map([['doi', { id: 42, title: 'Dataset' }]]),
            bytes: new Uint8Array([1, 2, 3]),
        };

        expect(fromJSON(toJSON(data))).toEqual(data);
    });
});
