import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import Curation from '../curation';
import type { ResourceType, TitleType } from '@/types';

const renderForm = vi.fn(() => null);

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/curation/datacite-form', () => ({
    default: (props: unknown) => {
        renderForm(props);
        return <div data-testid="datacite-form" />;
    },
}));

describe('Curation page', () => {
    it('passes resource and title types to DataCiteForm', () => {
        const resourceTypes: ResourceType[] = [
            { id: 1, name: 'Dataset', slug: 'dataset' },
        ];
        const titleTypes: TitleType[] = [
            { id: 1, name: 'Main Title', slug: 'main-title' },
        ];
        render(<Curation resourceTypes={resourceTypes} titleTypes={titleTypes} />);
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ resourceTypes, titleTypes })
        );
    });

    it('passes doi to DataCiteForm when provided', () => {
        const resourceTypes: ResourceType[] = [
            { id: 1, name: 'Dataset', slug: 'dataset' },
        ];
        const titleTypes: TitleType[] = [
            { id: 1, name: 'Main Title', slug: 'main-title' },
        ];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                doi="10.1234/xyz"
            />,
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ initialDoi: '10.1234/xyz' })
        );
    });

    it('passes year to DataCiteForm when provided', () => {
        const resourceTypes: ResourceType[] = [
            { id: 1, name: 'Dataset', slug: 'dataset' },
        ];
        const titleTypes: TitleType[] = [
            { id: 1, name: 'Main Title', slug: 'main-title' },
        ];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                year="2024"
            />, 
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ initialYear: '2024' })
        );
    });

    it('passes version to DataCiteForm when provided', () => {
        const resourceTypes: ResourceType[] = [
            { id: 1, name: 'Dataset', slug: 'dataset' },
        ];
        const titleTypes: TitleType[] = [
            { id: 1, name: 'Main Title', slug: 'main-title' },
        ];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                version="2.0"
            />,
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ initialVersion: '2.0' })
        );
    });
});
