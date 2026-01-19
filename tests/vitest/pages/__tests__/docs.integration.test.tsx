import '@testing-library/jest-dom/vitest';

import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Docs from '@/pages/docs';
import type { EditorSettings } from '@/types/docs';

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
    },
    features: {
        hasActiveGcmd: true,
        hasActiveMsl: true,
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

describe('Docs integration', () => {
    beforeEach(() => {
        document.title = '';
    });

    it('sets the document title', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        expect(document.title).toBe('Documentation');
    });
});

