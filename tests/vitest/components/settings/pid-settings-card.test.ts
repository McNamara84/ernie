import { describe, expect, it } from 'vitest';

import { getTypeLabels } from '@/components/settings/pid-settings-card';

describe('getTypeLabels', () => {
    it('returns b2inst labels for PID4INST settings by default', () => {
        expect(getTypeLabels('pid4inst')).toEqual({
            countLabel: 'instruments',
            sourceName: 'b2inst',
        });
    });

    it('returns ROR labels for organization settings', () => {
        expect(getTypeLabels('ror')).toEqual({
            countLabel: 'organizations',
            sourceName: 'ROR',
        });
    });

    it('returns RAiD labels for research activity settings', () => {
        expect(getTypeLabels('raid')).toEqual({
            countLabel: 'projects',
            sourceName: 'DataCite RAiD search',
        });
    });
});