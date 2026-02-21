import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    disableFeature,
    enableFeature,
    FEATURE_FLAGS,
    getFeatureFlagStatus,
    isFeatureEnabled,
    resetFeature,
    useFeatureFlag,
} from '@/config/feature-flags';

describe('Feature Flags', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    afterEach(() => {
        localStorage.clear();
    });

    describe('FEATURE_FLAGS', () => {
        it('has all flags defined', () => {
            expect(FEATURE_FLAGS).toHaveProperty('USE_NEW_FORM_SYSTEM');
            expect(FEATURE_FLAGS).toHaveProperty('USE_NEW_DATACITE_FORM');
            expect(FEATURE_FLAGS).toHaveProperty('USE_NEW_DATE_PICKER');
        });

        it('all flags default to false', () => {
            for (const value of Object.values(FEATURE_FLAGS)) {
                expect(value).toBe(false);
            }
        });
    });

    describe('isFeatureEnabled', () => {
        it('returns default value when no override', () => {
            expect(isFeatureEnabled('USE_NEW_FORM_SYSTEM')).toBe(false);
        });

        it('returns true when localStorage override is true', () => {
            localStorage.setItem('ff_USE_NEW_FORM_SYSTEM', 'true');
            expect(isFeatureEnabled('USE_NEW_FORM_SYSTEM')).toBe(true);
        });

        it('returns false when localStorage override is false', () => {
            localStorage.setItem('ff_USE_NEW_FORM_SYSTEM', 'false');
            expect(isFeatureEnabled('USE_NEW_FORM_SYSTEM')).toBe(false);
        });
    });

    describe('useFeatureFlag', () => {
        it('delegates to isFeatureEnabled', () => {
            expect(useFeatureFlag('USE_NEW_FORM_SYSTEM')).toBe(false);
            localStorage.setItem('ff_USE_NEW_FORM_SYSTEM', 'true');
            expect(useFeatureFlag('USE_NEW_FORM_SYSTEM')).toBe(true);
        });
    });

    describe('enableFeature', () => {
        it('sets localStorage to true', () => {
            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
            enableFeature('USE_NEW_FORM_SYSTEM');
            expect(localStorage.getItem('ff_USE_NEW_FORM_SYSTEM')).toBe('true');
            expect(isFeatureEnabled('USE_NEW_FORM_SYSTEM')).toBe(true);
            consoleSpy.mockRestore();
        });
    });

    describe('disableFeature', () => {
        it('sets localStorage to false', () => {
            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
            disableFeature('USE_NEW_FORM_SYSTEM');
            expect(localStorage.getItem('ff_USE_NEW_FORM_SYSTEM')).toBe('false');
            expect(isFeatureEnabled('USE_NEW_FORM_SYSTEM')).toBe(false);
            consoleSpy.mockRestore();
        });
    });

    describe('resetFeature', () => {
        it('removes localStorage override', () => {
            const consoleSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
            localStorage.setItem('ff_USE_NEW_FORM_SYSTEM', 'true');
            resetFeature('USE_NEW_FORM_SYSTEM');
            expect(localStorage.getItem('ff_USE_NEW_FORM_SYSTEM')).toBeNull();
            expect(isFeatureEnabled('USE_NEW_FORM_SYSTEM')).toBe(false);
            consoleSpy.mockRestore();
        });
    });

    describe('getFeatureFlagStatus', () => {
        it('returns status for all flags', () => {
            const status = getFeatureFlagStatus();
            for (const flag of Object.keys(FEATURE_FLAGS)) {
                expect(status[flag as keyof typeof FEATURE_FLAGS]).toBeDefined();
                expect(status[flag as keyof typeof FEATURE_FLAGS]).toHaveProperty('default');
                expect(status[flag as keyof typeof FEATURE_FLAGS]).toHaveProperty('current');
                expect(status[flag as keyof typeof FEATURE_FLAGS]).toHaveProperty('overridden');
            }
        });

        it('shows overridden status when localStorage is set', () => {
            localStorage.setItem('ff_USE_NEW_FORM_SYSTEM', 'true');
            const status = getFeatureFlagStatus();
            expect(status.USE_NEW_FORM_SYSTEM).toEqual({
                default: false,
                current: true,
                overridden: true,
            });
        });

        it('shows non-overridden status by default', () => {
            const status = getFeatureFlagStatus();
            expect(status.USE_NEW_FORM_SYSTEM).toEqual({
                default: false,
                current: false,
                overridden: false,
            });
        });
    });
});
