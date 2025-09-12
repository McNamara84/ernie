import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, describe, it, expect } from 'vitest';
import DataCiteForm, { canAddLicense } from '../datacite-form';
import { LANGUAGE_OPTIONS } from '@/constants/languages';
import type { ResourceType, TitleType, License } from '@/types';

describe('DataCiteForm', () => {
    beforeAll(() => {
        // Polyfill methods required by Radix UI Select
        Element.prototype.hasPointerCapture = () => false;
        Element.prototype.setPointerCapture = () => {};
        Element.prototype.releasePointerCapture = () => {};
        Element.prototype.scrollIntoView = () => {};
    });

    const resourceTypes: ResourceType[] = [
        { id: 1, name: 'Dataset', slug: 'dataset' },
    ];

    const titleTypes: TitleType[] = [
        { id: 1, name: 'Main Title', slug: 'main-title' },
        { id: 2, name: 'Subtitle', slug: 'subtitle' },
        { id: 3, name: 'TranslatedTitle', slug: 'translated-title' },
        { id: 4, name: 'Alternative Title', slug: 'alternative-title' },
    ];

    const licenses: License[] = [
        { id: 1, identifier: 'MIT', name: 'MIT License' },
        { id: 2, identifier: 'Apache-2.0', name: 'Apache License 2.0' },
    ];

    it('renders fields, title options and supports adding/removing titles', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
            />,
        );
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        // accordion sections
        const resourceTrigger = screen.getByRole('button', {
            name: 'Resource Information',
        });
        const licensesTrigger = screen.getByRole('button', {
            name: 'Licenses and Rights',
        });
        expect(resourceTrigger).toHaveAttribute('aria-expanded', 'true');
        expect(licensesTrigger).toHaveAttribute('aria-expanded', 'true');
        await user.click(resourceTrigger);
        expect(resourceTrigger).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByLabelText('DOI')).not.toBeInTheDocument();
        await user.click(resourceTrigger);

        // basic fields
        expect(screen.getByLabelText('DOI')).toBeInTheDocument();
        expect(screen.getByLabelText('Year', { exact: false })).toBeInTheDocument();
        expect(screen.getByLabelText('Version')).toBeInTheDocument();

        // resource type option
        const resourceTypeTrigger = screen.getByLabelText('Resource Type', { exact: false });
        await user.click(resourceTypeTrigger);
        expect(
            await screen.findByRole('option', { name: 'Dataset' }),
        ).toBeInTheDocument();

        // language options
        const languageTrigger = screen.getByLabelText('Language of Data');
        await user.click(languageTrigger);
        for (const option of LANGUAGE_OPTIONS) {
            expect(
                await screen.findByRole('option', { name: option.label }),
            ).toBeInTheDocument();
        }
        await user.keyboard('{Escape}');

        // license fields
        expect(
            screen.getByRole('combobox', { name: 'License' }),
        ).toBeInTheDocument();
        const addLicense = screen.getByRole('button', { name: 'Add license' });
        expect(addLicense).toBeDisabled();
        const licenseTrigger = screen.getByLabelText('License');
        await user.click(licenseTrigger);
        const mitOption = await screen.findByRole('option', {
            name: 'MIT License',
        });
        await user.click(mitOption);
        expect(addLicense).toBeEnabled();
        await user.click(addLicense);
        expect(
            screen.getAllByRole('combobox', { name: 'License' }),
        ).toHaveLength(2);
        expect(addLicense).toBeDisabled();
        const secondLicense = screen.getAllByLabelText('License')[1];
        await user.click(secondLicense);
        const apacheOption = await screen.findByRole('option', {
            name: 'Apache License 2.0',
        });
        await user.click(apacheOption);
        expect(addLicense).toBeEnabled();
        const removeLicense = screen.getByRole('button', { name: 'Remove license' });
        await user.click(removeLicense);
        expect(
            screen.getAllByRole('combobox', { name: 'License' }),
        ).toHaveLength(1);

        // title fields
        expect(screen.getByRole('textbox', { name: 'Title' })).toBeInTheDocument();
        const titleTypeTrigger = screen.getByRole('combobox', { name: 'Title Type' });
        expect(titleTypeTrigger).toHaveTextContent('Main Title');

        // add and remove title rows
        const addButton = screen.getByRole('button', { name: 'Add title' });
        await user.click(addButton);
        const titleInputs = screen.getAllByRole('textbox', { name: 'Title' });
        expect(titleInputs).toHaveLength(2);
        const secondTitleTypeTrigger = screen.getAllByRole('combobox', {
            name: 'Title Type',
        })[1];
        expect(secondTitleTypeTrigger).toHaveTextContent('Subtitle');
        await user.click(secondTitleTypeTrigger);
        expect(
            screen.queryByRole('option', { name: 'Main Title' }),
        ).not.toBeInTheDocument();
        await user.click(secondTitleTypeTrigger);
        const removeButton = screen.getByRole('button', { name: 'Remove title' });
        await user.click(removeButton);
        expect(screen.getAllByRole('textbox', { name: 'Title' })).toHaveLength(1);
    });

    it('prefills DOI when initialDoi is provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                initialDoi="10.1234/abc"
            />,
        );
        expect(screen.getByLabelText('DOI')).toHaveValue('10.1234/abc');
    });

    it('prefills Year when initialYear is provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                initialYear="2024"
            />,
        );
        expect(screen.getByLabelText('Year', { exact: false })).toHaveValue(2024);
    });

    it('prefills Version when initialVersion is provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                initialVersion="1.5"
            />,
        );
        expect(screen.getByLabelText('Version')).toHaveValue('1.5');
    });

    it('disables add license when entries list is empty', () => {
        expect(canAddLicense([], 3)).toBe(false);
    });

    it('prefills Language when initialLanguage is provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                initialLanguage="de"
            />,
        );
        expect(screen.getByLabelText('Language of Data')).toHaveTextContent(
            'German',
        );
    });

    it('prefills Resource Type when initialResourceType is provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                initialResourceType="dataset"
            />,
        );
        expect(screen.getByLabelText('Resource Type', { exact: false })).toHaveTextContent(
            'Dataset',
        );
    });

    it('marks year and resource type as required', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
            />,
        );
        const yearInput = screen.getByLabelText('Year', { exact: false });
        expect(yearInput).toBeRequired();
        const resourceTrigger = screen.getByLabelText('Resource Type', { exact: false });
        expect(resourceTrigger).toHaveAttribute('aria-required', 'true');
        expect(screen.getByText('Year')).toHaveTextContent('*');
        expect(screen.getByText('Resource Type')).toHaveTextContent('*');
    });

    it('prefills titles when initialTitles are provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                initialTitles={[
                    { title: 'Example Title', titleType: 'main-title' },
                    { title: 'Example Subtitle', titleType: 'subtitle' },
                    { title: 'Example TranslatedTitle', titleType: 'translated-title' },
                    { title: 'Example AlternativeTitle', titleType: 'alternative-title' },
                ]}
            />,
        );
        const inputs = screen.getAllByRole('textbox', { name: 'Title' });
        expect(inputs[0]).toHaveValue('Example Title');
        expect(inputs[1]).toHaveValue('Example Subtitle');
        expect(inputs[2]).toHaveValue('Example TranslatedTitle');
        expect(inputs[3]).toHaveValue('Example AlternativeTitle');
        const selects = screen.getAllByRole('combobox', { name: 'Title Type' });
        expect(selects[0]).toHaveTextContent('Main Title');
        expect(selects[1]).toHaveTextContent('Subtitle');
        expect(selects[2]).toHaveTextContent('TranslatedTitle');
        expect(selects[3]).toHaveTextContent('Alternative Title');
    });

    it('prefills a single main title', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                initialTitles={[{ title: 'A mandatory Event', titleType: 'main-title' }]}
            />,
        );
        expect(screen.getByRole('textbox', { name: 'Title' })).toHaveValue(
            'A mandatory Event',
        );
        expect(screen.getByRole('combobox', { name: 'Title Type' })).toHaveTextContent(
            'Main Title',
        );
    });

    it(
        'limits title rows to 100',
        async () => {
            render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                maxTitles={3}
            />,
            );
            const addButton = screen.getByRole('button', { name: 'Add title' });
            fireEvent.click(addButton);
            fireEvent.click(addButton);
            expect(
                screen.getAllByRole('textbox', { name: 'Title' }),
            ).toHaveLength(3);
            expect(addButton).toBeDisabled();
        },
        10000,
    );
});
