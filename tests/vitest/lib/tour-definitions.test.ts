import { describe, expect, it } from 'vitest';

import { getGuidedTourDefinition } from '@/lib/tours/definitions';

describe('guided tour definitions', () => {
    it('returns the configured beginner dashboard tour definition', () => {
        const definition = getGuidedTourDefinition('beginner-dashboard-main-menu', 1);

        expect(definition).not.toBeNull();
        expect(definition).toMatchObject({
            key: 'beginner-dashboard-main-menu',
            version: 1,
        });
        expect(definition?.steps).toHaveLength(8);
        expect(definition?.steps[0]).toMatchObject({
            id: 'dashboard-welcome',
            title: 'Welcome to ERNIE',
        });
        expect(definition?.steps[7]).toMatchObject({
            id: 'sidebar-documentation',
            align: 'end',
        });
    });

    it('returns null for unknown guided tour versions', () => {
        expect(getGuidedTourDefinition('beginner-dashboard-main-menu', 2)).toBeNull();
        expect(getGuidedTourDefinition('missing-tour', 1)).toBeNull();
    });
});