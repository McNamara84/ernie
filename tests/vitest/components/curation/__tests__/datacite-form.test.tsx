import '@testing-library/jest-dom/vitest';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, afterAll, afterEach, describe, it, expect, vi } from 'vitest';
import DataCiteForm, { canAddLicense, canAddTitle } from '@/components/curation/datacite-form';
import type { ResourceType, TitleType, License, Language } from '@/types';

vi.mock('@yaireo/tagify', () => {
    type ChangeHandler = (event: CustomEvent) => void;

    class MockTagify {
        public DOM: { scope: HTMLElement; input: HTMLInputElement };
        public value: { value: string }[] = [];
        private inputElement: HTMLInputElement;
        private handlers = new Map<string, Set<ChangeHandler>>();

        constructor(inputElement: HTMLInputElement) {
            this.inputElement = inputElement;
            const scope = document.createElement('div');
            scope.className = 'tagify';
            const input = document.createElement('input');
            input.className = 'tagify__input';
            this.DOM = { scope, input };
            const parent = inputElement.parentElement;
            if (parent) {
                parent.appendChild(scope);
            }
            scope.appendChild(input);
        }

        on(event: string, handler: ChangeHandler) {
            if (!this.handlers.has(event)) {
                this.handlers.set(event, new Set());
            }
            this.handlers.get(event)!.add(handler);
        }

        off(event: string, handler: ChangeHandler) {
            this.handlers.get(event)?.delete(handler);
        }

        destroy() {
            this.handlers.clear();
            this.DOM.scope.remove();
        }

        setDisabled(disabled: boolean) {
            if (disabled) {
                this.DOM.input.setAttribute('disabled', '');
            } else {
                this.DOM.input.removeAttribute('disabled');
            }
        }

        removeAllTags() {
            this.value = [];
            this.renderTags([]);
            this.emitChange('');
        }

        addTags(tags: string[] | string, _skipInvalid?: boolean, silent?: boolean) {
            const incoming = Array.isArray(tags) ? tags : [tags];
            const processed = incoming
                .map((tag) => (typeof tag === 'string' ? tag.trim() : ''))
                .filter((tag) => tag.length > 0);
            this.renderTags(processed);
            if (!silent) {
                this.emitChange(processed.join(', '));
            }
        }

        loadOriginalValues(raw: string) {
            const processed = raw
                .split(',')
                .map((value) => value.trim())
                .filter((value) => value.length > 0);
            this.renderTags(processed);
        }

        private renderTags(values: string[]) {
            this.value = values.map((value) => ({ value }));
            this.inputElement.value = values.join(', ');
            const existingTags = this.DOM.scope.querySelectorAll('.tagify__tag');
            existingTags.forEach((tag) => tag.remove());
            for (const value of values) {
                const tag = document.createElement('span');
                tag.className = 'tagify__tag';
                const tagText = document.createElement('span');
                tagText.className = 'tagify__tag-text';
                tagText.textContent = value;
                tag.appendChild(tagText);
                this.DOM.scope.insertBefore(tag, this.DOM.input);
            }
        }

        private emitChange(raw: string) {
            const handlers = this.handlers.get('change');
            if (!handlers || handlers.size === 0) {
                return;
            }
            const event = new CustomEvent('change', {
                detail: { value: raw, tagify: this },
            }) as CustomEvent;
            handlers.forEach((handler) => handler(event));
        }
    }

    return { default: MockTagify };
});

describe('DataCiteForm', () => {
    const originalFetch = global.fetch;

    const clearXsrfCookie = () => {
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    };

    const ensureAuthorsOpen = async (user: ReturnType<typeof userEvent.setup>) => {
        const authorsTrigger = screen.getByRole('button', { name: 'Authors' });
        if (authorsTrigger.getAttribute('aria-expanded') === 'false') {
            await user.click(authorsTrigger);
        }
    };

    const fillRequiredAuthor = async (
        user: ReturnType<typeof userEvent.setup>,
        lastName = 'Curator',
    ) => {
        await ensureAuthorsOpen(user);
        const lastNameInput = (await screen.findByLabelText(/Last name/)) as HTMLInputElement;
        if (lastNameInput.value) {
            await user.clear(lastNameInput);
        }
        await user.type(lastNameInput, lastName);
    };

    beforeAll(() => {
        // Polyfill methods required by Radix UI Select
        Element.prototype.hasPointerCapture = () => false;
        Element.prototype.setPointerCapture = () => {};
        Element.prototype.releasePointerCapture = () => {};
        Element.prototype.scrollIntoView = () => {};
    });

    beforeEach(() => {
        vi.restoreAllMocks();
        global.fetch = vi.fn();
        document.head.innerHTML = '<meta name="csrf-token" content="test-csrf-token">';
        clearXsrfCookie();
    });

    afterAll(() => {
        global.fetch = originalFetch;
    });

    afterEach(() => {
        document.head.innerHTML = '';
        clearXsrfCookie();
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
        { id: 3, code: 'fr', name: 'French' },
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
        const authorsTrigger = screen.getByRole('button', {
            name: 'Authors',
        });
        const licensesTrigger = screen.getByRole('button', {
            name: 'Licenses and Rights',
        });
        expect(resourceTrigger).toHaveAttribute('aria-expanded', 'true');
        expect(authorsTrigger).toHaveAttribute('aria-expanded', 'true');
        expect(licensesTrigger).toHaveAttribute('aria-expanded', 'true');
        expect(authorsTrigger).toBeInTheDocument();
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
        const languageTrigger = screen.getByLabelText('Language of Data', {
            exact: false,
        });
        expect(languageTrigger).toHaveAttribute('aria-required', 'true');
        const languageLabel = languageTrigger.closest('div')?.querySelector('label');
        if (!languageLabel) {
            throw new Error('Language label not found');
        }
        expect(languageLabel).toHaveTextContent('*');
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
        const titleTypeTrigger = screen.getByRole('combobox', { name: /Title Type/ });
        expect(titleTypeTrigger).toHaveTextContent('Main Title');

        // author fields
        expect(await screen.findByText('Author type')).toBeInTheDocument();
        expect(await screen.findByLabelText('ORCID')).toBeInTheDocument();
        expect(screen.getByText('Affiliations')).toBeInTheDocument();
        // Multiple "Add author" buttons exist (desktop + mobile), use getAllByRole
        expect(screen.getAllByRole('button', { name: 'Add author' }).length).toBeGreaterThan(0);

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
            name: /Title Type/,
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

    it('disables saving until required fields are provided', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });
        const saveButton = screen.getByRole('button', { name: 'Save to database' });

        expect(saveButton).toBeDisabled();
        expect(saveButton).toHaveAttribute('aria-disabled', 'true');

        const titleInput = screen.getByRole('textbox', { name: /Title/ });
        await user.type(titleInput, 'Sample Title');
        await user.type(screen.getByLabelText('Year', { exact: false }), '2024');

        await user.click(screen.getByLabelText('Resource Type', { exact: false }));
        await user.click(await screen.findByRole('option', { name: 'Dataset' }));

        await user.click(screen.getByLabelText('Language of Data', { exact: false }));
        await user.click(await screen.findByRole('option', { name: 'English' }));

        const licenseTrigger = screen.getAllByLabelText(/^License/, {
            selector: 'button',
        })[0];
        await user.click(licenseTrigger);
        await user.click(await screen.findByRole('option', { name: 'MIT License' }));

        await fillRequiredAuthor(user, 'Doe');

        await waitFor(() => {
            expect(saveButton).toBeEnabled();
            expect(saveButton).toHaveAttribute('aria-disabled', 'false');
        });
    });

    it('supports managing person and institution authors with affiliations', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });

        await ensureAuthorsOpen(user);

        const typeTrigger = screen.getByLabelText('Author type');
        await user.click(typeTrigger);
        await user.click(await screen.findByRole('option', { name: 'Institution' }));

        expect(screen.getByLabelText('Institution name')).toBeInTheDocument();
        expect(screen.queryByLabelText('First name')).not.toBeInTheDocument();

        await user.click(typeTrigger);
        await user.click(await screen.findByRole('option', { name: 'Person' }));

        expect(screen.getByLabelText('First name')).toBeInTheDocument();

        const affiliationField = screen.getByTestId('author-0-affiliations-field');
        expect(
            screen.queryByRole('button', { name: /Add affiliation/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Separate multiple affiliations with commas.'),
        ).not.toBeInTheDocument();

        const affiliationInput = screen.getByTestId(
            'author-0-affiliations-input',
        ) as HTMLInputElement & {
            tagify?: {
                addTags: (value: string | string[], clearInput?: boolean, skipChangeEvent?: boolean) => void;
            };
        };

        await waitFor(() => {
            expect(affiliationInput.tagify).toBeTruthy();
        });

        await act(async () => {
            affiliationInput.tagify!.addTags(
                ['University A', 'University B'],
                true,
                false,
            );
        });

        await waitFor(() => {
            expect(affiliationField.querySelectorAll('.tagify__tag')).toHaveLength(2);
        });
        expect(affiliationField).toHaveTextContent('University A');
        expect(affiliationField).toHaveTextContent('University B');

        const addAuthorButtons = screen.getAllByRole('button', { name: /Add author/i });
        await user.click(addAuthorButtons[0]);
        expect(screen.getAllByRole('heading', { name: /Author \d/ })).toHaveLength(2);
        
        // After adding a second author, only the second author should have the Add button visible on desktop
        const updatedAddButtons = screen.getAllByRole('button', { name: /Add author/i });
        expect(updatedAddButtons.length).toBeGreaterThanOrEqual(1);
        
        const removeAuthorButton = screen.getByRole('button', { name: 'Remove author 2' });
        await user.click(removeAuthorButton);
        expect(screen.getAllByRole('heading', { name: /Author \d/ })).toHaveLength(1);
        
        // After removing the second author, the Add button should be visible again
        expect(screen.getAllByRole('button', { name: /Add author/i }).length).toBeGreaterThanOrEqual(1);
    });

    it('applies responsive layout for author inputs', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await ensureAuthorsOpen(user);

        const typeField = screen.getByTestId('author-0-type-field');
        expect(typeField).toHaveClass('md:col-span-2');
        const typeTrigger = screen.getByLabelText('Author type');
        expect(typeTrigger).toHaveClass('w-full');
        // Note: md:w-[8.5rem] is on the SelectField container via triggerClassName, not on the trigger element itself
        const orcidField = screen.getByTestId('author-0-orcid-field');
        expect(orcidField).toHaveClass('md:col-span-3');
        const orcidInput = screen.getByLabelText('ORCID');
        expect(orcidInput).toHaveClass('w-full');
        // ORCID field uses full width within its 3-column container
        const authorGrid = screen.getByTestId('author-0-fields-grid');
        expect(authorGrid).toHaveClass('md:gap-x-3');
        expect(within(authorGrid).getByRole('button', { name: 'Add author' })).toBeInTheDocument();
        expect(
            screen.getByLabelText('Last name', { selector: 'input' }).closest('div')
        ).toHaveClass('md:col-span-3');
        expect(
            screen.getByLabelText('First name', { selector: 'input' }).closest('div')
        ).toHaveClass('md:col-span-2');
        const contactField = screen.getByTestId('author-0-contact-field');
        expect(contactField).toHaveClass('md:col-span-1');
        expect(contactField).toHaveClass('flex');
        expect(contactField).toHaveClass('flex-col');
        expect(contactField).toHaveClass('items-start');
        expect(contactField).not.toHaveClass('pt-6');
        const affiliationGrid = screen.getByTestId('author-0-affiliations-grid');
        const affiliationContainer = screen.getByTestId('author-0-affiliations-field');
        expect(affiliationGrid).toHaveClass('md:grid-cols-12');
        expect(affiliationGrid).toHaveClass('md:gap-x-3');
        expect(affiliationContainer).toHaveClass('md:col-span-12');
        expect(
            screen.queryByText('Use the 16-digit ORCID identifier when available.')
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Provide details for this author and their affiliations.')
        ).not.toBeInTheDocument();
    });

    it('aligns contact fields alongside affiliations when marked as CP', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await ensureAuthorsOpen(user);

        const contactCheckbox = screen.getByLabelText('Contact person');
        await user.click(contactCheckbox);

        const affiliationGrid = screen.getByTestId('author-0-affiliations-grid');
        const affiliationContainer = screen.getByTestId('author-0-affiliations-field');
        const emailContainer = screen
            .getByLabelText('Email address')
            .closest('div');
        const websiteContainer = screen.getByLabelText('Website').closest('div');

        expect(affiliationGrid).toHaveClass('md:grid-cols-12');
        // Affiliations field uses md:col-span-5 when contact fields are visible
        expect(affiliationContainer).toHaveClass('md:col-span-5');
        expect(emailContainer).toHaveClass('md:col-span-3');
        expect(websiteContainer).toHaveClass('md:col-span-3');
    });

    it('places the Authors section after Licenses and Rights', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );

        const licensesTrigger = screen.getByRole('button', {
            name: 'Licenses and Rights',
        });
        const authorsTrigger = screen.getByRole('button', { name: 'Authors' });

        const position = licensesTrigger.compareDocumentPosition(authorsTrigger);
        expect(position & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('shows contact guidance on hover while keeping the label compact', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });

        await ensureAuthorsOpen(user);

        const contactLabel = screen.getByText('CP');
        expect(contactLabel).toBeVisible();

        await user.hover(contactLabel);

        const tooltip = await screen.findByRole('tooltip');
        expect(tooltip).toBeVisible();
        expect(tooltip).toHaveTextContent(
            'Contact Person: Select if this author should be the primary contact.'
        );

        const contactCheckbox = screen.getByLabelText('Contact person');
        expect(contactCheckbox).toBeInTheDocument();
    });

    it('requires an email address when a person author is marked as contact', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });

        const saveButton = screen.getByRole('button', { name: 'Save to database' });

        const titleInput = screen.getByRole('textbox', { name: /Title/ });
        await user.type(titleInput, 'Contact Title');
        await user.type(screen.getByLabelText('Year', { exact: false }), '2025');
        await fillRequiredAuthor(user, 'Meyer');

        await user.click(screen.getByLabelText('Resource Type', { exact: false }));
        await user.click(await screen.findByRole('option', { name: 'Dataset' }));

        await user.click(screen.getByLabelText('Language of Data', { exact: false }));
        await user.click(await screen.findByRole('option', { name: 'German' }));

        const licenseTrigger = screen.getAllByLabelText(/^License/, {
            selector: 'button',
        })[0];
        await user.click(licenseTrigger);
        await user.click(await screen.findByRole('option', { name: 'MIT License' }));

        await ensureAuthorsOpen(user);

        const contactCheckbox = screen.getByLabelText('Contact person');
        await user.click(contactCheckbox);

        const emailInput = await screen.findByLabelText('Email address');
        expect(emailInput).toBeRequired();
        expect(screen.getByLabelText('Website')).toBeInTheDocument();
        expect(screen.queryByLabelText('Website (optional)')).not.toBeInTheDocument();
        expect(saveButton).toBeDisabled();

        await user.type(emailInput, 'contact@example.org');

        await waitFor(() => {
            expect(saveButton).toBeEnabled();
        });
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

    it('prefills Language when initialLanguage code is provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                initialLanguage="de"
            />,
        );
        expect(
            screen.getByLabelText('Language of Data', { exact: false }),
        ).toHaveTextContent(
            'German',
        );
    });

    it('defaults Language to English when initialLanguage is missing', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );
        expect(
            screen.getByLabelText('Language of Data', { exact: false }),
        ).toHaveTextContent(
            'English',
        );
    });

    it('defaults Language to English even when English is not the first option', () => {
        const shuffledLanguages: Language[] = [
            { id: 2, code: 'de', name: 'German' },
            { id: 3, code: 'fr', name: 'French' },
            { id: 1, code: 'en', name: 'English' },
        ];

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={shuffledLanguages}
            />,
        );

        expect(
            screen.getByLabelText('Language of Data', { exact: false }),
        ).toHaveTextContent(
            'English',
        );
    });

    it('prefills Language when initialLanguage name is provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                initialLanguage="German"
            />,
        );
        expect(
            screen.getByLabelText('Language of Data', { exact: false }),
        ).toHaveTextContent(
            'German',
        );
    });

    it('prefills French when initialLanguage indicates French', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                initialLanguage="French"
            />,
        );
        expect(
            screen.getByLabelText('Language of Data', { exact: false }),
        ).toHaveTextContent(
            'French',
        );
    });

    it('falls back to the first language with a code when English is unavailable', () => {
        const limitedLanguages = [
            { id: 4, code: 'de', name: 'German' },
            { id: 5, code: 'fr', name: 'French' },
        ] satisfies Language[];

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={limitedLanguages}
            />,
        );

        expect(
            screen.getByLabelText('Language of Data', { exact: false }),
        ).toHaveTextContent(
            'German',
        );
    });

    it('defaults to English when languages include incomplete entries', () => {
        const incompleteLanguages = [
            { id: 1, code: ' ', name: ' ' },
            { id: 2, code: 'en', name: 'English' },
            { id: 3, code: 'de', name: 'German' },
        ] satisfies Language[];

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={incompleteLanguages}
            />,
        );

        expect(
            screen.getByLabelText('Language of Data', { exact: false }),
        ).toHaveTextContent(
            'English',
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

    it('marks title type as required for all titles', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
            />,
        );
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        const firstTitleTypeTrigger = screen.getByRole('combobox', {
            name: /Title Type/,
        });
        expect(firstTitleTypeTrigger).toHaveAttribute('aria-required', 'true');
        const firstTitleTypeLabel = screen.getAllByText(/Title Type/, {
            selector: 'label',
        })[0];
        expect(firstTitleTypeLabel).toHaveTextContent('*');

        const titleInput = screen.getByRole('textbox', { name: /Title/ });
        await user.type(titleInput, 'Main Title');
        const addButton = screen.getByRole('button', { name: 'Add title' });
        await user.click(addButton);

        const typeTriggers = screen.getAllByRole('combobox', { name: /Title Type/ });
        expect(typeTriggers).toHaveLength(2);
        for (const trigger of typeTriggers) {
            expect(trigger).toHaveAttribute('aria-required', 'true');
        }

        const typeLabels = screen.getAllByText(/Title Type/, { selector: 'label' });
        expect(typeLabels).toHaveLength(2);
        typeLabels.forEach((label) => {
            expect(label).toHaveTextContent('*');
        });
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

        const typeTriggers = screen.getAllByRole('combobox', { name: /Title Type/ });
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
        const selects = screen.getAllByRole('combobox', { name: /Title Type/ });
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
        expect(screen.getByRole('combobox', { name: /Title Type/ })).toHaveTextContent(
            'Main Title',
        );
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

    it('submits data and shows success modal when saving succeeds', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        const responseData = { message: 'Resource stored!' };
        const jsonMock = vi.fn().mockResolvedValue(responseData);
        const response = {
            ok: true,
            status: 201,
            clone: () => ({ json: jsonMock }),
        } as unknown as Response;

        (global.fetch as unknown as vi.Mock).mockResolvedValue(response);

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'First Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredAuthor(user);
        await user.click(saveButton);

        expect(global.fetch).toHaveBeenCalledWith('/curation/resources', expect.objectContaining({
            method: 'POST',
            credentials: 'same-origin',
        }));

        const fetchArgs = (global.fetch as unknown as vi.Mock).mock.calls[0][1];
        expect(fetchArgs).toBeDefined();
        const headers = (fetchArgs as RequestInit).headers as Record<string, string>;
        expect(headers).toMatchObject({
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': 'test-csrf-token',
            'X-Requested-With': 'XMLHttpRequest',
        });
        expect(headers['X-XSRF-TOKEN']).toBeUndefined();
        const body = JSON.parse((fetchArgs as RequestInit).body as string);
        expect(body).toMatchObject({
            year: 2024,
            resourceType: 1,
            titles: [
                {
                    title: 'First Title',
                    titleType: 'main-title',
                },
            ],
            licenses: ['MIT'],
        });

        await screen.findByRole('dialog', { name: /successfully saved resource/i });
        expect(screen.getByText('Resource stored!')).toBeInTheDocument();
    });

    it('includes the resource identifier when updating an existing dataset', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        const responseData = { message: 'Resource updated!' };
        const jsonMock = vi.fn().mockResolvedValue(responseData);
        const response = {
            ok: true,
            status: 200,
            clone: () => ({ json: jsonMock }),
        } as unknown as Response;

        (global.fetch as unknown as vi.Mock).mockResolvedValue(response);

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                initialYear="2025"
                initialResourceType="1"
                initialTitles={[{ title: 'Existing Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
                initialResourceId=" 7 "
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredAuthor(user);
        await user.click(saveButton);

        expect(global.fetch).toHaveBeenCalledTimes(1);

        const fetchArgs = (global.fetch as unknown as vi.Mock).mock.calls[0][1] as RequestInit;
        const body = JSON.parse(fetchArgs.body as string);

        expect(body).toMatchObject({
            resourceId: 7,
            year: 2025,
            resourceType: 1,
            licenses: ['MIT'],
        });

        await screen.findByRole('dialog', { name: /successfully saved resource/i });
        expect(screen.getByText('Resource updated!')).toBeInTheDocument();
    });

    it('falls back to XSRF cookie when meta token is absent', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        document.head.innerHTML = '';
        document.cookie = 'XSRF-TOKEN=cookie-token';

        const jsonMock = vi.fn().mockResolvedValue({});
        const response = {
            ok: true,
            status: 201,
            clone: () => ({ json: jsonMock }),
        } as unknown as Response;

        (global.fetch as unknown as vi.Mock).mockResolvedValue(response);

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'First Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredAuthor(user);
        await user.click(saveButton);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const fetchArgs = (global.fetch as unknown as vi.Mock).mock.calls[0][1] as RequestInit;
        const headers = fetchArgs.headers as Record<string, string>;
        expect(headers['X-CSRF-TOKEN']).toBe('cookie-token');
        expect(headers['X-XSRF-TOKEN']).toBe('cookie-token');
    });

    it('shows an error if no CSRF token source is available', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        document.head.innerHTML = '';

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'Main Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredAuthor(user);
        await user.click(saveButton);

        expect(global.fetch).not.toHaveBeenCalled();
        expect(
            await screen.findByText('Missing security token. Please refresh the page and try again.'),
        ).toBeInTheDocument();
    });

    it('shows validation feedback when saving fails', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        const validationResponse = {
            message: 'Validation failed',
            errors: {
                titles: ['A main title is required.'],
            },
        };

        const jsonMock = vi.fn().mockResolvedValue(validationResponse);
        const errorResponse = {
            ok: false,
            status: 422,
            clone: () => ({ json: jsonMock }),
        } as unknown as Response;

        (global.fetch as unknown as vi.Mock).mockResolvedValue(errorResponse);

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[
                    { title: 'Primary Dataset', titleType: 'main-title' },
                    { title: 'Only Subtitle', titleType: 'subtitle' },
                ]}
                initialLicenses={['MIT']}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredAuthor(user);
        await user.click(saveButton);

        expect(global.fetch).toHaveBeenCalledWith('/curation/resources', expect.any(Object));
        const fetchArgs = (global.fetch as unknown as vi.Mock).mock.calls[0][1];
        expect(fetchArgs).toBeDefined();
        const headers = (fetchArgs as RequestInit).headers as Record<string, string>;
        expect(headers).toMatchObject({
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': 'test-csrf-token',
            'X-Requested-With': 'XMLHttpRequest',
        });
        const body = JSON.parse((fetchArgs as RequestInit).body as string);
        expect(body).toMatchObject({
            licenses: ['MIT'],
            titles: [
                {
                    title: 'Primary Dataset',
                    titleType: 'main-title',
                },
                {
                    title: 'Only Subtitle',
                    titleType: 'subtitle',
                },
            ],
        });

        await screen.findByText('Validation failed');
        const alert = screen.getByText('Validation failed').closest('[role="alert"]');
        expect(alert).not.toBeNull();
        expect(screen.getByText('A main title is required.')).toBeInTheDocument();
        expect(screen.queryByRole('dialog', { name: /successfully saved resource/i })).not.toBeInTheDocument();
    });

    it('shows a network error message when saving throws', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        (global.fetch as unknown as vi.Mock).mockRejectedValue(new Error('offline'));

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'Primary Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredAuthor(user);
        await user.click(saveButton);

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent(
            'A network error prevented saving the resource. Please try again.',
        );
        expect(consoleSpy).toHaveBeenCalledWith(
            'Failed to save resource',
            expect.any(Error),
        );
    });
});
