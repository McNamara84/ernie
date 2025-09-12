import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import Curation from '../curation';
import type { ResourceType, TitleType, License } from '@/types';

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
    it('passes resource, title types and licenses to DataCiteForm', () => {
        const resourceTypes: ResourceType[] = [
            { id: 1, name: 'Dataset', slug: 'dataset' },
        ];
        const titleTypes: TitleType[] = [
            { id: 1, name: 'Main Title', slug: 'main-title' },
        ];
        const licenses: License[] = [
            { id: 1, identifier: 'MIT', name: 'MIT License' },
        ];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
            />,
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ resourceTypes, titleTypes, licenses })
        );
    });

    it('passes doi to DataCiteForm when provided', () => {
        const resourceTypes: ResourceType[] = [
            { id: 1, name: 'Dataset', slug: 'dataset' },
        ];
        const titleTypes: TitleType[] = [
            { id: 1, name: 'Main Title', slug: 'main-title' },
        ];
        const licenses: License[] = [
            { id: 1, identifier: 'MIT', name: 'MIT License' },
        ];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
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
        const licenses: License[] = [
            { id: 1, identifier: 'MIT', name: 'MIT License' },
        ];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
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
        const licenses: License[] = [
            { id: 1, identifier: 'MIT', name: 'MIT License' },
        ];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                version="2.0"
            />,
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ initialVersion: '2.0' })
        );
    });

    it('passes language to DataCiteForm when provided', () => {
        const resourceTypes: ResourceType[] = [
            { id: 1, name: 'Dataset', slug: 'dataset' },
        ];
        const titleTypes: TitleType[] = [
            { id: 1, name: 'Main Title', slug: 'main-title' },
        ];
        const licenses: License[] = [
            { id: 1, identifier: 'MIT', name: 'MIT License' },
        ];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                language="de"
            />,
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ initialLanguage: 'de' })
        );
    });

    it('passes resource type to DataCiteForm when provided', () => {
        const resourceTypes: ResourceType[] = [
            { id: 1, name: 'Dataset', slug: 'dataset' },
        ];
        const titleTypes: TitleType[] = [
            { id: 1, name: 'Main Title', slug: 'main-title' },
        ];
        const licenses: License[] = [
            { id: 1, identifier: 'MIT', name: 'MIT License' },
        ];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                resourceType="dataset"
            />,
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ initialResourceType: 'dataset' })
        );
    });

    it('passes titles to DataCiteForm when provided', () => {
        const resourceTypes: ResourceType[] = [
            { id: 1, name: 'Dataset', slug: 'dataset' },
        ];
        const titleTypes: TitleType[] = [
            { id: 1, name: 'Main Title', slug: 'main-title' },
        ];
        const licenses: License[] = [
            { id: 1, identifier: 'MIT', name: 'MIT License' },
        ];
        const titles = [
            { title: 'Main', titleType: 'main-title' },
            { title: 'Alt', titleType: 'alternative-title' },
        ];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                titles={titles}
            />,
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ initialTitles: titles })
        );
    });

    it('passes initial licenses to DataCiteForm when provided', () => {
        const resourceTypes: ResourceType[] = [
            { id: 1, name: 'Dataset', slug: 'dataset' },
        ];
        const titleTypes: TitleType[] = [
            { id: 1, name: 'Main Title', slug: 'main-title' },
        ];
        const licenses: License[] = [
            { id: 1, identifier: 'MIT', name: 'MIT License' },
        ];
        const initialLicenses = ['MIT'];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                initialLicenses={initialLicenses}
            />,
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ initialLicenses })
        );
    });
});
