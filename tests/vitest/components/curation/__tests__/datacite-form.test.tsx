import '@testing-library/jest-dom/vitest';

import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import DataCiteForm, { canAddLicense, canAddTitle } from '@/components/curation/datacite-form';
import { useRorAffiliations } from '@/hooks/use-ror-affiliations';
import type { Language, License, ResourceType, Role, TitleType } from '@/types';

import {
    getTagifyInstance,
    type TagifyEnabledInput,
} from '../../../tagify-helpers';

vi.mock('@yaireo/tagify', () => {
    type ChangeHandler = (event: CustomEvent) => void;

    type NormalisedTag = { value: string; rorId: string | null };
    
    type MockTagifyValue = { value: string; rorId: string | null; data: { rorId: string | null } };

    class MockTagify {
        public DOM: { scope: HTMLElement; input: HTMLInputElement };
        public value: MockTagifyValue[] = [];
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

        setReadonly(readonly: boolean) {
            if (readonly) {
                this.DOM.input.setAttribute('readonly', '');
            } else {
                this.DOM.input.removeAttribute('readonly');
            }
        }

        removeAllTags() {
            this.value = [];
            this.renderTags([]);
            this.emitChange('');
        }

        addTags(tags: Array<string | Record<string, unknown>> | string, _skipInvalid?: boolean, silent?: boolean) {
            const incoming = Array.isArray(tags) ? tags : [tags];
            const processed = incoming
                .map((tag) => this.normaliseTag(tag))
                .filter((tag): tag is NormalisedTag => Boolean(tag));
            this.renderTags(processed);
            if (!silent) {
                this.emitChange(processed.map((tag) => tag.value).join(', '));
            }
        }

        loadOriginalValues(raw: string) {
            const processed = raw
                .split(',')
                .map((value) => value.trim())
                .filter((value) => value.length > 0);
            this.renderTags(processed.map((value) => ({ value, rorId: null })));
        }

        private normaliseTag(tag: unknown): NormalisedTag | null {
            if (typeof tag === 'string') {
                const trimmed = tag.trim();
                return trimmed ? { value: trimmed, rorId: null } : null;
            }

            if (!tag || typeof tag !== 'object') {
                return null;
            }

            const raw = tag as Record<string, unknown>;
            const value = typeof raw.value === 'string' ? raw.value.trim() : '';

            if (!value) {
                return null;
            }

            const rorId = typeof raw.rorId === 'string'
                ? raw.rorId
                : raw.rorId === null
                    ? null
                    : null;

            return { value, rorId };
        }

        private renderTags(values: NormalisedTag[]) {
            this.value = values.map((tag) => ({
                value: tag.value,
                rorId: tag.rorId,
                data: { rorId: tag.rorId },
            }));
            this.inputElement.value = values.map((tag) => tag.value).join(', ');
            const existingTags = this.DOM.scope.querySelectorAll('.tagify__tag');
            existingTags.forEach((tag) => tag.remove());
            for (const item of values) {
                const tagElement = document.createElement('span');
                tagElement.className = 'tagify__tag';
                const tagText = document.createElement('span');
                tagText.className = 'tagify__tag-text';
                tagText.textContent = item.value;
                tagElement.appendChild(tagText);
                this.DOM.scope.insertBefore(tagElement, this.DOM.input);
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

vi.mock('@/hooks/use-ror-affiliations', () => ({
    useRorAffiliations: vi.fn().mockReturnValue({
        suggestions: [],
        isLoading: false,
        error: null,
    }),
}));

describe('DataCiteForm', () => {
    const originalFetch = global.fetch;

    // Constants
    // The label for the required date type (Date Created must be filled for form submission)
    const REQUIRED_DATE_TYPE_LABEL = 'Created';

    // Helper Functions
    const clearXsrfCookie = () => {
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    };

    /**
     * Filters and returns all empty date input elements from the form.
     * This is used to locate date inputs when filling out test data.
     */
    const getEmptyDateInputs = (): HTMLInputElement[] => {
        return screen.getAllByDisplayValue('').filter(input => 
            input.getAttribute('type') === 'date'
        ) as HTMLInputElement[];
    };

    const ensureAuthorsOpen = async (user: ReturnType<typeof userEvent.setup>) => {
        const authorsTrigger = screen.getByRole('button', { name: 'Authors' });
        if (authorsTrigger.getAttribute('aria-expanded') === 'false') {
            await user.click(authorsTrigger);
        }
    };

    const ensureContributorsOpen = async (user: ReturnType<typeof userEvent.setup>) => {
        const contributorsTrigger = screen.getByRole('button', { name: 'Contributors' });
        if (contributorsTrigger.getAttribute('aria-expanded') === 'false') {
            await user.click(contributorsTrigger);
        }
    };

    const getAuthorScope = () => within(screen.getByTestId('author-entries-group'));

    const fillRequiredContributor = async (
        user: ReturnType<typeof userEvent.setup>,
        {
            lastName = 'Helper',
            role = contributorPersonRoles[0]?.name ?? 'Researcher',
        }: { lastName?: string; role?: string } = {},
    ) => {
        await ensureContributorsOpen(user);
        const roleInput = screen.getByTestId('contributor-0-roles-input') as TagifyEnabledInput;

        await waitFor(() => {
            expect(roleInput.tagify).toBeTruthy();
        });

        const roleTagify = getTagifyInstance(roleInput);

        await act(async () => {
            roleTagify.addTags([role], true, false);
        });

        const contributorSection = screen.getByRole('region', { name: 'Contributor 1' });
        const lastNameInput = within(contributorSection).getByRole('textbox', {
            name: /^Last name/,
        }) as HTMLInputElement;
        if (lastNameInput.value) {
            await user.clear(lastNameInput);
        }
        await user.type(lastNameInput, lastName);
    };

    const fillRequiredAuthor = async (
        user: ReturnType<typeof userEvent.setup>,
        lastName = 'Curator',
    ) => {
        await ensureAuthorsOpen(user);
        const authorGroup = await screen.findByTestId('author-entries-group');
        const lastNameInput = within(authorGroup).getByRole('textbox', { name: /Last name/ }) as HTMLInputElement;
        if (lastNameInput.value) {
            await user.clear(lastNameInput);
        }
        await user.type(lastNameInput, lastName);
    };

    const ensureDescriptionsOpen = async (user: ReturnType<typeof userEvent.setup>) => {
        const descriptionsTrigger = screen.getByRole('button', { name: 'Descriptions' });
        if (descriptionsTrigger.getAttribute('aria-expanded') === 'false') {
            await user.click(descriptionsTrigger);
        }
    };

    const fillRequiredAbstract = async (
        user: ReturnType<typeof userEvent.setup>,
        abstract = 'This is a test abstract for the dataset.',
    ) => {
        await ensureDescriptionsOpen(user);
        const abstractTextarea = screen.getByRole('textbox', { name: /Abstract/i });
        await user.click(abstractTextarea);
        await user.keyboard(abstract);
    };

    const ensureDatesOpen = async (user: ReturnType<typeof userEvent.setup>) => {
        const datesTrigger = screen.getByRole('button', { name: 'Dates' });
        if (datesTrigger.getAttribute('aria-expanded') === 'false') {
            await user.click(datesTrigger);
        }
    };

    const fillRequiredDateCreated = async (
        user: ReturnType<typeof userEvent.setup>,
        date = '2024-01-15',
    ) => {
        await ensureDatesOpen(user);
        const dateInputs = getEmptyDateInputs();
        if (dateInputs.length === 0) {
            throw new Error('No date inputs found in the form');
        }
        const dateCreatedInput = dateInputs[0];
        
        // Use type() for date inputs with the correct format
        await user.clear(dateCreatedInput);
        await user.type(dateCreatedInput, date);
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
        (useRorAffiliations as unknown as vi.Mock).mockReturnValue({
            suggestions: [],
            isLoading: false,
            error: null,
        });
        global.fetch = vi.fn();
        
        // Mock the controlled vocabulary fetches that DataCiteForm makes on mount
        // (GCMD Science Keywords, Platforms, Instruments, and ROR Funders)
        const emptyVocabularyResponse = {
            ok: true,
            status: 200,
            json: () => Promise.resolve([]),
        } as Response;
        
        (global.fetch as unknown as vi.Mock)
            .mockResolvedValueOnce(emptyVocabularyResponse) // gcmd-science-keywords
            .mockResolvedValueOnce(emptyVocabularyResponse) // gcmd-platforms
            .mockResolvedValueOnce(emptyVocabularyResponse) // gcmd-instruments
            .mockResolvedValueOnce(emptyVocabularyResponse); // ror-funders
        
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
    const contributorPersonRoles: Role[] = [
        { id: 1, name: 'Researcher', slug: 'researcher' },
        { id: 2, name: 'Editor', slug: 'editor' },
    ];
    const contributorInstitutionRoles: Role[] = [
        { id: 3, name: 'Hosting Institution', slug: 'hosting-institution' },
        { id: 4, name: 'Rights Holder', slug: 'rights-holder' },
    ];
    const authorRoles: Role[] = [{ id: 5, name: 'Author', slug: 'author' }];

    it(
        'renders fields, title options and supports adding/removing titles',
        { timeout: 10000 },
        async () => {
            render(
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    licenses={licenses}
                    languages={languages}
                    contributorPersonRoles={contributorPersonRoles}
                    contributorInstitutionRoles={contributorInstitutionRoles}
                    authorRoles={authorRoles}
                />,
            );
        expect(useRorAffiliations).toHaveBeenCalled();
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
        const contributorsTrigger = screen.getByRole('button', {
            name: 'Contributors',
        });
        expect(resourceTrigger).toHaveAttribute('aria-expanded', 'true');
        expect(authorsTrigger).toHaveAttribute('aria-expanded', 'true');
        expect(licensesTrigger).toHaveAttribute('aria-expanded', 'true');
        expect(contributorsTrigger).toHaveAttribute('aria-expanded', 'true');
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
        const languageLabel = screen.getByText(/Language of Data/, { selector: 'label' });
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

        await ensureAuthorsOpen(user);
        await ensureContributorsOpen(user);

        // author fields
        expect(await screen.findByText('Author type')).toBeInTheDocument();
        expect(await screen.findAllByLabelText('ORCID')).toHaveLength(2);
        expect(screen.getAllByText('Affiliations', { selector: 'label' })).toHaveLength(2);
        // Multiple "Add author" buttons exist (desktop + mobile), use getAllByRole
        expect(screen.getAllByRole('button', { name: 'Add author' }).length).toBeGreaterThan(0);

        expect(await screen.findByText('Contributor type')).toBeInTheDocument();
        expect(screen.getByLabelText(/^Roles/)).toBeInTheDocument();
        expect(
            screen.getAllByRole('button', { name: /Add contributor/i }).length,
        ).toBeGreaterThan(0);

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

    it('announces available author roles for accessible guidance', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
            />,
        );

        const message = await screen.findByTestId('author-roles-availability');
        expect(message).toHaveTextContent('The available author role is Author.');
        expect(message).toHaveAttribute('id', 'author-roles-description');

        const group = screen.getByTestId('author-entries-group');
        expect(group).toHaveAttribute('role', 'group');
        expect(group).toHaveAttribute('aria-describedby', 'author-roles-description');
    });

    it('lets curators add and remove contributors without losing existing entries', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await ensureContributorsOpen(user);

        expect(screen.getByRole('heading', { name: 'Contributor 1' })).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Contributor 2' })).not.toBeInTheDocument();

        const addContributorButtons = screen.getAllByRole('button', { name: 'Add contributor' });
        await user.click(addContributorButtons[0]);

        expect(screen.getByRole('heading', { name: 'Contributor 2' })).toBeInTheDocument();

        const removeContributorButton = screen.getByRole('button', {
            name: 'Remove contributor 2',
        });
        await user.click(removeContributorButton);

        await waitFor(() => {
            expect(screen.queryByRole('heading', { name: 'Contributor 2' })).not.toBeInTheDocument();
        });
    });

    it(
        'disables saving until required fields are provided',
        { timeout: 10000 },
        async () => {
            render(
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    licenses={licenses}
                    languages={languages}
                    contributorPersonRoles={contributorPersonRoles}
                    contributorInstitutionRoles={contributorInstitutionRoles}
                    authorRoles={authorRoles}
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
        await fillRequiredContributor(user);
        await fillRequiredAbstract(user);
        await fillRequiredDateCreated(user);

        await waitFor(() => {
            expect(saveButton).toBeEnabled();
            expect(saveButton).toHaveAttribute('aria-disabled', 'false');
        });
    },
    );

    it('supports managing person and institution authors with affiliations', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });

        await ensureAuthorsOpen(user);

        const authorScope = getAuthorScope();
        const typeTrigger = authorScope.getByRole('combobox', { name: /Author type/i });
        await user.click(typeTrigger);
        await user.click(await screen.findByRole('option', { name: 'Institution' }));

        expect(authorScope.getByRole('textbox', { name: /Institution name/i })).toBeInTheDocument();
        expect(authorScope.queryByRole('textbox', { name: /First name/i })).not.toBeInTheDocument();

        await user.click(typeTrigger);
        await user.click(await screen.findByRole('option', { name: 'Person' }));

        expect(authorScope.getByRole('textbox', { name: /First name/i })).toBeInTheDocument();

        const affiliationField = authorScope.getByTestId('author-0-affiliations-field');
        expect(
            screen.queryByRole('button', { name: /Add affiliation/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Separate multiple affiliations with commas.'),
        ).not.toBeInTheDocument();

        const affiliationInput = screen.getByTestId('author-0-affiliations-input') as TagifyEnabledInput;

        await waitFor(() => {
            expect(affiliationInput.tagify).toBeTruthy();
        });

        const affiliationTagify = getTagifyInstance(affiliationInput);

        await act(async () => {
            affiliationTagify.addTags(
                ['University A', 'University B'],
                true,
                false,
            );
        });

        await waitFor(() => {
            expect(affiliationField.querySelectorAll('.tagify__tag')).toHaveLength(2);
        });
        const affiliationValues = affiliationTagify.value.map((tag) => tag.value);
        expect(affiliationValues).toContain('University A');
        expect(affiliationValues).toContain('University B');

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

    it('renders badges for affiliations that include recognised ROR IDs', async () => {
        const useRorAffiliationsMock = useRorAffiliations as unknown as vi.Mock;
        useRorAffiliationsMock.mockReturnValue({
            suggestions: [
                {
                    value: 'Example University',
                    rorId: 'https://ror.org/05fjyn938',
                    searchTerms: [],
                },
            ],
            isLoading: false,
            error: null,
        });

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await ensureAuthorsOpen(user);

        const affiliationInput = screen.getByTestId('author-0-affiliations-input') as TagifyEnabledInput;

        await waitFor(() => {
            expect(affiliationInput.tagify).toBeTruthy();
        });

        const affiliationTagify = getTagifyInstance(affiliationInput);

        await act(async () => {
            affiliationTagify.addTags(
                [
                    {
                        value: 'Example University',
                        rorId: 'https://ror.org/05fjyn938',
                    },
                ],
                true,
                false,
            );
        });

        const badgesContainer = await screen.findByTestId('author-0-affiliations-ror-ids');
        expect(badgesContainer).toHaveTextContent('Linked ROR IDs');
        expect(badgesContainer).toHaveTextContent('https://ror.org/05fjyn938');
        const badgeElements = badgesContainer.querySelectorAll('[data-slot="badge"]');
        expect(badgeElements).toHaveLength(1);
    });

    it('does not display ROR badges when affiliations have no identifier', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await ensureAuthorsOpen(user);

        const affiliationInput = screen.getByTestId('author-0-affiliations-input') as TagifyEnabledInput;

        await waitFor(() => {
            expect(affiliationInput.tagify).toBeTruthy();
        });

        const affiliationTagify = getTagifyInstance(affiliationInput);

        await act(async () => {
            affiliationTagify.addTags(['Independent Organisation'], true, false);
        });

        await waitFor(() => {
            expect(
                screen.queryByTestId('author-0-affiliations-ror-ids'),
            ).not.toBeInTheDocument();
        });
    });

    it('shows ROR badges for initial authors passed to the form', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialAuthors={[
                    {
                        type: 'person',
                        lastName: 'Existing Author',
                        affiliations: [
                            {
                                value: 'Historic Institute',
                                rorId: 'https://ror.org/02mhbdp94',
                            },
                        ],
                    },
                ]}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await ensureAuthorsOpen(user);

        const badgesContainer = await screen.findByTestId('author-0-affiliations-ror-ids');
        expect(badgesContainer).toHaveTextContent('https://ror.org/02mhbdp94');
    });

    it(
        'supports adding, removing and managing multiple authors independently',
        { timeout: 15000 },
        async () => {
            render(
                <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                    contributorPersonRoles={contributorPersonRoles}
                    contributorInstitutionRoles={contributorInstitutionRoles}
                    authorRoles={authorRoles}
                />,
            );

            const user = userEvent.setup({ pointerEventsCheck: 0 });

            await ensureAuthorsOpen(user);

            // Add three authors
            const addButtons = () => screen.getAllByRole('button', { name: /Add author/i });
            
            const authorGroup = screen.getByTestId('author-entries-group');

            const firstLastNameInput = within(authorGroup).getByRole('textbox', { name: /Last name/i });
            await user.clear(firstLastNameInput);
            await user.type(firstLastNameInput, 'First Author');
            
            await user.click(addButtons()[0]);
            
            await waitFor(() => {
                expect(screen.getByRole('heading', { name: 'Author 2' })).toBeInTheDocument();
            });
            
            const secondLastNameInput = within(authorGroup).getAllByRole('textbox', { name: /Last name/i })[1];
            await user.clear(secondLastNameInput);
            await user.type(secondLastNameInput, 'Second Author');
            
            await user.click(addButtons()[0]);
            
            await waitFor(() => {
                expect(screen.getByRole('heading', { name: 'Author 3' })).toBeInTheDocument();
            });
            
            const thirdLastNameInput = within(authorGroup).getAllByRole('textbox', { name: /Last name/i })[2];
            await user.clear(thirdLastNameInput);
            await user.type(thirdLastNameInput, 'Third Author');

        // Verify all three authors are present
        expect(screen.getByRole('heading', { name: 'Author 1' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Author 2' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Author 3' })).toBeInTheDocument();

        // Change second author to institution
        const secondAuthorType = screen.getAllByRole('combobox', { name: /Author type/i })[1];
        await user.click(secondAuthorType);
        await user.click(await screen.findByRole('option', { name: 'Institution' }));

        const institutionInput = screen.getByRole('textbox', { name: /Institution name/i });
        await user.type(institutionInput, 'Test University');

        // Verify first and third are still persons
        expect(within(authorGroup).getAllByRole('textbox', { name: /Last name/i })).toHaveLength(2);
        expect(within(authorGroup).getAllByRole('textbox', { name: /Last name/i })[0]).toHaveValue(
            'First Author',
        );
        expect(within(authorGroup).getAllByRole('textbox', { name: /Last name/i })[1]).toHaveValue(
            'Third Author',
        );

        // Set first author as contact person
        const firstContactCheckbox = screen.getAllByRole('checkbox', { name: /Contact person/i })[0];
        await user.click(firstContactCheckbox);

        await waitFor(() => {
            expect(screen.getByRole('textbox', { name: /Email address/i })).toBeInTheDocument();
        });

        await user.type(screen.getByRole('textbox', { name: /Email address/i }), 'first@example.com');
        await user.type(screen.getByRole('textbox', { name: /Website/i }), 'https://first.example.com');

        // Add affiliations to third author
        const thirdAffiliationInput = screen.getAllByTestId(/author-\d+-affiliations-input/)[2] as TagifyEnabledInput;

        await waitFor(() => {
            expect(thirdAffiliationInput.tagify).toBeTruthy();
        });

        const thirdAffiliationTagify = getTagifyInstance(thirdAffiliationInput);

        await act(async () => {
            thirdAffiliationTagify.addTags(['Institution X', 'Institution Y'], true, false);
        });

        const thirdAffiliationField = screen.getAllByTestId(/author-\d+-affiliations-field/)[2];
        await waitFor(() => {
            expect(thirdAffiliationField.querySelectorAll('.tagify__tag')).toHaveLength(2);
        });

        // Remove second author (institution)
        const removeButtons = screen.getAllByRole('button', { name: /Remove author \d/ });
        await user.click(removeButtons[1]);

        // Should now have 2 authors
        await waitFor(() => {
            expect(screen.getAllByRole('heading', { name: /Author \d/ })).toHaveLength(2);
        });

        expect(screen.getByRole('heading', { name: 'Author 1' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Author 2' })).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Author 3' })).not.toBeInTheDocument();

        // Former third author should now be second author
        expect(within(authorGroup).getAllByRole('textbox', { name: /Last name/i })[1]).toHaveValue(
            'Third Author',
        );

        // First author contact data should be preserved
        expect(screen.getByRole('textbox', { name: /Email address/i })).toHaveValue('first@example.com');
        expect(screen.getByRole('textbox', { name: /Website/i })).toHaveValue('https://first.example.com');

        // Former third author affiliations should be preserved
        const secondAffiliationInput = screen.getAllByTestId(/author-\d+-affiliations-input/)[1] as TagifyEnabledInput;
        const updatedAffiliationValues = getTagifyInstance(secondAffiliationInput).value
            .map((tag) => tag.value)
            .filter((value): value is string => Boolean(value));
        expect(updatedAffiliationValues).toContain('Institution X');
        expect(updatedAffiliationValues).toContain('Institution Y');

        // Remove first author
        await user.click(screen.getAllByRole('button', { name: /Remove author \d/ })[0]);

        // Should now have 1 author
        await waitFor(() => {
            expect(screen.getAllByRole('heading', { name: /Author \d/ })).toHaveLength(1);
        });

        expect(screen.getByRole('heading', { name: 'Author 1' })).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Author 2' })).not.toBeInTheDocument();

        // Remaining author should be the former third author
        expect(within(authorGroup).getByRole('textbox', { name: /Last name/i })).toHaveValue(
            'Third Author',
        );
        
        // Affiliations should be preserved
        const finalAffiliationInput = screen.getByTestId('author-0-affiliations-input') as TagifyEnabledInput;
        const finalAffiliationValues = getTagifyInstance(finalAffiliationInput).value
            .map((tag) => tag.value)
            .filter((value): value is string => Boolean(value));
        expect(finalAffiliationValues).toContain('Institution X');
        expect(finalAffiliationValues).toContain('Institution Y');
    },
    );

    it('applies responsive layout for author inputs', async () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await ensureAuthorsOpen(user);

        const authorsScope = getAuthorScope();
        const typeField = screen.getByTestId('author-0-type-field');
        expect(typeField).toHaveClass('md:col-span-2');
        const typeTrigger = authorsScope.getByRole('combobox', { name: /Author type/i });
        expect(typeTrigger).toHaveClass('w-full');
        // Note: md:w-[8.5rem] is on the SelectField container via triggerClassName, not on the trigger element itself
        const orcidField = screen.getByTestId('author-0-orcid-field');
        expect(orcidField).toHaveClass('md:col-span-3');
        const orcidInput = authorsScope.getByRole('textbox', { name: /ORCID/i });
        expect(orcidInput).toHaveClass('w-full');
        // ORCID field uses full width within its 3-column container
        const authorGrid = screen.getByTestId('author-0-fields-grid');
        expect(authorGrid).toHaveClass('md:gap-x-3');
        // Add author button is outside the fields grid in a separate container
        expect(screen.getAllByRole('button', { name: 'Add author' }).length).toBeGreaterThan(0);
        expect(
            getAuthorScope()
                .getByRole('textbox', { name: /Last name/i })
                .closest('div'),
        ).toHaveClass('md:col-span-3');
        expect(
            getAuthorScope()
                .getByRole('textbox', { name: /First name/i })
                .closest('div'),
        ).toHaveClass('md:col-span-3');
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
        // Affiliations field spans 11 columns when contact person is not selected (no email/website fields)
        expect(affiliationContainer).toHaveClass('md:col-span-11');
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
            />,
        );

        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await ensureAuthorsOpen(user);

        const contactCheckbox = screen.getByRole('checkbox', { name: /Contact person/i });
        await user.click(contactCheckbox);

        const affiliationGrid = screen.getByTestId('author-0-affiliations-grid');
        const affiliationContainer = screen.getByTestId('author-0-affiliations-field');
        const emailContainer = screen
            .getByRole('textbox', { name: /Email address/i })
            .closest('div');
        const websiteContainer = screen.getByRole('textbox', { name: /Website/i }).closest('div');

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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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

        const contactCheckbox = screen.getByRole('checkbox', { name: /Contact person/i });
        expect(contactCheckbox).toBeInTheDocument();
    });

    it(
        'requires an email address when a person author is marked as contact',
        { timeout: 15000 },
        async () => {
            render(
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    licenses={licenses}
                    languages={languages}
                    contributorPersonRoles={contributorPersonRoles}
                    contributorInstitutionRoles={contributorInstitutionRoles}
                    authorRoles={authorRoles}
                />,
            );

            const user = userEvent.setup({ pointerEventsCheck: 0 });

            const saveButton = screen.getByRole('button', { name: 'Save to database' });

            const titleInput = screen.getByRole('textbox', { name: /Title/ });
            await user.type(titleInput, 'Contact Title');
            await user.type(screen.getByLabelText('Year', { exact: false }), '2025');
            await fillRequiredAuthor(user, 'Meyer');
            await fillRequiredContributor(user);

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

            const contactCheckbox = screen.getByRole('checkbox', { name: /Contact person/i });
            await user.click(contactCheckbox);

            const emailInput = await screen.findByRole('textbox', { name: /Email address/i });
            expect(emailInput).toBeRequired();
            expect(screen.getByRole('textbox', { name: /Website/i })).toBeInTheDocument();
            expect(screen.queryByRole('textbox', { name: /Website \(optional\)/i })).not.toBeInTheDocument();
            expect(saveButton).toBeDisabled();

            await user.type(emailInput, 'contact@example.org');
            await fillRequiredAbstract(user);
            await fillRequiredDateCreated(user);

            await waitFor(
                () => {
                    expect(saveButton).toBeEnabled();
                },
                { timeout: 10000 },
            );
        },
    );

    it('prefills DOI when initialDoi is provided', () => {
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialVersion="1.5"
            />,
        );
        expect(screen.getByLabelText('Version')).toHaveValue('1.5');
    });

    it('prefills authors when initialAuthors are provided', async () => {
        const user = userEvent.setup();

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialAuthors={[
                    {
                        type: 'person',
                        firstName: 'Sofia',
                        lastName: 'Hernandez',
                        orcid: 'https://orcid.org/0000-0002-2771-9344',
                        affiliations: [
                            {
                                value: 'GFZ Data Services',
                                rorId: 'https://ror.org/04wxnsj81',
                            },
                        ],
                    },
                    {
                        type: 'institution',
                        institutionName: 'Example Organization',
                        affiliations: [
                            {
                                value: 'Independent Collaboration',
                                rorId: null,
                            },
                        ],
                    },
                ]}
            />,
        );

        await ensureAuthorsOpen(user);

        const authorScope = getAuthorScope();

        const firstNameInputs = authorScope.getAllByRole('textbox', {
            name: /First name/i,
        }) as HTMLInputElement[];
        expect(firstNameInputs[0]).toHaveValue('Sofia');

        const lastNameInputs = authorScope.getAllByRole('textbox', {
            name: /Last name/i,
        }) as HTMLInputElement[];
        expect(lastNameInputs[0]).toHaveValue('Hernandez');

        expect(authorScope.getByLabelText('ORCID')).toHaveValue(
            '0000-0002-2771-9344',
        );

        expect(
            (authorScope.getByRole('textbox', { name: /Institution name/i })) as HTMLInputElement,
        ).toHaveValue('Example Organization');

        expect(screen.getAllByText('GFZ Data Services').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Independent Collaboration').length).toBeGreaterThan(0);
    });

    it('prefills contributors when initialContributors are provided', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialContributors={[
                    {
                        type: 'person',
                        roles: ['ContactPerson'],
                        orcid: 'https://orcid.org/0000-0001-5727-2427',
                        firstName: 'Ada',
                        lastName: 'Lovelace',
                        affiliations: [
                            { value: 'Example Affiliation', rorId: 'https://ror.org/04wxnsj81' },
                        ],
                    },
                    {
                        type: 'institution',
                        roles: ['Distributor'],
                        institutionName: 'Example Org',
                        affiliations: [
                            { value: 'Example Org', rorId: 'https://ror.org/03yrm5c26' },
                        ],
                    },
                ]}
            />,
        );

        await ensureContributorsOpen(user);

        const contributorRoleInput = screen.getByTestId(
            'contributor-0-roles-input',
        ) as HTMLInputElement;
        expect(contributorRoleInput.value).toBe('Contact Person');

        const contributorSection = screen
            .getByRole('heading', { name: 'Contributor 1' })
            .closest('section') as HTMLElement;

        const contributorOrcidField = within(
            screen.getByTestId('contributor-0-orcid-field'),
        ).getByRole('textbox') as HTMLInputElement;
        expect(contributorOrcidField.value).toBe('0000-0001-5727-2427');

        const contributorFirstNameField = within(contributorSection).getByLabelText('First name', {
            selector: 'input',
        }) as HTMLInputElement;
        expect(contributorFirstNameField.value).toBe('Ada');

        const contributorLastNameField = within(contributorSection).getByRole('textbox', {
            name: /^Last name/,
        }) as HTMLInputElement;
        expect(contributorLastNameField.value).toBe('Lovelace');

        const contributorAffiliationsInput = screen.getByTestId(
            'contributor-0-affiliations-input',
        ) as HTMLInputElement;
        expect(contributorAffiliationsInput.value).toBe('Example Affiliation');
        expect(screen.getByTestId('contributor-0-affiliations-ror-ids')).toBeInTheDocument();

        const institutionSection = screen
            .getByRole('heading', { name: 'Contributor 2' })
            .closest('section') as HTMLElement;

        const institutionRolesInput = screen.getByTestId('contributor-1-roles-input') as HTMLInputElement;
        expect(institutionRolesInput.value).toBe('Distributor');

        const institutionNameInput = within(institutionSection).getByRole('textbox', {
            name: /Institution name/i,
        }) as HTMLInputElement;
        expect(institutionNameInput.value).toBe('Example Org');

        const institutionAffiliationsInput = screen.getByTestId(
            'contributor-1-affiliations-input',
        ) as HTMLInputElement;
        expect(institutionAffiliationsInput.value).toBe('Example Org');
        expect(screen.getByTestId('contributor-1-affiliations-ror-ids')).toBeInTheDocument();
    });

    it('prefills contributor role inputs with multiple roles', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialContributors={[
                    {
                        type: 'person',
                        roles: ['Contact Person', 'Data Curator'],
                        firstName: 'Ada',
                        lastName: 'Lovelace',
                    },
                ]}
            />,
        );

        await ensureContributorsOpen(user);

        const contributorRoleInput = screen.getByTestId(
            'contributor-0-roles-input',
        ) as HTMLInputElement;

        expect(contributorRoleInput.value).toBe('Contact Person, Data Curator');
    });

    it('treats research group contributors as institutions when roles require it', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialContributors={[
                    {
                        type: 'person',
                        roles: ['ResearchGroup'],
                        institutionName: 'ExampleContributorRG',
                        affiliations: [
                            {
                                value: 'ExampleOrganization',
                                rorId: 'https://ror.org/03yrm5c26',
                            },
                        ],
                    },
                ]}
            />,
        );

        await ensureContributorsOpen(user);

        const contributorSection = screen
            .getByRole('heading', { name: 'Contributor 1' })
            .closest('section') as HTMLElement;

        const typeField = within(screen.getByTestId('contributor-0-type-field')).getByRole(
            'combobox',
        );
        expect(typeField).toHaveTextContent('Institution');

        const rolesInput = screen.getByTestId('contributor-0-roles-input') as HTMLInputElement;
        expect(rolesInput.value).toBe('Research Group');

        const institutionNameInput = within(contributorSection).getByDisplayValue(
            'ExampleContributorRG',
        ) as HTMLInputElement;
        expect(institutionNameInput).toBeInTheDocument();

        expect(
            within(contributorSection).queryByLabelText('First name', { selector: 'input' }),
        ).not.toBeInTheDocument();
        expect(
            within(contributorSection).queryByLabelText('Last name', { selector: 'input' }),
        ).not.toBeInTheDocument();

        const affiliationsInput = screen.getByTestId(
            'contributor-0-affiliations-input',
        ) as HTMLInputElement;
        expect(affiliationsInput.value).toBe('ExampleOrganization');

        const rorList = screen.getByTestId('contributor-0-affiliations-ror-ids');
        expect(within(rorList).getByText('https://ror.org/03yrm5c26')).toBeInTheDocument();
    });

    it('loads multiple affiliations for authors from old datasets correctly', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialAuthors={[
                    {
                        type: 'person',
                        firstName: 'Stefano',
                        lastName: 'Parolai',
                        orcid: 'https://orcid.org/0000-0002-9084-7488',
                        affiliations: [
                            {
                                value: 'GFZ German Research Centre for Geosciences, Potsdam, Germany',
                                rorId: 'https://ror.org/04z8jg394',
                            },
                            {
                                value: 'Seismological Research Centre of the OGS',
                                rorId: null,
                            },
                        ],
                    },
                ]}
            />,
        );

        await ensureAuthorsOpen(user);

        // Verify that multiple tags were created (not just one tag with all affiliations)
        const affiliationField = screen.getByTestId('author-0-affiliations-field');
        const tags = within(affiliationField).getAllByRole('generic', { hidden: true })
            .filter(el => el.classList.contains('tagify__tag'));
        
        // Should have exactly 2 separate tags
        expect(tags.length).toBe(2);
        
        // Verify the tag contents
        expect(tags[0]).toHaveTextContent('GFZ German Research Centre for Geosciences, Potsdam, Germany');
        expect(tags[1]).toHaveTextContent('Seismological Research Centre of the OGS');

        // Verify ROR IDs are displayed
        const rorIds = screen.getByTestId('author-0-affiliations-ror-ids');
        expect(rorIds).toBeInTheDocument();
        expect(within(rorIds).getByText('https://ror.org/04z8jg394')).toBeInTheDocument();
    });

    it('loads multiple affiliations for contributors from old datasets correctly', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialContributors={[
                    {
                        type: 'person',
                        firstName: 'Jean François',
                        lastName: 'Iffly',
                        roles: ['Data Collector', 'Data Curator'],
                        affiliations: [
                            {
                                value: 'Luxembourg Institute of Science and Technology',
                                rorId: null,
                            },
                            {
                                value: 'Catchment and Eco-Hydrology Group',
                                rorId: null,
                            },
                        ],
                    },
                ]}
            />,
        );

        await ensureContributorsOpen(user);

        // Verify that multiple tags were created (not just one tag with all affiliations)
        const affiliationField = screen.getByTestId('contributor-0-affiliations-field');
        const tags = within(affiliationField).getAllByRole('generic', { hidden: true })
            .filter(el => el.classList.contains('tagify__tag'));
        
        // Should have exactly 2 separate tags
        expect(tags.length).toBe(2);
        
        // Verify the tag contents
        expect(tags[0]).toHaveTextContent('Luxembourg Institute of Science and Technology');
        expect(tags[1]).toHaveTextContent('Catchment and Eco-Hydrology Group');
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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

    /**
     * Helper function to get the save operation fetch call from the mock.
     * The save operation is a POST request to /curation/resources.
     * 
     * @returns The most recent save call, or null if no save call was found
     * @throws Error if multiple save calls are found (unexpected test scenario)
     */
    const getSaveFetchCall = () => {
        const fetchMock = global.fetch as unknown as vi.Mock;
        
        // Find all POST calls to /curation/resources
        const saveCalls = fetchMock.mock.calls
            .map((call, index) => ({ call, index }))
            .filter(
                ({ call }) => call[0] === '/curation/resources' && call[1]?.method === 'POST'
            );
        
        // Validate: exactly zero or one save call expected in most tests
        if (saveCalls.length === 0) {
            return null;
        }
        
        if (saveCalls.length > 1) {
            throw new Error(
                `Expected at most one save call, but found ${saveCalls.length}. ` +
                `This might indicate a test issue or unintended form submissions.`
            );
        }
        
        // Return the single save call found
        return fetchMock.mock.calls[saveCalls[0].index];
    };

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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'First Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredAuthor(user);
        await fillRequiredContributor(user);
        await fillRequiredAbstract(user);
        await fillRequiredDateCreated(user);
        await user.click(saveButton);

        expect(global.fetch).toHaveBeenCalledWith('/curation/resources', expect.objectContaining({
            method: 'POST',
            credentials: 'same-origin',
        }));

        // Get the save operation fetch call
        const saveCall = getSaveFetchCall();
        expect(saveCall).toBeDefined();
        const fetchArgs = saveCall![1];
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
        expect(body.authors).toEqual([
            {
                type: 'person',
                orcid: null,
                firstName: null,
                lastName: 'Curator',
                email: null,
                website: null,
                isContact: false,
                affiliations: [],
                position: 0,
            },
        ]);

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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialYear="2025"
                initialResourceType="1"
                initialTitles={[{ title: 'Existing Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
                initialResourceId=" 7 "
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredAuthor(user);
        await fillRequiredContributor(user);
        await fillRequiredAbstract(user);
        await fillRequiredDateCreated(user);
        await user.click(saveButton);

        expect(global.fetch).toHaveBeenCalledTimes(5); // 4 vocabularies + 1 save

        // Get the save operation fetch call
        const saveCall = getSaveFetchCall();
        expect(saveCall).toBeDefined();
        const fetchArgs = saveCall![1] as RequestInit;
        const body = JSON.parse(fetchArgs.body as string);

        expect(body).toMatchObject({
            resourceId: 7,
            year: 2025,
            resourceType: 1,
            licenses: ['MIT'],
        });
        expect(body.authors).toEqual([
            expect.objectContaining({
                type: 'person',
                lastName: 'Curator',
                position: 0,
            }),
        ]);

        await screen.findByRole('dialog', { name: /successfully saved resource/i });
        expect(screen.getByText('Resource updated!')).toBeInTheDocument();
    });

    it('serializes person and institution authors in the save payload', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        const jsonMock = vi.fn().mockResolvedValue({ message: 'Stored' });
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'Dataset Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
                initialAuthors={[
                    {
                        type: 'person',
                        firstName: 'Jane',
                        lastName: 'Doe',
                        email: 'jane@example.org',
                        website: 'https://jane.example',
                        isContact: true,
                        affiliations: [
                            { value: 'University A', rorId: 'https://ror.org/05fjyn938' },
                            { value: 'Consortium B', rorId: null },
                        ],
                    },
                    {
                        type: 'institution',
                        institutionName: 'Research Lab',
                        affiliations: [
                            { value: 'Parent Org', rorId: 'https://ror.org/03yrm5c26' },
                            { value: 'Another Org', rorId: null },
                        ],
                    },
                ]}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredContributor(user);
        await fillRequiredAbstract(user);
        await fillRequiredDateCreated(user);
        await user.click(saveButton);

        expect(global.fetch).toHaveBeenCalledTimes(5); // 4 vocabularies + 1 save

        // Get the save operation fetch call
        const saveCall = getSaveFetchCall();
        expect(saveCall).toBeDefined();
        const fetchArgs = saveCall![1] as RequestInit;
        const body = JSON.parse(fetchArgs.body as string);

        expect(body.authors).toEqual([
            {
                type: 'person',
                orcid: null,
                firstName: 'Jane',
                lastName: 'Doe',
                email: 'jane@example.org',
                website: 'https://jane.example',
                isContact: true,
                affiliations: [
                    { value: 'University A', rorId: 'https://ror.org/05fjyn938' },
                    { value: 'Consortium B', rorId: null },
                ],
                position: 0,
            },
            {
                type: 'institution',
                institutionName: 'Research Lab',
                rorId: 'https://ror.org/03yrm5c26',
                affiliations: [
                    { value: 'Parent Org', rorId: 'https://ror.org/03yrm5c26' },
                    { value: 'Another Org', rorId: null },
                ],
                position: 1,
            },
        ]);
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'First Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredAuthor(user);
        await fillRequiredContributor(user);
        await fillRequiredAbstract(user);
        await fillRequiredDateCreated(user);
        await user.click(saveButton);

        expect(global.fetch).toHaveBeenCalledTimes(5); // 4 vocabularies + 1 save
        
        // Get the save operation fetch call
        const saveCall = getSaveFetchCall();
        expect(saveCall).toBeDefined();
        const fetchArgs = saveCall![1] as RequestInit;
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'Main Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredAuthor(user);
        await fillRequiredContributor(user);
        await fillRequiredAbstract(user);
        await fillRequiredDateCreated(user);
        await user.click(saveButton);

        // Only vocabulary fetches should have been called (4 times), but no save fetch
        expect(global.fetch).toHaveBeenCalledTimes(4);
        expect(
            await screen.findByText('Missing security token. Please refresh the page and try again.'),
        ).toBeInTheDocument();
    });

    it(
        'shows validation feedback when saving fails',
        async () => {
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
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
        await fillRequiredContributor(user);
        await fillRequiredAbstract(user);
        await fillRequiredDateCreated(user);
        await user.click(saveButton);

        expect(global.fetch).toHaveBeenCalledWith('/curation/resources', expect.any(Object));
        
        // Get the save operation fetch call
        const saveCall = getSaveFetchCall();
        expect(saveCall).toBeDefined();
        const fetchArgs = saveCall![1];
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
        },
        10000,
    );

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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'Primary Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await fillRequiredAuthor(user);
        await fillRequiredContributor(user);
        await fillRequiredAbstract(user);
        await fillRequiredDateCreated(user);
        await waitFor(() => expect(saveButton).toBeEnabled());
        await user.click(saveButton);

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalled();
        });

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent(
            'A network error prevented saving the resource. Please try again.',
        );
        expect(consoleSpy).toHaveBeenCalledWith(
            'Failed to save resource',
            expect.any(Error),
        );
    });

    describe('Descriptions', () => {
        it('renders the Descriptions accordion section', () => {
            render(
                <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
            />,
        );

        expect(screen.getByText('Descriptions')).toBeInTheDocument();
    });

    it('disables save button when Abstract is not filled', async () => {
        const user = userEvent.setup();
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'Primary Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        await fillRequiredAuthor(user);
        await fillRequiredContributor(user);

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        // Should still be disabled because Abstract is not filled
        expect(saveButton).toBeDisabled();
    });

    it('enables save button when Abstract is filled', async () => {
        const user = userEvent.setup();
        render(
            <DataCiteForm
                resourceTypes={resourceTypes}
                titleTypes={titleTypes}
                licenses={licenses}
                languages={languages}
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'Primary Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        await fillRequiredAuthor(user);
        await fillRequiredContributor(user);
        await fillRequiredDateCreated(user);

        // Fill Abstract
        const abstractTextarea = screen.getByRole('textbox', { name: /Abstract/i });
        await user.type(abstractTextarea, 'This is a test abstract');

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await waitFor(() => expect(saveButton).toBeEnabled());
    });

    it(
        'includes descriptions in the payload when submitting',
        async () => {
            const user = userEvent.setup({ pointerEventsCheck: 0 });

        const responseData = { message: 'Success', resource: { id: 1 } };
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'Primary Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        await fillRequiredAuthor(user);
        await fillRequiredContributor(user);
        await fillRequiredDateCreated(user);

        // Fill Abstract (required)
        const abstractTextarea = screen.getByRole('textbox', { name: /Abstract/i });
        await user.type(abstractTextarea, 'This is a test abstract');

        // Fill Methods (optional)
        const methodsTab = screen.getByRole('tab', { name: /Methods/i });
        await user.click(methodsTab);
        const methodsTextarea = screen.getByRole('textbox', { name: /Methods/i });
        await user.type(methodsTextarea, 'Test methodology');

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await waitFor(() => expect(saveButton).toBeEnabled());
        await user.click(saveButton);

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalled();
        });

        // Get the save operation fetch call
        const fetchCall = getSaveFetchCall();
        expect(fetchCall).toBeDefined();
        const requestBody = JSON.parse(fetchCall![1].body);

        expect(requestBody.descriptions).toBeDefined();
        expect(requestBody.descriptions).toHaveLength(2);
        expect(requestBody.descriptions).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    descriptionType: 'Abstract',
                    description: 'This is a test abstract',
                }),
                expect.objectContaining({
                    descriptionType: 'Methods',
                    description: 'Test methodology',
                }),
            ]),
        );
        },
        15000,
    ); // Increased timeout for this long-running test with multiple user interactions

    it('does not include empty descriptions in the payload', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        const responseData = { message: 'Success', resource: { id: 1 } };
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'Primary Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        await fillRequiredAuthor(user);
        await fillRequiredContributor(user);
        await fillRequiredDateCreated(user);

        // Fill only Abstract
        const abstractTextarea = screen.getByRole('textbox', { name: /Abstract/i });
        await user.type(abstractTextarea, 'This is a test abstract');

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await waitFor(() => expect(saveButton).toBeEnabled());
        await user.click(saveButton);

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalled();
        });

        // Get the save operation fetch call
        const fetchCall = getSaveFetchCall();
        expect(fetchCall).toBeDefined();
        const requestBody = JSON.parse(fetchCall![1].body);

        expect(requestBody.descriptions).toBeDefined();
        expect(requestBody.descriptions).toHaveLength(1);
        expect(requestBody.descriptions[0]).toEqual({
            descriptionType: 'Abstract',
            description: 'This is a test abstract',
        });
    });

    it('trims whitespace from description values', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        const responseData = { message: 'Success', resource: { id: 1 } };
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
                contributorPersonRoles={contributorPersonRoles}
                contributorInstitutionRoles={contributorInstitutionRoles}
                authorRoles={authorRoles}
                initialYear="2024"
                initialResourceType="1"
                initialTitles={[{ title: 'Primary Title', titleType: 'main-title' }]}
                initialLicenses={['MIT']}
            />,
        );

        await fillRequiredAuthor(user);
        await fillRequiredContributor(user);
        await fillRequiredDateCreated(user);

        // Fill Abstract with whitespace
        const abstractTextarea = screen.getByRole('textbox', { name: /Abstract/i });
        await user.type(abstractTextarea, '   Test abstract with spaces   ');

        const saveButton = screen.getByRole('button', { name: /save to database/i });
        await waitFor(() => expect(saveButton).toBeEnabled());
        await user.click(saveButton);

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalled();
        });

        // Get the save operation fetch call
        const fetchCall = getSaveFetchCall();
        expect(fetchCall).toBeDefined();
        const requestBody = JSON.parse(fetchCall![1].body);

        expect(requestBody.descriptions[0].description).toBe('Test abstract with spaces');
    });
    });

    describe('Dates Form Group', () => {
        it('renders the Dates accordion section', () => {
            render(
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    licenses={licenses}
                    languages={languages}
                    contributorPersonRoles={contributorPersonRoles}
                    contributorInstitutionRoles={contributorInstitutionRoles}
                    authorRoles={authorRoles}
                />,
            );

            const datesTrigger = screen.getByRole('button', { name: 'Dates' });
            expect(datesTrigger).toBeInTheDocument();
            expect(datesTrigger).toHaveAttribute('aria-expanded', 'true');
        });

        it('renders a single Date Created field by default', () => {
            render(
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    licenses={licenses}
                    languages={languages}
                    contributorPersonRoles={contributorPersonRoles}
                    contributorInstitutionRoles={contributorInstitutionRoles}
                    authorRoles={authorRoles}
                />,
            );

            const dateInputs = screen.getAllByDisplayValue('');
            const dateFields = dateInputs.filter(input => input.getAttribute('type') === 'date');
            // Now we have 2 date fields: startDate and endDate
            expect(dateFields).toHaveLength(2);
            expect(dateFields[0]).toHaveAttribute('type', 'date');
            expect(dateFields[1]).toHaveAttribute('type', 'date');
            
            const dateTypeTrigger = screen.getAllByRole('combobox').find(el => 
                el.getAttribute('id')?.includes('dateType')
            );
            expect(dateTypeTrigger).toBeDefined();
            // Check the actual value attribute which should be the required date type
            if (dateTypeTrigger) {
                expect(dateTypeTrigger.getAttribute('aria-activedescendant') || dateTypeTrigger.textContent).toContain(REQUIRED_DATE_TYPE_LABEL);
            }
        });

        it('marks Date Created as required', () => {
            render(
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    licenses={licenses}
                    languages={languages}
                    contributorPersonRoles={contributorPersonRoles}
                    contributorInstitutionRoles={contributorInstitutionRoles}
                    authorRoles={authorRoles}
                />,
            );

            const dateInputs = screen.getAllByDisplayValue('');
            const dateFields = dateInputs.filter(input => input.getAttribute('type') === 'date');
            expect(dateFields[0]).toHaveAttribute('required');
        });

        it('supports adding additional date types', async () => {
            render(
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    licenses={licenses}
                    languages={languages}
                    contributorPersonRoles={contributorPersonRoles}
                    contributorInstitutionRoles={contributorInstitutionRoles}
                    authorRoles={authorRoles}
                />,
            );
            const user = userEvent.setup({ pointerEventsCheck: 0 });

            const firstDateInput = screen.getAllByDisplayValue('').find(input => 
                input.getAttribute('type') === 'date'
            ) as HTMLInputElement;
            await user.type(firstDateInput, '2024-01-15');

            const addButton = screen.getByRole('button', { name: 'Add date' });
            await user.click(addButton);

            const dateInputs = screen.getAllByDisplayValue('').filter(input => 
                input.getAttribute('type') === 'date'
            );
            // After adding a new date: First row has 1 filled + 1 empty (endDate), second row has 2 empty = 3 empty total
            expect(dateInputs).toHaveLength(3);
            const allDateInputs = document.querySelectorAll('input[type="date"]');
            // Total: 2 date fields per row × 2 rows = 4 date inputs
            expect(allDateInputs).toHaveLength(4);
        });

        it('supports removing non-required date fields', async () => {
            render(
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    licenses={licenses}
                    languages={languages}
                    contributorPersonRoles={contributorPersonRoles}
                    contributorInstitutionRoles={contributorInstitutionRoles}
                    authorRoles={authorRoles}
                />,
            );
            const user = userEvent.setup({ pointerEventsCheck: 0 });

            const firstDateInput = screen.getAllByDisplayValue('').find(input => 
                input.getAttribute('type') === 'date'
            ) as HTMLInputElement;
            await user.type(firstDateInput, '2024-01-15');

            const addButton = screen.getByRole('button', { name: 'Add date' });
            await user.click(addButton);

            const removeButton = screen.getByRole('button', { name: 'Remove date' });
            await user.click(removeButton);

            const dateInputs = document.querySelectorAll('input[type="date"]');
            // After removing: back to 1 row with 2 date fields (startDate + endDate)
            expect(dateInputs).toHaveLength(2);
        });

        it('filters out already used date types from options', async () => {
            render(
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    licenses={licenses}
                    languages={languages}
                    contributorPersonRoles={contributorPersonRoles}
                    contributorInstitutionRoles={contributorInstitutionRoles}
                    authorRoles={authorRoles}
                />,
            );
            const user = userEvent.setup({ pointerEventsCheck: 0 });

            const firstDateInput = screen.getAllByDisplayValue('').find(input => 
                input.getAttribute('type') === 'date'
            ) as HTMLInputElement;
            await user.type(firstDateInput, '2024-01-15');

            const addButton = screen.getByRole('button', { name: 'Add date' });
            await user.click(addButton);

            const dateTypeTriggers = screen.getAllByRole('combobox').filter(el => 
                el.getAttribute('id')?.includes('dateType')
            );
            await user.click(dateTypeTriggers[1]);

            const createdOption = screen.queryByRole('option', { name: REQUIRED_DATE_TYPE_LABEL });
            expect(createdOption).not.toBeInTheDocument();
        });

        it('displays description for selected date type', () => {
            render(
                <DataCiteForm
                    resourceTypes={resourceTypes}
                    titleTypes={titleTypes}
                    licenses={licenses}
                    languages={languages}
                    contributorPersonRoles={contributorPersonRoles}
                    contributorInstitutionRoles={contributorInstitutionRoles}
                    authorRoles={authorRoles}
                />,
            );

            const description = screen.getByText(/The date the resource itself was put together/);
            expect(description).toBeInTheDocument();
        });
    });
});

