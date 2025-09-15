import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, describe, it, expect } from 'vitest';
import DataCiteForm, { canAddLicense, canAddTitle } from '../datacite-form';
import type { ResourceType, TitleType, License, Language } from '@/types';
import { router } from '@inertiajs/react';

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn() },
}));

describe('DataCiteForm', () => {
    beforeAll(() => {
        // Polyfill methods required by Radix UI Select
        Element.prototype.hasPointerCapture = () => false;
        Element.prototype.setPointerCapture = () => {};
        Element.prototype.releasePointerCapture = () => {};
        Element.prototype.scrollIntoView = () => {};
    });

    const resourceTypes: ResourceType[] = [{ id: 1, name: 'Dataset' }];

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

    const languages: Language[] = [
        { id: 1, code: 'en', name: 'English' },
        { id: 2, code: 'de', name: 'German' },
    ];

    it('renders fields, title options and supports adding/removing titles', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
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
        for (const option of languages) {
            expect(
                await screen.findByRole('option', { name: option.name }),
            ).toBeInTheDocument();
        }
        await user.keyboard('{Escape}');

        // license field
        let licenseTriggers = screen.getAllByLabelText(/^License/, {
            selector: 'button',
        });
        const licenseTrigger = licenseTriggers[0];
        expect(licenseTrigger).toHaveAttribute('aria-required', 'true');
        const licenseLabel = screen.getAllByText('License', { selector: 'label' })[0];
        expect(licenseLabel).toHaveTextContent('*');
        expect(
            screen.queryByRole('button', { name: 'Add license' }),
        ).not.toBeInTheDocument();
        await user.click(licenseTrigger);
        const mitOption = await screen.findByRole('option', {
            name: 'MIT License',
        });
        await user.click(mitOption);
        const addLicenseButton = screen.getByRole('button', { name: 'Add license' });
        await user.click(addLicenseButton);
        licenseTriggers = screen.getAllByLabelText(/^License/, {
            selector: 'button',
        });
        expect(licenseTriggers).toHaveLength(2);
        expect(licenseTriggers[1]).not.toHaveAttribute('aria-required', 'true');
        expect(
            screen.getByRole('button', { name: 'Remove license' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Add license' }),
        ).not.toBeInTheDocument();

        // title fields
        const titleInput = screen.getByRole('textbox', { name: /Title/ });
        expect(titleInput).toBeInTheDocument();
        const titleTypeTrigger = screen.getByRole('combobox', { name: 'Title Type' });
        expect(titleTypeTrigger).toHaveTextContent('Main Title');

        // add and remove title rows
        const addButton = screen.getByRole('button', { name: 'Add title' });
        expect(addButton).toBeDisabled();
        await user.type(titleInput, 'First Title');
        expect(addButton).toBeEnabled();
        await user.click(addButton);
        const titleInputs = screen.getAllByRole('textbox', { name: /Title/ });
        expect(titleInputs).toHaveLength(2);
        expect(addButton).toBeDisabled();
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
        expect(
            screen.getAllByRole('textbox', { name: /Title/ }),
        ).toHaveLength(1);
    });

    it('prefills DOI when initialDoi is provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
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
                languages={languages}
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
                languages={languages}
                initialVersion="1.5"
            />,
        );
        expect(screen.getByLabelText('Version')).toHaveValue('1.5');
    });

    it('disables add license when entries list is empty', () => {
        expect(canAddLicense([], 1)).toBe(false);
    });

    it('allows adding license when last entry filled and under limit', () => {
        expect(
            canAddLicense(
                [
                    { id: '1', license: 'MIT' },
                ],
                2,
            ),
        ).toBe(true);
    });

    it('prevents adding license when last entry is empty', () => {
        expect(
            canAddLicense(
                [
                    { id: '1', license: 'MIT' },
                    { id: '2', license: '' },
                ],
                3,
            ),
        ).toBe(false);
    });

    it('determines whether titles can be added', () => {
        expect(canAddTitle([], 3)).toBe(false);
        expect(
            canAddTitle(
                [{ id: '1', title: '', titleType: 'main-title' }],
                3,
            ),
        ).toBe(false);
        expect(
            canAddTitle(
                [{ id: '1', title: 'First', titleType: 'main-title' }],
                3,
            ),
        ).toBe(true);
    });

    it('prefills Language when initialLanguage is provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
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
                languages={languages}
                initialResourceType="1"
            />,
        );
        expect(screen.getByLabelText('Resource Type', { exact: false })).toHaveTextContent(
            'Dataset',
        );
    });

    it('prefills licenses when initialLicenses are provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                initialLicenses={['MIT', 'Apache-2.0']}
            />,
        );
        const triggers = screen.getAllByLabelText(/^License/, { selector: 'button' });
        expect(triggers[0]).toHaveTextContent('MIT License');
        expect(triggers[1]).toHaveTextContent('Apache License 2.0');
    });

    it('marks year, resource type and license as required', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );
        const yearInput = screen.getByLabelText('Year', { exact: false });
        expect(yearInput).toBeRequired();
        const resourceTrigger = screen.getByLabelText('Resource Type', { exact: false });
        expect(resourceTrigger).toHaveAttribute('aria-required', 'true');
        const licenseTriggers = screen.getAllByLabelText('License', { exact: false });
        const licenseTrigger = licenseTriggers.find((el) => el.tagName === 'BUTTON')!;
        expect(licenseTrigger).toHaveAttribute('aria-required', 'true');
        const yearLabel = screen.getAllByText('Year', { selector: 'label' })[0];
        const resourceLabel = screen.getAllByText('Resource Type', { selector: 'label' })[0];
        const licenseLabel2 = screen.getAllByText('License', { selector: 'label' })[0];
        expect(yearLabel).toHaveTextContent('*');
        expect(resourceLabel).toHaveTextContent('*');
        expect(licenseLabel2).toHaveTextContent('*');
    });

    it('marks only main title as required', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        const firstInput = screen.getByRole('textbox', { name: /Title/ });
        expect(firstInput).toBeRequired();
        const addButton = screen.getByRole('button', { name: 'Add title' });
        await user.type(firstInput, 'My Title');
        await user.click(addButton);
        let inputs = screen.getAllByRole('textbox', { name: /Title/ });
        expect(inputs[1]).not.toBeRequired();
        let labels = screen
            .getAllByText(/Title/, { selector: 'label' })
            .filter((l) => ['Title', 'Title*'].includes(l.textContent?.trim() ?? ''));
        expect(labels[0]).toHaveTextContent('*');
        expect(labels[1]).not.toHaveTextContent('*');

        const typeTriggers = screen.getAllByRole('combobox', { name: 'Title Type' });
        await user.click(typeTriggers[0]);
        const subtitleOption = await screen.findByRole('option', { name: 'Subtitle' });
        await user.click(subtitleOption);
        await user.click(typeTriggers[1]);
        const mainOption = await screen.findByRole('option', { name: 'Main Title' });
        await user.click(mainOption);

        inputs = screen.getAllByRole('textbox', { name: /Title/ });
        labels = screen
            .getAllByText(/Title/, { selector: 'label' })
            .filter((l) => ['Title', 'Title*'].includes(l.textContent?.trim() ?? ''));
        expect(inputs[0]).not.toBeRequired();
        expect(inputs[1]).toBeRequired();
        expect(labels[0]).not.toHaveTextContent('*');
        expect(labels[1]).toHaveTextContent('*');
    });

    it('prefills titles when initialTitles are provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                initialTitles={[
                    { title: 'Example Title', titleType: 'main-title' },
                    { title: 'Example Subtitle', titleType: 'subtitle' },
                    { title: 'Example TranslatedTitle', titleType: 'translated-title' },
                    { title: 'Example AlternativeTitle', titleType: 'alternative-title' },
                ]}
            />,
        );
        const inputs = screen.getAllByRole('textbox', { name: /Title/ });
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
                languages={languages}
                initialTitles={[{ title: 'A mandatory Event', titleType: 'main-title' }]}
            />,
        );
        expect(screen.getByRole('textbox', { name: /Title/ })).toHaveValue(
            'A mandatory Event',
        );
        expect(screen.getByRole('combobox', { name: 'Title Type' })).toHaveTextContent(
            'Main Title',
        );
    });

    it('posts form data when Save is clicked', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await user.type(screen.getByRole('textbox', { name: /Title/ }), 'My Title');
        await user.type(screen.getByLabelText('Year', { exact: false }), '2024');
        const resourceTrigger = screen.getByLabelText('Resource Type', { exact: false });
        await user.click(resourceTrigger);
        await user.click(await screen.findByRole('option', { name: 'Dataset' }));
        const licenseTrigger = screen.getAllByLabelText(/^License/, {
            selector: 'button',
        })[0];
        await user.click(licenseTrigger);
        await user.click(await screen.findByRole('option', { name: 'MIT License' }));
        await user.click(screen.getByRole('button', { name: 'Save' }));
        expect(router.post).toHaveBeenCalledWith('/curation', {
            doi: '',
            year: '2024',
            resourceType: '1',
            version: '',
            language: '',
            titles: [{ title: 'My Title', titleType: 'main-title' }],
            licenses: ['MIT'],
        });
    });

    it(
        'limits title rows to max titles',
        async () => {
            render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                maxTitles={3}
            />,
            );
            const user = userEvent.setup();
            const addButton = screen.getByRole('button', { name: 'Add title' });
        const firstInput = screen.getByRole('textbox', { name: /Title/ });
            await user.type(firstInput, 'One');
            await user.click(addButton);
        const secondInput = screen.getAllByRole('textbox', { name: /Title/ })[1];
            await user.type(secondInput, 'Two');
            await user.click(addButton);
            expect(
                screen.getAllByRole('textbox', { name: /Title/ }),
            ).toHaveLength(3);
            expect(addButton).toBeDisabled();
        },
        10000,
    );
});
