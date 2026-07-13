import '@testing-library/jest-dom/vitest';

import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Docs from '@/pages/docs';
import type { DataCiteDocsSettings, EditorSettings } from '@/types/docs';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: React.ReactNode }) => {
        if (title) document.title = title;
        return <>{children}</>;
    },
}));

// Mock IntersectionObserver for scroll spy
global.IntersectionObserver = class IntersectionObserver {
    observe() {}
    disconnect() {}
    unobserve() {}
    takeRecords() {
        return [];
    }
    root = null;
    rootMargin = '';
    thresholds = [];
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
} as any;

// Default editor settings for tests
const defaultEditorSettings: EditorSettings = {
    thesauri: {
        scienceKeywords: true,
        platforms: true,
        instruments: true,
        chronostratigraphy: true,
        gemet: true,
        analyticalMethods: true,
        euroSciVoc: true,
    },
    features: {
        hasActiveGcmd: true,
        hasActiveMsl: true,
        hasActiveChronostrat: true,
        hasActiveGemet: true,
        hasActiveAnalyticalMethods: true,
        hasActiveEuroSciVoc: true,
        hasActiveLicenses: true,
        hasActiveResourceTypes: true,
        hasActiveTitleTypes: true,
        hasActiveLanguages: true,
    },
    limits: {
        maxTitles: 10,
        maxLicenses: 5,
    },
};

const defaultDataCite: DataCiteDocsSettings = {
    currentMode: 'test',
    isTestModeForcedForUser: false,
    testPrefixes: ['10.83279', '10.83186', '10.83114'],
    productionPrefixes: ['10.5880', '10.1594', '10.14470'],
    testEndpoint: 'https://api.test.datacite.org',
    productionEndpoint: 'https://api.datacite.org',
};
describe('Docs integration', () => {
    beforeEach(() => {
        document.title = '';
    });

    it('sets the document title', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        expect(document.title).toBe('Documentation');
    });
});
