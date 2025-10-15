import '@testing-library/jest-dom/vitest';

import { render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import Editor from '@/pages/editor';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: React.ReactNode }) => {
        if (title) document.title = title;
        return <>{children}</>;
    },
}));

vi.mock('@/components/curation/datacite-form', () => ({
    default: () => <div />,
}));

describe('Editor integration', () => {
    beforeEach(() => {
        document.title = '';
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve([]) })));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('sets the document title', () => {
        render(<Editor maxTitles={99} maxLicenses={99} />);
        expect(document.title).toBe('Editor');
    });
});
