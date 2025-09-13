import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import Curation from '../curation';
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import type { TitleType, License } from '@/types';

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

describe('Curation integration', () => {
    beforeEach(() => {
        document.title = '';
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve([]) })));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('sets the document title', () => {
        const titleTypes: TitleType[] = [];
        const licenses: License[] = [];
        render(
            <Curation
                titleTypes={titleTypes}
                licenses={licenses}
                maxTitles={99}
                maxLicenses={99}
            />,
        );
        expect(document.title).toBe('Curation');
    });
});
