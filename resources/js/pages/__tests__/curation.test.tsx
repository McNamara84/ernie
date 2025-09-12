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
                maxTitles={99}
                maxLicenses={99}
            />,
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ resourceTypes, titleTypes, licenses })
        );
    });

    it('passes limits to DataCiteForm', () => {
        const resourceTypes: ResourceType[] = [];
        const titleTypes: TitleType[] = [];
        const licenses: License[] = [];
        render(
            <Curation
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                maxTitles={5}
                maxLicenses={7}
            />,
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ maxTitles: 5, maxLicenses: 7 })
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
                maxTitles={99}
                maxLicenses={99}
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
                maxTitles={99}
                maxLicenses={99}
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
                maxTitles={99}
                maxLicenses={99}
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
                maxTitles={99}
                maxLicenses={99}
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
                maxTitles={99}
                maxLicenses={99}
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
                maxTitles={99}
                maxLicenses={99}
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
                maxTitles={99}
                maxLicenses={99}
                initialLicenses={initialLicenses}
            />,
        );
        expect(renderForm).toHaveBeenCalledWith(
            expect.objectContaining({ initialLicenses })
        );
    });
});
