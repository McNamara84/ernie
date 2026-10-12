import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { act, fireEvent, render, screen, waitFor, within } from '@tests/vitest/utils/render';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { EDITOR_SETTINGS_SECTION_ORDER } from '@/components/settings/editor-settings-section';
import EditorSettings from '@/pages/settings/index';

const formHarness = vi.hoisted(() => ({
    initialData: null as Record<string, unknown> | null,
    replaceData: null as ((data: Record<string, unknown>) => void) | null,
    succeed: null as (() => void) | null,
    post: vi.fn(),
}));

const axiosMocks = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('axios', () => ({
    default: axiosMocks,
    isAxiosError: (error: unknown) => typeof error === 'object' && error !== null && 'response' in error,
}));

vi.mock('@inertiajs/react', async () => {
    const ReactModule = await import('react');
    const { isDeepStrictEqual } = await import('node:util');

    return {
        Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
        useForm: (initial: Record<string, unknown>) => {
            const [data, setDataState] = ReactModule.useState(initial);
            const [defaults, setDefaults] = ReactModule.useState(initial);
            const transform = ReactModule.useRef((values: Record<string, unknown>) => values);
            const currentData = ReactModule.useRef(data);
            currentData.current = data;
            const defaultsSetInSuccess = ReactModule.useRef(false);
            formHarness.initialData = initial;

            const setData = (keyOrData: string | Record<string, unknown>, value?: unknown) => {
                if (typeof keyOrData === 'string') {
                    setDataState((current) => ({ ...current, [keyOrData]: value }));
                    return;
                }

                setDataState(keyOrData);
            };
            formHarness.replaceData = (nextData) => setData(nextData);

            return {
                data,
                setData,
                setDefaults: (values: Record<string, unknown>) => {
                    defaultsSetInSuccess.current = true;
                    setDefaults(values);
                },
                transform: (callback: (values: Record<string, unknown>) => Record<string, unknown>) => {
                    transform.current = callback;
                },
                post: (url: string, options?: { onSuccess?: () => void }) => {
                    formHarness.post(url, transform.current(data));
                    formHarness.succeed = () => {
                        defaultsSetInSuccess.current = false;
                        options?.onSuccess?.();
                        if (!defaultsSetInSuccess.current) {
                            setDefaults(currentData.current);
                        }
                    };
                },
                processing: false,
                isDirty: !isDeepStrictEqual(data, defaults),
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
        isElmoMslActive: false,
        exists: true,
        conceptCount: 100,
        lastUpdated: null,
    },
    {
        type: 'platforms',
        displayName: 'Platforms',
        isActive: false,
        isElmoActive: true,
        isElmoMslActive: false,
        exists: true,
        conceptCount: 50,
        lastUpdated: null,
    },
];

const defaultProps: React.ComponentProps<typeof EditorSettings> = {
    resourceTypes: [
        { id: 1, name: 'Dataset', active: true, elmo_active: false, elmo_msl_active: false },
        { id: 2, name: 'Collection', active: false, elmo_active: true, elmo_msl_active: false },
    ],
    titleTypes: [{ id: 1, name: 'Main Title', slug: 'main-title', active: true, elmo_active: false, elmo_msl_active: false }],
    licenses: [
        {
            id: 1,
            identifier: 'CC-BY-4.0',
            name: 'Creative Commons Attribution 4.0',
            active: true,
            elmo_active: false,
            elmo_msl_active: false,
            excluded_resource_type_ids: [],
            elmo_excluded_resource_type_ids: [],
            elmo_msl_excluded_resource_type_ids: [],
        },
    ],
    languages: [{ id: 1, code: 'en', name: 'English', active: true, elmo_active: false, elmo_msl_active: false }],
    dateTypes: [{ id: 1, name: 'Accepted', slug: 'accepted', description: null, active: true, elmo_active: false, elmo_msl_active: false }],
    descriptionTypes: [{ id: 1, name: 'Abstract', slug: 'Abstract', active: true, elmo_active: true, elmo_msl_active: true }],
    thesauri: defaultThesauri,
    pidSettings: [
        {
            type: 'ror',
            displayName: 'ROR',
            isActive: true,
            isElmoActive: false,
            isElmoMslActive: false,
            exists: true,
            itemCount: 10,
            lastUpdated: null,
        },
    ],
    landingPageDomains: [],
    contributorPersonRoles: [
        { id: 1, name: 'Contact Person', slug: 'ContactPerson', category: 'person', active: true, elmo_active: false, elmo_msl_active: false },
    ],
    contributorInstitutionRoles: [
        { id: 2, name: 'Distributor', slug: 'Distributor', category: 'institution', active: false, elmo_active: true, elmo_msl_active: false },
    ],
    contributorBothRoles: [{ id: 3, name: 'Other', slug: 'Other', category: 'both', active: true, elmo_active: true, elmo_msl_active: false }],
    relationTypes: [{ id: 1, name: 'Cites', slug: 'Cites', active: true, elmo_active: false, elmo_msl_active: false }],
    identifierTypes: [
        {
            id: 1,
            name: 'DOI',
            slug: 'DOI',
            active: true,
            elmo_active: true,
            elmo_msl_active: false,
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

function settingsTriggers(): HTMLButtonElement[] {
    return Array.from(screen.getByTestId('settings-accordion').querySelectorAll<HTMLButtonElement>('[data-slot="accordion-trigger"]'));
}

function bulkAction(value: string, action: 'Expand' | 'Collapse'): HTMLButtonElement {
    return within(section(value)).getByRole('button', { name: `${action} all settings cards` }) as HTMLButtonElement;
}

function expectBulkActions(expand: boolean, collapse: boolean) {
    for (const value of EDITOR_SETTINGS_SECTION_ORDER) {
        const actions = within(section(value).querySelector<HTMLElement>('[data-slot="accordion-actions"]')!);
        expect(actions.queryByRole('button', { name: 'Expand all settings cards' }) !== null).toBe(expand);
        expect(actions.queryByRole('button', { name: 'Collapse all settings cards' }) !== null).toBe(collapse);
    }
}

describe('EditorSettings accordion page', () => {
    beforeEach(() => {
        formHarness.initialData = null;
        formHarness.replaceData = null;
        formHarness.succeed = null;
        formHarness.post.mockReset();
        axiosMocks.post.mockReset();
        axiosMocks.patch.mockReset();
        axiosMocks.delete.mockReset();
    });

    it('renders all sections in the agreed full-width order and collapses them initially', () => {
        renderSettings();

        const accordion = screen.getByTestId('settings-accordion');
        expect(accordion).toHaveClass('flex', 'flex-col');
        expect(screen.queryByTestId('settings-grid')).not.toBeInTheDocument();

        const renderedOrder = within(accordion)
            .getAllByRole('button', { expanded: false })
            .map((trigger) => trigger.closest('[data-accordion-value]')?.getAttribute('data-accordion-value'));

        expect(renderedOrder).toEqual(EDITOR_SETTINGS_SECTION_ORDER);
        expect(sectionTrigger(/^Resource Types/)).toHaveAccessibleName(/2 resource types, 1 ERNIE, 1 ELMO/);
        within(accordion)
            .getAllByRole('button', { expanded: false })
            .forEach((trigger) => expect(trigger).toHaveAttribute('aria-expanded', 'false'));
        expectBulkActions(true, false);
    });

    it('opens sections independently and closes only the chosen section', async () => {
        const user = userEvent.setup();
        renderSettings();

        const resourceTypes = sectionTrigger(/^Resource Types/);
        const licenses = sectionTrigger(/^Licenses/);

        await user.click(resourceTypes);
        expect(resourceTypes).toHaveAttribute('aria-expanded', 'true');
        expect(within(section('resource-types')).getByDisplayValue('Dataset')).toBeVisible();

        await user.click(licenses);
        expect(licenses).toHaveAttribute('aria-expanded', 'true');
        expect(resourceTypes).toHaveAttribute('aria-expanded', 'true');
        expect(within(section('licenses')).getByText('CC-BY-4.0')).toBeVisible();

        await user.click(licenses);
        expect(licenses).toHaveAttribute('aria-expanded', 'false');
        expect(resourceTypes).toHaveAttribute('aria-expanded', 'true');

        await user.click(resourceTypes);
        expectBulkActions(true, false);
    });

    it('keeps all three Contributor Roles cards open together', async () => {
        const user = userEvent.setup();
        renderSettings();

        for (const title of ['Contributor Roles (Persons)', 'Contributor Roles (Institutions)', 'Contributor Roles (Both)']) {
            await user.click(sectionTrigger(new RegExp(`^${title.replace(/[()]/g, '\\$&')}`)));
        }

        for (const value of ['contributor-person-roles', 'contributor-institution-roles', 'contributor-both-roles']) {
            expect(within(section(value)).getByRole('button', { expanded: true })).toBeVisible();
            expect(within(section(value)).getByRole('table')).toBeVisible();
        }
    });

    it('moves a contributor role between open cards without closing them or losing edits', async () => {
        const user = userEvent.setup();
        renderSettings();
        for (const title of [/^Contributor Roles \(Persons\)/, /^Contributor Roles \(Institutions\)/, /^Contributor Roles \(Both\)/]) {
            await user.click(sectionTrigger(title));
        }

        await user.click(within(section('contributor-person-roles')).getByRole('combobox', { name: 'Category for Contact Person' }));
        await user.click(screen.getByRole('option', { name: 'Institution' }));
        expect(within(section('contributor-person-roles')).queryByText('Contact Person')).not.toBeInTheDocument();
        expect(within(section('contributor-institution-roles')).getByText('Contact Person')).toBeVisible();
        for (const value of ['contributor-person-roles', 'contributor-institution-roles', 'contributor-both-roles']) {
            expect(within(section(value)).getByRole('button', { expanded: true })).toBeVisible();
        }

        await user.click(bulkAction('contributor-institution-roles', 'Collapse'));
        await user.click(bulkAction('contributor-person-roles', 'Expand'));
        expect(within(section('contributor-institution-roles')).getByRole('combobox', { name: 'Category for Contact Person' })).toHaveTextContent(
            'Institution',
        );
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(formHarness.post).toHaveBeenCalledWith(
            '/settings',
            expect.objectContaining({
                contributorPersonRoles: [],
                contributorInstitutionRoles: expect.arrayContaining([expect.objectContaining({ id: 1, category: 'institution' })]),
                contributorBothRoles: defaultProps.contributorBothRoles,
            }),
        );
    });

    it('expands and collapses every card from different headings and mixed states', async () => {
        const user = userEvent.setup();
        renderSettings();

        await user.click(sectionTrigger(/^Licenses/));
        expectBulkActions(true, true);

        await user.click(bulkAction('contributor-both-roles', 'Expand'));
        expect(settingsTriggers()).toHaveLength(16);
        settingsTriggers().forEach((trigger) => expect(trigger).toHaveAttribute('aria-expanded', 'true'));
        expectBulkActions(false, true);
        expect(within(section('datacenters')).getByPlaceholderText('Datacenter name')).toBeVisible();

        await user.click(bulkAction('identifier-types', 'Collapse'));
        settingsTriggers().forEach((trigger) => expect(trigger).toHaveAttribute('aria-expanded', 'false'));
        expectBulkActions(true, false);

        await user.click(bulkAction('datacenters', 'Expand'));
        expectBulkActions(false, true);
        await user.click(sectionTrigger(/^Resource Types/));
        expectBulkActions(true, true);

        await user.click(bulkAction('resource-types', 'Collapse'));
        settingsTriggers().forEach((trigger) => expect(trigger).toHaveAttribute('aria-expanded', 'false'));
        expectBulkActions(true, false);
        expect(formHarness.post).not.toHaveBeenCalled();
        expect(axiosMocks.post).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
        expect(screen.getByTestId('settings-save-status')).toHaveTextContent('No unsaved changes');
    });

    it('keeps bulk actions outside the card trigger and returns keyboard focus to that trigger', async () => {
        const user = userEvent.setup();
        renderSettings();
        const resourceTypes = sectionTrigger(/^Resource Types/);
        const expand = bulkAction('resource-types', 'Expand');
        expect(expand).toHaveAttribute('type', 'button');
        expect(resourceTypes).not.toContainElement(expand);

        expand.focus();
        await user.keyboard('{Enter}');
        expect(resourceTypes).toHaveFocus();
        settingsTriggers().forEach((trigger) => expect(trigger).toHaveAttribute('aria-expanded', 'true'));

        const collapse = bulkAction('resource-types', 'Collapse');
        expect(collapse).toHaveAttribute('type', 'button');
        expect(resourceTypes).not.toContainElement(collapse);
        collapse.focus();
        await user.keyboard(' ');
        expect(resourceTypes).toHaveFocus();
        settingsTriggers().forEach((trigger) => expect(trigger).toHaveAttribute('aria-expanded', 'false'));
        expect(formHarness.post).not.toHaveBeenCalled();
    });

    it('starts collapsed on a new mount and keeps open cards during partial prop updates', async () => {
        const user = userEvent.setup();
        const view = renderSettings();
        await user.click(sectionTrigger(/^Resource Types/));
        await user.click(sectionTrigger(/^Persistent Identifiers/));

        view.rerender(<EditorSettings {...defaultProps} pidSettings={defaultProps.pidSettings.map((pid) => ({ ...pid, itemCount: 20 }))} />);
        expect(sectionTrigger(/^Resource Types/)).toHaveAttribute('aria-expanded', 'true');
        expect(sectionTrigger(/^Persistent Identifiers/)).toHaveAttribute('aria-expanded', 'true');

        view.unmount();
        renderSettings();
        settingsTriggers().forEach((trigger) => expect(trigger).toHaveAttribute('aria-expanded', 'false'));
        expectBulkActions(true, false);
    });

    it('mounts regular section content only while that section is open', async () => {
        const user = userEvent.setup();
        renderSettings();

        expect(screen.queryByDisplayValue('Dataset')).not.toBeInTheDocument();

        await user.click(sectionTrigger(/^Resource Types/));
        const resourceTypeName = within(section('resource-types')).getByDisplayValue('Dataset');
        expect(resourceTypeName).toBeVisible();

        await user.click(sectionTrigger(/^Licenses/));
        expect(resourceTypeName).toBeVisible();
        await user.click(sectionTrigger(/^Resource Types/));
        expect(resourceTypeName).not.toBeInTheDocument();
    });

    it('keeps force-mounted Thesaurus and PID content alive when individually or collectively collapsed', async () => {
        const user = userEvent.setup();
        renderSettings();

        expect(screen.getByTestId('thesaurus-card-mock')).not.toBeVisible();
        expect(screen.getByTestId('pid-settings-card-mock')).not.toBeVisible();

        await user.click(sectionTrigger(/^Thesauri/));
        const thesaurusContent = screen.getByTestId('thesaurus-card-mock');
        expect(thesaurusContent).toBeVisible();

        await user.click(sectionTrigger(/^Persistent Identifiers/));
        const pidContent = screen.getByTestId('pid-settings-card-mock');
        expect(screen.getByTestId('thesaurus-card-mock')).toBe(thesaurusContent);
        expect(thesaurusContent).toBeVisible();
        expect(pidContent).toBeVisible();

        await user.click(sectionTrigger(/^Thesauri/));
        expect(thesaurusContent).not.toBeVisible();
        expect(pidContent).toBeVisible();

        await user.click(bulkAction('thesauri', 'Collapse'));
        expect(pidContent).not.toBeVisible();
        expect(screen.getByTestId('thesaurus-card-mock')).toBe(thesaurusContent);
        expect(screen.getByTestId('pid-settings-card-mock')).toBe(pidContent);
        for (const value of ['thesauri', 'persistent-identifiers']) {
            expect(section(value).querySelector('[data-slot="accordion-content"]')).toHaveAttribute('hidden');
            expect(section(value).querySelector('[data-slot="accordion-content"]')).toHaveAttribute('aria-hidden', 'true');
        }

        await user.click(bulkAction('thesauri', 'Expand'));
        expect(thesaurusContent).toBeVisible();
        expect(pidContent).toBeVisible();
        for (const value of ['thesauri', 'persistent-identifiers']) {
            expect(section(value).querySelector('[data-slot="accordion-content"]')).not.toHaveAttribute('hidden');
            expect(section(value).querySelector('[data-slot="accordion-content"]')).not.toHaveAttribute('aria-hidden');
        }
    });

    it('shows live section summaries from current form data', async () => {
        const user = userEvent.setup();
        renderSettings({
            resourceTypes: [
                { id: 1, name: 'Dataset', active: false, elmo_active: false, elmo_msl_active: false },
                { id: 2, name: 'Collection', active: false, elmo_active: true, elmo_msl_active: false },
            ],
        });

        const resourceTypes = section('resource-types');
        expect(within(resourceTypes).getByText('2 resource types')).toBeInTheDocument();
        expect(within(resourceTypes).getByText('0 ERNIE')).toBeInTheDocument();
        expect(within(resourceTypes).getByText('1 ELMO')).toBeInTheDocument();

        await user.click(sectionTrigger(/^Resource Types/));
        await user.click(within(resourceTypes).getByLabelText('Select all ERNIE active for Resource Types'));

        expect(within(resourceTypes).getByText('2 ERNIE')).toBeInTheDocument();

        await user.click(sectionTrigger(/^Resource Types/));
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

    it('preserves unsaved values through bulk actions and keeps cards open after saving', async () => {
        const user = userEvent.setup();
        renderSettings();
        await user.click(sectionTrigger(/^Resource Types/));
        await user.type(within(section('resource-types')).getAllByLabelText('Name')[0], ' edited');
        const save = screen.getByRole('button', { name: 'Save changes' });

        await user.click(bulkAction('resource-types', 'Collapse'));
        expect(screen.queryByDisplayValue('Dataset edited')).not.toBeInTheDocument();
        expect(save).toBeEnabled();
        await user.click(bulkAction('licenses', 'Expand'));
        expect(within(section('resource-types')).getByDisplayValue('Dataset edited')).toBeVisible();
        expect(save).toBeEnabled();
        expect(formHarness.post).not.toHaveBeenCalled();

        await user.click(save);
        const submitted = formHarness.post.mock.calls[0][1];
        expect(submitted).not.toHaveProperty('openSections');
        expect(submitted.resourceTypes[0].name).toBe('Dataset edited');
        // A request without a success callback leaves edits and open cards intact.
        expect(save).toBeEnabled();
        settingsTriggers().forEach((trigger) => expect(trigger).toHaveAttribute('aria-expanded', 'true'));

        act(() => formHarness.succeed?.());
        expect(save).toBeDisabled();
        settingsTriggers().forEach((trigger) => expect(trigger).toHaveAttribute('aria-expanded', 'true'));
        expect(within(section('resource-types')).getByDisplayValue('Dataset edited')).toBeVisible();
    });

    it.each([false, true])('omits the unchanged installation order when saving other settings (section opened: %s)', async (openSuggestions) => {
        const user = userEvent.setup();
        renderSettings({ downloadUrlSuggestionOrder: ['https://datapub.gfz.de/download'] });

        if (openSuggestions) {
            await user.click(sectionTrigger(/^Download URL suggestions/));
            expect(screen.getByLabelText('Download URL suggestion 1')).toHaveValue('https://datapub.gfz.de/download');
            expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
        }
        await user.click(sectionTrigger(/^Resource Types/));
        await user.type(within(section('resource-types')).getAllByLabelText('Name')[0], ' updated');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));

        const payload = formHarness.post.mock.calls[0][1];
        expect(payload).not.toHaveProperty('downloadUrlSuggestionOrder');
        expect(payload.resourceTypes[0].name).toBe('Dataset updated');
    });

    it('submits reordered download suggestions with the other settings', async () => {
        const user = userEvent.setup();
        renderSettings({
            downloadUrlSuggestionOrder: ['https://datapub.gfz.de/download'],
            downloadUrlSuggestions: [{ value: 'https://example.org/', usage_count: 5 }],
        });
        await user.click(sectionTrigger(/^Download URL suggestions/));
        await user.click(screen.getByRole('button', { name: 'Move suggestion 2 up' }));
        await user.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(formHarness.post).toHaveBeenLastCalledWith(
            '/settings',
            expect.objectContaining({
                downloadUrlSuggestionOrder: ['https://example.org/', 'https://datapub.gfz.de/download'],
                resourceTypes: defaultProps.resourceTypes,
            }),
        );
    });

    it('submits an explicitly empty order after the last prefix is removed', async () => {
        const user = userEvent.setup();
        renderSettings({ downloadUrlSuggestionOrder: ['https://datapub.gfz.de/download'] });
        await user.click(sectionTrigger(/^Download URL suggestions/));
        await user.click(screen.getByRole('button', { name: 'Remove suggestion 1' }));
        await user.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(formHarness.post.mock.calls[0][1]).toHaveProperty('downloadUrlSuggestionOrder', []);
    });

    it('omits the order when prefix edits are reverted before saving another section', async () => {
        const user = userEvent.setup();
        renderSettings({ downloadUrlSuggestionOrder: ['https://datapub.gfz.de/download'] });
        await user.click(sectionTrigger(/^Download URL suggestions/));
        const prefix = screen.getByLabelText('Download URL suggestion 1');
        await user.type(prefix, 's');
        await user.keyboard('[Backspace]');
        await user.click(sectionTrigger(/^Resource Types/));
        await user.type(within(section('resource-types')).getAllByLabelText('Name')[0], ' updated');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(formHarness.post.mock.calls[0][1]).not.toHaveProperty('downloadUrlSuggestionOrder');
    });

    it('retries changed prefixes and omits them from unrelated saves only after success', async () => {
        const user = userEvent.setup();
        renderSettings({ downloadUrlSuggestionOrder: ['https://datapub.gfz.de/download'] });
        await user.click(sectionTrigger(/^Download URL suggestions/));
        await user.type(screen.getByLabelText('Download URL suggestion 1'), '/data');
        const save = screen.getByRole('button', { name: 'Save changes' });
        await user.click(save);
        // A failed request must leave the baseline unchanged so the retry includes the order.
        await user.click(save);
        expect(formHarness.post.mock.calls[1][1]).toHaveProperty('downloadUrlSuggestionOrder', ['https://datapub.gfz.de/download/data']);

        act(() => formHarness.succeed?.());
        expect(save).toBeDisabled();
        await user.click(sectionTrigger(/^Resource Types/));
        await user.type(within(section('resource-types')).getAllByLabelText('Name')[0], ' updated');
        await user.click(save);
        expect(formHarness.post.mock.calls[2][1]).not.toHaveProperty('downloadUrlSuggestionOrder');

        await user.click(screen.getByRole('button', { name: 'Remove suggestion 1' }));
        await user.click(save);
        expect(formHarness.post.mock.calls[3][1]).toHaveProperty('downloadUrlSuggestionOrder', []);
    });

    it('retains edits made while a previous prefix change is being saved', async () => {
        const user = userEvent.setup();
        renderSettings({ downloadUrlSuggestionOrder: ['https://datapub.gfz.de/download'] });
        await user.click(sectionTrigger(/^Download URL suggestions/));
        const prefix = screen.getByLabelText('Download URL suggestion 1');
        await user.type(prefix, '/first');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        await user.type(prefix, '/second');
        act(() => formHarness.succeed?.());
        await user.click(screen.getByRole('button', { name: 'Save changes' }));

        expect(formHarness.post.mock.calls[1][1]).toHaveProperty('downloadUrlSuggestionOrder', ['https://datapub.gfz.de/download/first/second']);
    });

    it('does not mark semantically equal data as dirty when object keys are reordered', () => {
        renderSettings();

        const reorderedData = Object.fromEntries(Object.entries(formHarness.initialData ?? {}).reverse());
        act(() => formHarness.replaceData?.(reorderedData));

        expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
        expect(screen.getByTestId('settings-save-status')).toHaveTextContent('No unsaved changes');
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
                    elmo_msl_active: false,
                    excluded_resource_type_ids: [],
                    elmo_excluded_resource_type_ids: [],
                    elmo_msl_excluded_resource_type_ids: [],
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

    it('renames an assigned datacenter immediately and retains its count and global save state', async () => {
        const user = userEvent.setup();
        axiosMocks.patch.mockResolvedValue({
            data: { datacenter: { id: 8, name: 'Alpha renamed', resources_count: 3 }, message: 'Datacenter renamed successfully.' },
        });
        renderSettings({
            datacenters: [
                { id: 8, name: 'Zulu', resources_count: 3 },
                { id: 9, name: 'Beta', resources_count: 0 },
            ],
        });
        await user.click(sectionTrigger(/^Datacenters/));

        const rename = within(section('datacenters')).getByRole('button', { name: 'Rename Zulu' });
        expect(rename).toBeEnabled();
        await user.click(rename);
        const input = screen.getByRole('textbox', { name: 'Datacenter name for Zulu' });
        await user.clear(input);
        await user.type(input, ' Alpha renamed ');
        await user.click(within(section('datacenters')).getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(axiosMocks.patch).toHaveBeenCalledWith('/api/datacenters/8', { name: 'Alpha renamed' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Rename Alpha renamed' })).toBeInTheDocument());
        const rows = within(section('datacenters')).getAllByRole('row');
        expect(rows[1]).toHaveTextContent('Alpha renamed3');
        expect(rows[2]).toHaveTextContent('Beta');
        expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    });

    it('cancels a datacenter rename without sending a request', async () => {
        const user = userEvent.setup();
        renderSettings({ datacenters: [{ id: 8, name: 'Original', resources_count: 1 }] });
        await user.click(sectionTrigger(/^Datacenters/));
        await user.click(screen.getByRole('button', { name: 'Rename Original' }));
        await user.type(screen.getByRole('textbox', { name: 'Datacenter name for Original' }), ' changed');
        await user.click(within(section('datacenters')).getByRole('button', { name: 'Cancel' }));

        expect(screen.getByRole('button', { name: 'Rename Original' })).toBeInTheDocument();
        expect(axiosMocks.patch).not.toHaveBeenCalled();
    });

    it('keeps the rename draft and shows a name conflict returned by the API', async () => {
        const user = userEvent.setup();
        axiosMocks.patch.mockRejectedValue({ response: { data: { errors: { name: ['This datacenter name is already in use.'] } } } });
        renderSettings({ datacenters: [{ id: 8, name: 'Original', resources_count: 1 }] });
        await user.click(sectionTrigger(/^Datacenters/));
        await user.click(screen.getByRole('button', { name: 'Rename Original' }));
        const input = screen.getByRole('textbox', { name: 'Datacenter name for Original' });
        await user.clear(input);
        await user.type(input, 'Reserved');
        await user.click(within(section('datacenters')).getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('This datacenter name is already in use.')).toBeVisible();
        expect(screen.getByRole('alert')).toHaveTextContent('This datacenter name is already in use.');
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(input).toHaveAccessibleDescription('This datacenter name is already in use.');
        expect(input).toHaveValue('Reserved');
        expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    });

    it('rejects an empty rename before calling the API', async () => {
        const user = userEvent.setup();
        renderSettings({ datacenters: [{ id: 8, name: 'Original', resources_count: 1 }] });
        await user.click(sectionTrigger(/^Datacenters/));
        await user.click(screen.getByRole('button', { name: 'Rename Original' }));
        const input = screen.getByRole('textbox', { name: 'Datacenter name for Original' });
        await user.clear(input);
        await user.click(within(section('datacenters')).getByRole('button', { name: 'Save' }));

        expect(screen.getByText('Enter a datacenter name.')).toBeVisible();
        expect(screen.getByRole('alert')).toHaveTextContent('Enter a datacenter name.');
        expect(input).toHaveAccessibleDescription('Enter a datacenter name.');
        fireEvent.change(input, { target: { value: 'x'.repeat(256) } });
        await user.click(within(section('datacenters')).getByRole('button', { name: 'Save' }));
        expect(screen.getByText('Datacenter names must be at most 255 characters.')).toBeVisible();
        expect(input).toHaveAccessibleDescription('Datacenter names must be at most 255 characters.');
        expect(axiosMocks.patch).not.toHaveBeenCalled();
    });

    it('uses the loading button state while a datacenter rename is pending', async () => {
        const user = userEvent.setup();
        let resolveRename: (value: {
            data: { datacenter: { id: number; name: string; resources_count: number }; message: string };
        }) => void = () => {};
        axiosMocks.patch.mockImplementation(
            () =>
                new Promise((resolve) => {
                    resolveRename = resolve;
                }),
        );
        renderSettings({ datacenters: [{ id: 8, name: 'Original', resources_count: 1 }] });
        await user.click(sectionTrigger(/^Datacenters/));
        await user.click(screen.getByRole('button', { name: 'Rename Original' }));
        await user.type(screen.getByRole('textbox', { name: 'Datacenter name for Original' }), ' renamed');
        await user.click(within(section('datacenters')).getByRole('button', { name: 'Save' }));

        const save = within(section('datacenters')).getByRole('button', { name: 'Save' });
        expect(save).toHaveAttribute('aria-busy', 'true');
        expect(save).toBeDisabled();
        expect(save).toHaveAttribute('data-slot', 'loading-button');

        resolveRename({ data: { datacenter: { id: 8, name: 'Original renamed', resources_count: 1 }, message: 'Datacenter renamed successfully.' } });
        await waitFor(() => expect(screen.getByRole('button', { name: 'Rename Original renamed' })).toBeInTheDocument());
    });

    it('initializes the form with complete backend values and enforces Abstract as active', () => {
        renderSettings({
            descriptionTypes: [
                { id: 1, name: 'Abstract', slug: 'Abstract', active: false, elmo_active: false, elmo_msl_active: false },
                { id: 2, name: 'Methods', slug: 'Methods', active: false, elmo_active: true, elmo_msl_active: false },
            ],
        });

        expect(formHarness.initialData).toEqual(
            expect.objectContaining({
                resourceTypes: defaultProps.resourceTypes,
                licenses: defaultProps.licenses,
                thesauri: defaultThesauri.map(({ type, isActive, isElmoActive, isElmoMslActive }) => ({
                    type,
                    isActive,
                    isElmoActive,
                    isElmoMslActive,
                })),
                descriptionTypes: [
                    { id: 1, name: 'Abstract', slug: 'Abstract', active: true, elmo_active: true, elmo_msl_active: true },
                    { id: 2, name: 'Methods', slug: 'Methods', active: false, elmo_active: true, elmo_msl_active: false },
                ],
            }),
        );
    });
});
