import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import Docs from '@/pages/docs';
import type { UserRole } from '@/types';
import type { EditorSettings } from '@/types/docs';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

// Mock IntersectionObserver for scroll spy
global.IntersectionObserver = class IntersectionObserver {
    observe() {}
    disconnect() {}
    unobserve() {}
    takeRecords() {
        return [];
    }
    root = null;
    rootMargin = '';
    thresholds = [];
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
} as any;

Object.defineProperty(window, 'scrollTo', {
    configurable: true,
    writable: true,
    value: vi.fn(),
});

// Default editor settings for tests
const defaultEditorSettings: EditorSettings = {
    thesauri: {
        scienceKeywords: true,
        platforms: true,
        instruments: true,
        chronostratigraphy: true,
        gemet: true,
        analyticalMethods: true,
        euroSciVoc: true,
    },
    features: {
        hasActiveGcmd: true,
        hasActiveMsl: true,
        hasActiveChronostrat: true,
        hasActiveGemet: true,
        hasActiveAnalyticalMethods: true,
        hasActiveEuroSciVoc: true,
        hasActiveLicenses: true,
        hasActiveResourceTypes: true,
        hasActiveTitleTypes: true,
        hasActiveLanguages: true,
    },
    limits: {
        maxTitles: 10,
        maxLicenses: 5,
    },
};

type EditorSettingsOverrides = {
    thesauri?: Partial<EditorSettings['thesauri']>;
    features?: Partial<EditorSettings['features']>;
    limits?: Partial<EditorSettings['limits']>;
};

const createEditorSettings = (overrides: EditorSettingsOverrides = {}): EditorSettings => ({
    thesauri: {
        ...defaultEditorSettings.thesauri,
        ...overrides.thesauri,
    },
    features: {
        ...defaultEditorSettings.features,
        ...overrides.features,
    },
    limits: {
        ...defaultEditorSettings.limits,
        ...overrides.limits,
    },
});

const renderDocsPage = (userRole: UserRole, editorSettings: EditorSettings = defaultEditorSettings) => {
    const user = userEvent.setup();

    render(<Docs userRole={userRole} editorSettings={editorSettings} />);

    return { user };
};

const openDatasetsTab = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(screen.getByRole('tab', { name: /Datasets/i }));

    expect(screen.getByText('Uploading DataCite Files')).toBeInTheDocument();
};

const openPhysicalSamplesTab = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(screen.getByRole('tab', { name: /Physical Samples/i }));

    expect(screen.getByText('What is IGSN?')).toBeInTheDocument();
};

describe('Docs page', () => {
    it('renders documentation for beginner role', () => {
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} />);
        // Check for sections visible in Getting Started tab (default)
        expect(screen.getAllByText('Welcome').length).toBeGreaterThan(0);
        expect(screen.getAllByText('API Documentation').length).toBeGreaterThan(0);
    });

    it('hides user management section for beginners', () => {
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} />);
        // User Management should not be visible for beginners
        expect(screen.queryByText('Managing Users')).not.toBeInTheDocument();
    });

    it('shows user management for group_leader', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} />);
        expect(screen.getAllByText('User Management').length).toBeGreaterThan(0);
    });

    it('hides system administration for group_leader', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} />);
        // System Administration requires admin role
        expect(screen.queryByText('php artisan add-user')).not.toBeInTheDocument();
    });

    it('shows all sections for admin', () => {
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} />);
        expect(screen.getAllByText('Welcome').length).toBeGreaterThan(0);
        expect(screen.getAllByText('User Management').length).toBeGreaterThan(0);
        expect(screen.getAllByText('System Administration').length).toBeGreaterThan(0);
        expect(screen.getAllByText('API Documentation').length).toBeGreaterThan(0);
    });

    it('shows assistance documentation for group leaders', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} />);
        expect(screen.getByText('Metadata Enrichment Assistance')).toBeInTheDocument();
    });

    it('hides assistance documentation for curators', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        expect(screen.queryByText('Metadata Enrichment Assistance')).not.toBeInTheDocument();
    });

    it('mentions the assessment FAIR sidebar summary for administrators', () => {
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} />);

        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('Assessment entry also shows the current average FAIR score summary in the format Resources / IGSNs');
            }),
        ).toBeInTheDocument();
    });

    it('documents the admin and group leader workspace switcher', () => {
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} />);

        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('Admins and Group Leaders now see a Curation / Administration switcher at the top of the sidebar.');
            }),
        ).toBeInTheDocument();
    });

    it('describes the current authenticated header navigation behavior', () => {
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} />);

        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('The authenticated page header keeps the main navigation visible and provides quick access to Editor Settings, the changelog, the user documentation, the API documentation, and the user menu.');
            }),
        ).toBeInTheDocument();
    });

    it('displays beginner role indicator in header', () => {
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} />);
        // The header shows the user's role (may appear multiple times)
        expect(screen.getAllByText('beginner').length).toBeGreaterThan(0);
    });

    it('does not show beginner notice for curator role', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        // Curator role should be shown (may appear multiple times)
        expect(screen.getAllByText('curator').length).toBeGreaterThan(0);
    });

    it('links to API documentation', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        const link = screen.getByText('View API Documentation');
        expect(link).toHaveAttribute('href', '/api/v1/doc');
    });

    it('mentions the OpenAPI 3.2 API documentation', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);

        expect(screen.getByText(/OpenAPI 3\.2 specifications/i)).toBeInTheDocument();
        expect(screen.getByText(/validated with Redocly/i)).toBeInTheDocument();
    });

    it('documents the current metadata schema and legacy ELMO envelope format', async () => {
        const { user } = renderDocsPage('beginner');

        expect(screen.getByText(/DataCite v4\.7 metadata editor/i)).toBeInTheDocument();

        await openDatasetsTab(user);

        expect(screen.getByText(/legacy DataCite 4\.6 \+ ISO envelope format/i)).toBeInTheDocument();
        expect(screen.getByText(/DataCite Metadata Schema 4\.7/i)).toBeInTheDocument();
    });

    it('documents opening resources from the resources table row', async () => {
        const { user } = renderDocsPage('beginner');

        await openDatasetsTab(user);

        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('Click anywhere else on a resource row to open that resource in the Data Editor in a new browser tab.');
            }),
        ).toBeInTheDocument();
    });

    it('hides controlled keywords section when all vocabulary families are disabled', async () => {
        const { user } = renderDocsPage(
            'beginner',
            createEditorSettings({
                features: {
                    hasActiveGcmd: false,
                    hasActiveMsl: false,
                    hasActiveChronostrat: false,
                    hasActiveGemet: false,
                    hasActiveAnalyticalMethods: false,
                    hasActiveEuroSciVoc: false,
                },
            }),
        );

        await openDatasetsTab(user);

        expect(screen.queryByText('Controlled Vocabularies')).not.toBeInTheDocument();
    });

    it('shows only the enabled controlled vocabulary families', async () => {
        const { user } = renderDocsPage(
            'beginner',
            createEditorSettings({
                thesauri: {
                    scienceKeywords: false,
                    platforms: false,
                    instruments: false,
                    chronostratigraphy: false,
                    gemet: false,
                    analyticalMethods: false,
                    euroSciVoc: true,
                },
                features: {
                    hasActiveGcmd: false,
                    hasActiveMsl: false,
                    hasActiveChronostrat: false,
                    hasActiveGemet: false,
                    hasActiveAnalyticalMethods: false,
                    hasActiveEuroSciVoc: true,
                },
            }),
        );

        await openDatasetsTab(user);

        expect(screen.getByText('Controlled Vocabularies')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: /European Science Vocabulary \(EuroSciVoc\)/i })).toBeInTheDocument();
        expect(screen.queryByText('NASA GCMD Keywords')).not.toBeInTheDocument();
    });

    it('shows editor settings for group_leader', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} />);
        // 'Editor Configuration' is the unique h3 inside the Editor Settings section
        expect(screen.getByText('Editor Configuration')).toBeInTheDocument();
    });

    it('hides editor settings for curator', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        // Editor Configuration is the h3 inside the Editor Settings section
        expect(screen.queryByText('Editor Configuration')).not.toBeInTheDocument();
    });

    it('hides legacy import for curator', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        // Switch to Datasets tab where Legacy Import lives
        const datasetsTab = screen.getByRole('tab', { name: /Datasets/i });
        await user.click(datasetsTab);
        // Verify tab switched by checking Datasets-only content is rendered
        expect(screen.getByText('Uploading DataCite Files')).toBeInTheDocument();
        // Legacy Import requires admin role
        expect(screen.queryByText('Importing from Old Datasets')).not.toBeInTheDocument();
    });

    it('shows legacy import for admin', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} />);
        // Switch to Datasets tab
        const datasetsTab = screen.getByRole('tab', { name: /Datasets/i });
        await user.click(datasetsTab);
        // Verify tab switched and admin sees Legacy Import
        expect(screen.getByText('Uploading DataCite Files')).toBeInTheDocument();
        expect(screen.getByText('Importing from Old Datasets')).toBeInTheDocument();
    });

    it('hides landing pages documentation for beginner', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} />);
        // Switch to Datasets tab where Landing Pages lives
        const datasetsTab = screen.getByRole('tab', { name: /Datasets/i });
        await user.click(datasetsTab);
        // Verify tab switched by checking Datasets-only content is rendered
        expect(screen.getByText('Uploading DataCite Files')).toBeInTheDocument();
        // Landing Pages requires curator role
        expect(screen.queryByText('Creating Landing Pages')).not.toBeInTheDocument();
    });

    it('shows landing pages documentation for curator', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);
        // Switch to Datasets tab
        const datasetsTab = screen.getByRole('tab', { name: /Datasets/i });
        await user.click(datasetsTab);
        // Verify tab switched and curator sees Landing Pages
        expect(screen.getByText('Uploading DataCite Files')).toBeInTheDocument();
        expect(screen.getByText('Creating Landing Pages')).toBeInTheDocument();
    });

    it('documents the landing page preview action in the Data Editor', async () => {
        const { user } = renderDocsPage('curator');

        await openDatasetsTab(user);

        expect(screen.getAllByText('Show LP Preview').length).toBeGreaterThan(0);
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('From the Data Editor, click Show LP Preview next to Save Draft and Save & Validate') &&
                    text.includes('automatically opens the preview after you create it.')
                );
            }),
        ).toBeInTheDocument();
    });

    it('documents resource quick actions and grouped delete behavior for curators', async () => {
        const { user } = renderDocsPage('curator');

        await openDatasetsTab(user);

        expect(screen.getByText('Quick Resource Actions')).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('Edit and Set up landing page appear as quick actions directly in the selection toolbar.');
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Delete Selected Resources (Curator and above)')).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('Published resources are listed as protected and are never sent to the delete endpoint.');
            }),
        ).toBeInTheDocument();
    });

    it('hides resource delete documentation for beginners', async () => {
        const { user } = renderDocsPage('beginner');

        await openDatasetsTab(user);

        expect(screen.getByText('Quick Resource Actions')).toBeInTheDocument();
        expect(screen.queryByText('Delete Selected Resources (Curator and above)')).not.toBeInTheDocument();
    });

    it('shows landing page templates for group leaders', async () => {
        const groupLeaderPage = renderDocsPage('group_leader');
        await openDatasetsTab(groupLeaderPage.user);
        expect(screen.getByText('Custom Landing Page Templates')).toBeInTheDocument();
    });

    it('hides landing page templates for curators', async () => {
        const curatorPage = renderDocsPage('curator');
        await openDatasetsTab(curatorPage.user);
        expect(screen.queryByText('Custom Landing Page Templates')).not.toBeInTheDocument();
    });

    it('shows related item manager documentation for beginners', async () => {
        const { user } = renderDocsPage('beginner');

        await openDatasetsTab(user);

        expect(screen.getByText(/Related Items \(DataCite 4\.7/i)).toBeInTheDocument();
        expect(screen.getByText(/You can open this workflow anywhere ERNIE lets you edit a resource\./i)).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('Related items appear in the Related Work section under the Citations heading, labelled with an Inline metadata badge.');
            }),
        ).toBeInTheDocument();
    });

    it('hides resource types documentation when no resource types are active', async () => {
        const { user } = renderDocsPage(
            'beginner',
            createEditorSettings({
                features: {
                    hasActiveResourceTypes: false,
                },
            }),
        );

        await openDatasetsTab(user);

        expect(screen.queryByText('Selecting Resource Types')).not.toBeInTheDocument();
    });

    it('hides licenses documentation when no licenses are active', async () => {
        const { user } = renderDocsPage(
            'beginner',
            createEditorSettings({
                features: {
                    hasActiveLicenses: false,
                },
            }),
        );

        await openDatasetsTab(user);

        expect(screen.queryByText('Assigning Licenses')).not.toBeInTheDocument();
    });

    it('hides optional title type examples when title types are disabled', async () => {
        const { user } = renderDocsPage(
            'beginner',
            createEditorSettings({
                features: {
                    hasActiveTitleTypes: false,
                },
            }),
        );

        await openDatasetsTab(user);

        expect(screen.getByText('Main Title')).toBeInTheDocument();
        expect(screen.queryByText('Alternative Title')).not.toBeInTheDocument();
        expect(screen.queryByText('Subtitle')).not.toBeInTheDocument();
        expect(screen.queryByText('Translated Title')).not.toBeInTheDocument();
    });

    it('documents the update metadata DOI action label in the ORCID pre-flight section', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} />);

        await user.click(screen.getByRole('tab', { name: /Datasets/i }));

        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('when you press Register DOI or Update metadata.');
            }),
        ).toBeInTheDocument();
    });

    it('shows the beginner note for test DOI registration only', async () => {
        const { user } = renderDocsPage('beginner');

        await openDatasetsTab(user);

        expect(screen.getByText('Beginners can only register test DOIs.')).toBeInTheDocument();
    });

    it('documents the current schema version for IGSN exports', async () => {
        const { user } = renderDocsPage('beginner');

        await openPhysicalSamplesTab(user);

        expect(screen.getByText(/DataCite Schema 4\.7 before download/i)).toBeInTheDocument();
    });

    it('shows IGSN administration for admins', async () => {
        const adminPage = renderDocsPage('admin');
        await openPhysicalSamplesTab(adminPage.user);
        expect(screen.getByText('Bulk Delete')).toBeInTheDocument();
    });

    it('hides IGSN administration for group leaders', async () => {
        const groupLeaderPage = renderDocsPage('group_leader');
        await openPhysicalSamplesTab(groupLeaderPage.user);
        expect(screen.queryByText('Bulk Delete')).not.toBeInTheDocument();
    });

    it('shows thesaurus update actions for admin in editor settings', () => {
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} />);
        expect(screen.getByText('Check for updates by comparing local vs. remote counts')).toBeInTheDocument();
        expect(screen.getByText('Trigger vocabulary updates with one click')).toBeInTheDocument();
        expect(screen.getByText('Trigger background downloads of the full vocabulary data')).toBeInTheDocument();
    });

    it('shows thesaurus update actions for group_leader in editor settings', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} />);
        expect(screen.getByText('Check for updates by comparing local vs. remote counts')).toBeInTheDocument();
        expect(screen.getByText('Trigger vocabulary updates with one click')).toBeInTheDocument();
        expect(screen.getByText('Trigger background downloads of the full vocabulary data')).toBeInTheDocument();
    });
});
