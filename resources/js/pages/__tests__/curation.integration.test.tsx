import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import Curation from '../curation';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ResourceType, TitleType } from '@/types';

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
    });

    it('sets the document title', () => {
        const resourceTypes: ResourceType[] = [];
        const titleTypes: TitleType[] = [];
        render(<Curation resourceTypes={resourceTypes} titleTypes={titleTypes} />);
        expect(document.title).toBe('Curation');
    });
});

