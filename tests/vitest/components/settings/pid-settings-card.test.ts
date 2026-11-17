import { describe, expect, it } from 'vitest';

import { getCompletedPidLocalStatus, getTypeLabels } from '@/components/settings/pid-settings-card';

describe('getTypeLabels', () => {
    it('names both instrument sources for PIDINST settings', () => {
        expect(getTypeLabels('pid4inst')).toEqual({
            countLabel: 'instruments',
            sourceName: 'B2INST + DataCite',
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

describe('getCompletedPidLocalStatus', () => {
    it('uses the remote count and completion timestamp after a successful update', () => {
        expect(getCompletedPidLocalStatus({ itemCount: 0 }, { remoteCount: 570 }, '2026-06-26T03:00:00Z')).toEqual({
            exists: true,
            itemCount: 570,
            lastUpdated: '2026-06-26T03:00:00Z',
        });
    });

    it('falls back to the current item count when no update comparison is available', () => {
        expect(getCompletedPidLocalStatus({ itemCount: 42 }, null, '2026-06-26T03:05:00Z')).toEqual({
            exists: true,
            itemCount: 42,
            lastUpdated: '2026-06-26T03:05:00Z',
        });
    });
});
