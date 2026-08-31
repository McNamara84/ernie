import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@tests/vitest/utils/render';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { EDITOR_SETTINGS_SECTION_ORDER } from '@/components/settings/editor-settings-section';
import EditorSettings from '@/pages/settings/index';

const formHarness = vi.hoisted(() => ({
    initialData: null as Record<string, unknown> | null,
    post: vi.fn(),
}));

const axiosMocks = vi.hoisted(() => ({
    post: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('axios', () => ({
    default: axiosMocks,
    isAxiosError: () => false,
}));

vi.mock('@inertiajs/react', async () => {
    const ReactModule = await import('react');

    return {
        Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
        useForm: (initial: Record<string, unknown>) => {
            const [data, setDataState] = ReactModule.useState(initial);
            const [defaults] = ReactModule.useState(initial);
            formHarness.initialData = initial;

            const setData = (keyOrData: string | Record<string, unknown>, value?: unknown) => {
                if (typeof keyOrData === 'string') {
                    setDataState((current) => ({ ...current, [keyOrData]: value }));
                    return;
                }

                setDataState(keyOrData);
            };

            return {
                data,
                setData,
                post: (url: string) => formHarness.post(url, data),
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(defaults),
                recentlySuccessful: false,
            };
        },
        usePage: () => ({
            props: {
                auth: {
                    user: {
                        id: 1,
                        name: 'Admin User',
                        role: 'admin',
                    },
                },
            },
        }),
    };
});

vi.mock('@/routes', () => ({ settings: () => ({ url: '/settings' }) }));

vi.mock('@/layouts/app-layout', () => {
    return {
        default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
    };
});

vi.mock('@/components/settings/thesaurus-card', () => ({
    ThesaurusCard: () => <div data-testid="thesaurus-card-mock">Thesaurus settings content</div>,
}));

vi.mock('@/components/settings/pid-settings-card', () => ({
    PidSettingsCard: () => <div data-testid="pid-settings-card-mock">PID settings content</div>,
}));

const defaultThesauri = [
    {
        type: 'science_keywords',
        displayName: 'Science Keywords',
        isActive: true,
        isElmoActive: false,
        exists: true,
        conceptCount: 100,
        lastUpdated: null,
    },
    {
        type: 'platforms',
        displayName: 'Platforms',
        isActive: false,
        isElmoActive: true,
        exists: true,
        conceptCount: 50,
        lastUpdated: null,
    },
];

const defaultProps: React.ComponentProps<typeof EditorSettings> = {
    resourceTypes: [
        { id: 1, name: 'Dataset', active: true, elmo_active: false },
        { id: 2, name: 'Collection', active: false, elmo_active: true },
    ],
    titleTypes: [{ id: 1, name: 'Main Title', slug: 'main-title', active: true, elmo_active: false }],
    licenses: [
        {
            id: 1,
            identifier: 'CC-BY-4.0',
            name: 'Creative Commons Attribution 4.0',
            active: true,
            elmo_active: false,
            excluded_resource_type_ids: [],
        },
    ],
    languages: [{ id: 1, code: 'en', name: 'English', active: true, elmo_active: false }],
    dateTypes: [{ id: 1, name: 'Accepted', slug: 'accepted', description: null, active: true }],
    descriptionTypes: [{ id: 1, name: 'Abstract', slug: 'Abstract', active: true, elmo_active: true }],
    thesauri: defaultThesauri,
    pidSettings: [
        {
            type: 'ror',
            displayName: 'ROR',
            isActive: true,
            isElmoActive: false,
            exists: true,
            itemCount: 10,
            lastUpdated: null,
        },
    ],
    landingPageDomains: [],
    contributorPersonRoles: [{ id: 1, name: 'Contact Person', slug: 'ContactPerson', category: 'person', active: true, elmo_active: false }],
    contributorInstitutionRoles: [{ id: 2, name: 'Distributor', slug: 'Distributor', category: 'institution', active: false, elmo_active: true }],
    contributorBothRoles: [{ id: 3, name: 'Other', slug: 'Other', category: 'both', active: true, elmo_active: true }],
    relationTypes: [{ id: 1, name: 'Cites', slug: 'Cites', active: true, elmo_active: false }],
    identifierTypes: [
        {
            id: 1,
            name: 'DOI',
            slug: 'DOI',
            active: true,
            elmo_active: true,
            patterns: [{ id: 1, type: 'validation', pattern: '^10\\.', is_active: true, priority: 10 }],
        },
    ],
    datacenters: [],
};

function renderSettings(overrides: Partial<React.ComponentProps<typeof EditorSettings>> = {}) {
    return render(<EditorSettings {...defaultProps} {...overrides} />);
}

function sectionTrigger(name: string | RegExp): HTMLButtonElement {
    return screen.getByRole('button', { name }) as HTMLButtonElement;
}

function section(value: string): HTMLElement {
    const element = document.querySelector<HTMLElement>(`[data-accordion-value="${value}"]`);

    if (!element) {
        throw new Error(`Settings section ${value} was not rendered`);
    }

    return element;
}

describe('EditorSettings accordion page', () => {
    beforeEach(() => {
        formHarness.initialData = null;
        formHarness.post.mockReset();
        axiosMocks.post.mockReset();
        axiosMocks.delete.mockReset();
    });

    it('renders all sections in the agreed full-width order and collapses them initially', () => {
        renderSettings();

        const accordion = screen.getByTestId('settings-accordion');
        expect(accordion).toHaveClass('flex', 'flex-col');
        expect(screen.queryByTestId('settings-grid')).not.toBeInTheDocument();

        const renderedOrder = within(accordion)
            .getAllByRole('button')
            .map((trigger) => trigger.closest('[data-accordion-value]')?.getAttribute('data-accordion-value'));

        expect(renderedOrder).toEqual(EDITOR_SETTINGS_SECTION_ORDER);
        within(accordion)
            .getAllByRole('button')
            .forEach((trigger) => expect(trigger).toHaveAttribute('aria-expanded', 'false'));
    });

    it('opens one section at a time and lets the open section collapse again', async () => {
        const user = userEvent.setup();
        renderSettings();

        const resourceTypes = sectionTrigger(/^Resource Types/);
        const licenses = sectionTrigger(/^Licenses/);

        await user.click(resourceTypes);
        expect(resourceTypes).toHaveAttribute('aria-expanded', 'true');
        expect(within(section('resource-types')).getByDisplayValue('Dataset')).toBeVisible();

        await user.click(licenses);
        expect(licenses).toHaveAttribute('aria-expanded', 'true');
        expect(resourceTypes).toHaveAttribute('aria-expanded', 'false');
        expect(within(section('licenses')).getByText('CC-BY-4.0')).toBeVisible();

        await user.click(licenses);
        expect(licenses).toHaveAttribute('aria-expanded', 'false');
    });

    it('keeps force-mounted Thesaurus and PID content alive while switching sections', async () => {
        const user = userEvent.setup();
        renderSettings();

        await user.click(sectionTrigger(/^Thesauri/));
        const thesaurusContent = screen.getByTestId('thesaurus-card-mock');
        expect(thesaurusContent).toBeVisible();

        await user.click(sectionTrigger(/^Persistent Identifiers/));
        expect(screen.getByTestId('thesaurus-card-mock')).toBe(thesaurusContent);
        expect(thesaurusContent).not.toBeVisible();
        expect(screen.getByTestId('pid-settings-card-mock')).toBeVisible();
    });

    it('shows live section summaries from current form data', async () => {
        const user = userEvent.setup();
        renderSettings({
            resourceTypes: [
                { id: 1, name: 'Dataset', active: false, elmo_active: false },
                { id: 2, name: 'Collection', active: false, elmo_active: true },
            ],
        });

        const resourceTypes = section('resource-types');
        expect(within(resourceTypes).getByText('2 resource types')).toBeInTheDocument();
        expect(within(resourceTypes).getByText('0 ERNIE')).toBeInTheDocument();
        expect(within(resourceTypes).getByText('1 ELMO')).toBeInTheDocument();

        await user.click(sectionTrigger(/^Resource Types/));
        await user.click(within(resourceTypes).getByLabelText('Select all ERNIE active for Resource Types'));

        expect(within(resourceTypes).getByText('2 ERNIE')).toBeInTheDocument();
    });

    it('keeps unsaved edits when switching sections and submits the current full form data', async () => {
        const user = userEvent.setup();
        renderSettings();

        const save = screen.getByRole('button', { name: 'Save changes' });
        expect(save).toBeDisabled();
        expect(screen.getByTestId('settings-save-status')).toHaveTextContent('No unsaved changes');

        await user.click(sectionTrigger(/^Resource Types/));
        const nameInput = within(section('resource-types')).getAllByLabelText('Name')[0];
        await user.clear(nameInput);
        await user.type(nameInput, 'Updated Dataset');

        expect(save).toBeEnabled();
        expect(screen.getByTestId('settings-save-status')).toHaveTextContent('Unsaved changes');

        await user.click(sectionTrigger(/^Licenses/));
        await user.click(sectionTrigger(/^Resource Types/));
        expect(within(section('resource-types')).getByDisplayValue('Updated Dataset')).toBeVisible();

        await user.click(save);
        expect(formHarness.post).toHaveBeenCalledWith(
            '/settings',
            expect.objectContaining({
                resourceTypes: expect.arrayContaining([expect.objectContaining({ name: 'Updated Dataset' })]),
            }),
        );
    });

    it('never substitutes an abbreviated license identifier into submitted form data', async () => {
        const user = userEvent.setup();
        const identifier = 'CUSTOM-THE-DATA-MADE-AVAILABLE-THROUGH-INTERMAGNET-ARE-PROVIDED-FOR-YOUR-USE-AND-ARE-NOT-FOR-COMMERCIAL-USE-83AF93FC8F37';
        renderSettings({
            licenses: [
                {
                    id: 7,
                    identifier,
                    name: 'INTERMAGNET data terms',
                    active: false,
                    elmo_active: false,
                    excluded_resource_type_ids: [],
                },
            ],
        });

        await user.click(sectionTrigger(/^Licenses/));
        expect(within(section('licenses')).getByText('CUSTOM-THE-DATA-MADE-AVAILABLE-THROUGH-INTERMAGNET…')).toBeVisible();

        await user.click(within(section('licenses')).getByLabelText('ERNIE active'));
        await user.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(formHarness.post).toHaveBeenCalledWith(
            '/settings',
            expect.objectContaining({
                licenses: [expect.objectContaining({ identifier })],
            }),
        );
    });

    it('updates the immediately saved Domain summary without dirtying the global form', async () => {
        const user = userEvent.setup();
        axiosMocks.post.mockResolvedValue({
            data: {
                domain: { id: 9, domain: 'https://example.org/' },
                message: 'Domain added',
            },
        });
        renderSettings();

        await user.click(sectionTrigger(/^Landing Page Domains/));
        await user.type(screen.getByPlaceholderText('https://example.org/'), 'https://example.org/');
        await user.click(within(section('landing-page-domains')).getByRole('button', { name: 'Add' }));

        await waitFor(() => expect(within(section('landing-page-domains')).getByText('1 domain')).toBeInTheDocument());
        expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
        expect(screen.getByTestId('settings-save-status')).toHaveTextContent('No unsaved changes');
    });

    it('initializes the form with complete backend values and enforces Abstract as active', () => {
        renderSettings({
            descriptionTypes: [
                { id: 1, name: 'Abstract', slug: 'Abstract', active: false, elmo_active: false },
                { id: 2, name: 'Methods', slug: 'Methods', active: false, elmo_active: true },
            ],
        });

        expect(formHarness.initialData).toEqual(
            expect.objectContaining({
                resourceTypes: defaultProps.resourceTypes,
                licenses: defaultProps.licenses,
                thesauri: defaultThesauri.map(({ type, isActive, isElmoActive }) => ({ type, isActive, isElmoActive })),
                descriptionTypes: [
                    { id: 1, name: 'Abstract', slug: 'Abstract', active: true, elmo_active: true },
                    { id: 2, name: 'Methods', slug: 'Methods', active: false, elmo_active: true },
                ],
            }),
        );
    });
});
