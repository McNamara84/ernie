import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import Docs from '@/pages/docs';
import type { UserRole } from '@/types';
import type { DataCiteDocsSettings, EditorSettings } from '@/types/docs';

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
        simpleLithology: true,
    },
    features: {
        hasActiveGcmd: true,
        hasActiveMsl: true,
        hasActiveChronostrat: true,
        hasActiveGemet: true,
        hasActiveAnalyticalMethods: true,
        hasActiveEuroSciVoc: true,
        hasActiveSimpleLithology: true,
        hasActiveLicenses: true,
        hasActiveResourceTypes: true,
        hasActiveTitleTypes: true,
        hasActiveLanguages: true,
    },
};

const defaultDataCite: DataCiteDocsSettings = {
    currentMode: 'test',
    isTestModeForcedForUser: false,
    testPrefixes: ['10.83279', '10.83186', '10.83114'],
    productionPrefixes: ['10.5880', '10.1594', '10.14470'],
    testEndpoint: 'https://api.test.datacite.org',
    productionEndpoint: 'https://api.datacite.org',
};
type EditorSettingsOverrides = {
    thesauri?: Partial<EditorSettings['thesauri']>;
    features?: Partial<EditorSettings['features']>;
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
});

const renderDocsPage = (
    userRole: UserRole,
    editorSettings: EditorSettings = defaultEditorSettings,
    dataCite: DataCiteDocsSettings = defaultDataCite,
) => {
    const user = userEvent.setup();

    render(<Docs userRole={userRole} editorSettings={editorSettings} dataCite={dataCite} />);

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
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        // Check for sections visible in Getting Started tab (default)
        expect(screen.getAllByText('Welcome').length).toBeGreaterThan(0);
        expect(screen.getAllByText('API Documentation').length).toBeGreaterThan(0);
    });

    it('hides user management section for beginners', () => {
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        // User Management should not be visible for beginners
        expect(screen.queryByText('Managing Users')).not.toBeInTheDocument();
    });

    it('shows user management for group_leader', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        expect(screen.getAllByText('User Management').length).toBeGreaterThan(0);
    });

    it('hides system administration for group_leader', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        // System Administration requires admin role
        expect(screen.queryByText('php artisan add-user')).not.toBeInTheDocument();
    });

    it('shows all sections for admin', () => {
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        expect(screen.getAllByText('Welcome').length).toBeGreaterThan(0);
        expect(screen.getAllByText('User Management').length).toBeGreaterThan(0);
        expect(screen.getAllByText('System Administration').length).toBeGreaterThan(0);
        expect(screen.getAllByText('API Documentation').length).toBeGreaterThan(0);
    });

    it('documents the legacy IGSN Handle audit command only for admins', () => {
        const { unmount } = render(<Docs userRole="admin" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.getByText('php artisan igsn:audit-legacy-handles')).toBeInTheDocument();
        expect(screen.getByText(/--batch=20 --output=\/path\/to\/report\.json/)).toBeInTheDocument();
        expect(
            screen.getByText(/missing Handles, transient or unknown responses, and report write errors produce a failure exit code/i),
        ).toBeInTheDocument();

        unmount();
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.queryByText('php artisan igsn:audit-legacy-handles')).not.toBeInTheDocument();
    });

    it('shows the complete DataCite landing-page URL migration workflow only to admins', async () => {
        const { user } = renderDocsPage('admin');
        await openDatasetsTab(user);

        expect(screen.getAllByText('DataCite Landing-Page URL Migration').length).toBeGreaterThan(0);
        expect(screen.getByRole('heading', { name: 'Preview the migration' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Start after confirmation' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Monitor progress' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Request cancellation' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Resume a paused or cancelled run' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Retry failed items' })).toBeInTheDocument();
    });

    it('hides the DataCite landing-page URL migration workflow from non-admins', async () => {
        const groupLeader = renderDocsPage('group_leader');
        await openDatasetsTab(groupLeader.user);
        expect(screen.queryByText('Preview the migration')).not.toBeInTheDocument();
    });

    it('shows assistance documentation for group leaders', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        expect(screen.getByText('Metadata Enrichment Assistance')).toBeInTheDocument();
    });

    it('documents DOI, Datacenter, and indirect Assistance filtering for group leaders', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.getByText('Filter Pending Suggestions')).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') return false;

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('The Datacenter dropdown lists only datacenters that have resources affected');
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') return false;

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes("Such results remain grouped under the suggestion's origin resource and are marked Indirect match");
            }),
        ).toBeInTheDocument();
    });

    it('documents description segmentation suggestions for group leaders', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.getByText('Description Segmentation Suggestions')).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('Description Segmentation suggestions show the current Abstract beside the proposed remaining Abstract') &&
                    text.includes('stale suggestions are rejected if the source Abstract changed after discovery.')
                );
            }),
        ).toBeInTheDocument();
    });

    it('documents description language suggestion review for group leaders', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.getByText('Suggested Description Languages')).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('Description language suggestions show the Description Type, a text preview') &&
                    text.includes('run the check again to review refreshed evidence.')
                );
            }),
        ).toBeInTheDocument();
    });
    it('documents exact-match bulk acceptance for ROR affiliation suggestions', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('Suggested ROR-ID affiliation matches are exact.') &&
                    text.includes('with the same exported creatorName, affiliation, and proposed ROR identifier') &&
                    text.includes('creator name identifiers and affiliation labels stay unchanged.')
                );
            }),
        ).toBeInTheDocument();
    });

    it('hides assistance documentation for curators', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        expect(screen.queryByText('Metadata Enrichment Assistance')).not.toBeInTheDocument();
    });

    it('mentions the assessment FAIR sidebar summary for all assessment roles', () => {
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('For Admins, Group Leaders, and Curators') &&
                    text.includes('Assessment entry also shows the current average FAIR score summary in the format Resources / IGSNs')
                );
            }),
        ).toBeInTheDocument();
    });

    it('documents score-causal FAIR opportunity guidance for Resources and physical-sample IGSNs', () => {
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.getByText('Improvement Guidance')).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('Admins see both Curator actions and ERNIE administrator actions') &&
                    text.includes('without a redundant actor label') &&
                    text.includes('administrator-only guidance is not sent to them')
                );
            }),
        ).toBeInTheDocument();
        expect(screen.getByText(/Resource and IGSN rankings are always displayed as separate cards, one below the other/)).toBeInTheDocument();
        expect(document.body).not.toHaveTextContent('F-UJI');
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('physical-sample IGSNs use separate guidance') &&
                    text.includes('instead of asking you to add digital-file metadata')
                );
            }),
        ).toBeInTheDocument();
    });

    it('shows curators the read-only Assessment documentation and role-appropriate navigation', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.getByText('FAIR Assessment Dashboard')).toBeInTheDocument();
        expect(screen.getByText('Review current results')).toBeInTheDocument();
        expect(screen.getByText(/Curators have read-only access to the latest results/)).toBeInTheDocument();
        expect(screen.queryByText('Start a check')).not.toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('Curators reach it from the normal Tools section');
            }),
        ).toBeInTheDocument();
    });

    it('documents persistent IGSN datacenter filtering for all physical-sample users', async () => {
        const { user } = renderDocsPage('beginner');
        await openPhysicalSamplesTab(user);

        expect(screen.getByRole('heading', { name: 'Filtering by Datacenter', level: 4 })).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') return false;

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('dropdown lists only Datacenters assigned to at least one IGSN') &&
                    text.includes('Without Datacenter') &&
                    text.includes('search, IGSN prefix, upload status, sorting, and pagination')
                );
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') return false;

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('remembers the selected Datacenter or Without Datacenter in this browser') &&
                    text.includes('A Datacenter supplied in the URL takes precedence') &&
                    text.includes('clearing all filters removes the saved selection')
                );
            }),
        ).toBeInTheDocument();
    });

    it('documents composable DOI and Datacenter Assessment filters for curators', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.getByText('Filter Assessment Results')).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') return false;

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('The Datacenter dropdown lists only datacenters represented by stored assessment results');
            }),
        ).toBeInTheDocument();
        expect(screen.getByText(/starting a check still assesses its full selected scope/)).toBeInTheDocument();
    });

    it('shows group leaders the Assessment run workflow and documents the default external-resource filter', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.getByText('Start a check')).toBeInTheDocument();
        expect(screen.getByText(/These actions are available only to Admins and Group Leaders/)).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('external landing page are excluded from the Resource ranking by default') &&
                    text.includes('applies this filter before it selects the 10 lowest scores') &&
                    text.includes('does not change the IGSN ranking')
                );
            }),
        ).toBeInTheDocument();
    });

    it('keeps Assessment documentation hidden from beginners', () => {
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.queryByText('FAIR Assessment Dashboard')).not.toBeInTheDocument();
    });

    it('documents the admin and group leader workspace switcher', () => {
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

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
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('On screens narrower than 768px, the complete authenticated page header stays at the top while you scroll') &&
                    text.includes('the button that opens the main sidebar remains available on long pages') &&
                    text.includes('At 768px and above, the left sidebar remains available and the header continues to scroll normally')
                );
            }),
        ).toBeInTheDocument();
    });

    it('displays beginner role indicator in header', () => {
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        // The header shows the user's role (may appear multiple times)
        expect(screen.getAllByText('beginner').length).toBeGreaterThan(0);
    });

    it('does not show beginner notice for curator role', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        // Curator role should be shown (may appear multiple times)
        expect(screen.getAllByText('curator').length).toBeGreaterThan(0);
    });

    it('links to API documentation', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        const link = screen.getByText('View API Documentation');
        expect(link).toHaveAttribute('href', '/api/v1/doc');
    });

    it('mentions the OpenAPI 3.2 API documentation', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.getByText(/OpenAPI 3\.2 specifications/i)).toBeInTheDocument();
        expect(screen.getByText(/validated with Redocly/i)).toBeInTheDocument();
    });

    it('documents personal settings through the user menu and route-specific settings pages', () => {
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.getByText(/Open the user menu from your avatar/i)).toBeInTheDocument();
        expect(screen.getAllByText('/settings/profile').length).toBeGreaterThan(0);
        expect(screen.getByText('/settings/password')).toBeInTheDocument();
        expect(screen.getByText('/settings/appearance')).toBeInTheDocument();
    });

    it('documents the current Add User entry point and Create User submit action', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        expect(screen.getByText('"Add User"')).toBeInTheDocument();
        expect(screen.getByText('"Create User"')).toBeInTheDocument();
    });
    it('documents the current metadata schema and legacy ELMO envelope format', async () => {
        const { user } = renderDocsPage('beginner');

        expect(screen.getByText(/DataCite v4\.7 metadata editor/i)).toBeInTheDocument();

        await openDatasetsTab(user);

        expect(screen.getByText(/legacy DataCite 4\.6 \+ ISO envelope format/i)).toBeInTheDocument();
        expect(screen.getByText(/DataCite Metadata Schema 4\.7/i)).toBeInTheDocument();
    });

    it('documents description language versions in the Data Editor', async () => {
        const { user } = renderDocsPage('beginner');

        await openDatasetsTab(user);

        expect(screen.getByText('Managing Description Types and Language Versions')).toBeInTheDocument();
        expect(screen.getByText('Add language version')).toBeInTheDocument();
        expect(screen.getByText('Remove version')).toBeInTheDocument();
        expect(screen.getByText(/Tabs containing validation errors display a red error indicator/i)).toBeInTheDocument();
        expect(screen.getByText(/at least one non-empty Abstract with a maximum of 17,500 characters/i)).toBeInTheDocument();
        expect(screen.queryByText(/Abstract with 50 to 17,500 characters/i)).not.toBeInTheDocument();
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

        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('Whenever exactly one resource is being opened') && text.includes('shows a warning');
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('When multiple resources are selected') &&
                    text.includes('fallback dialog with direct links for only the blocked resources')
                );
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
                    hasActiveSimpleLithology: false,
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
                    simpleLithology: false,
                },
                features: {
                    hasActiveGcmd: false,
                    hasActiveMsl: false,
                    hasActiveChronostrat: false,
                    hasActiveGemet: false,
                    hasActiveAnalyticalMethods: false,
                    hasActiveEuroSciVoc: true,
                    hasActiveSimpleLithology: false,
                },
            }),
        );

        await openDatasetsTab(user);

        expect(screen.getByText('Controlled Vocabularies')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: /European Science Vocabulary \(EuroSciVoc\)/i })).toBeInTheDocument();
        expect(screen.queryByText('NASA GCMD Keywords')).not.toBeInTheDocument();
    });

    it('shows editor settings for group_leader', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        // 'Editor Configuration' is the unique h3 inside the Editor Settings section
        expect(screen.getByText('Editor Configuration')).toBeInTheDocument();
    });

    it('hides editor settings for curator', () => {
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        // Editor Configuration is the h3 inside the Editor Settings section
        expect(screen.queryByText('Editor Configuration')).not.toBeInTheDocument();
    });

    it('hides legacy import for curator', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
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
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        // Switch to Datasets tab
        const datasetsTab = screen.getByRole('tab', { name: /Datasets/i });
        await user.click(datasetsTab);
        // Verify tab switched and admin sees Legacy Import
        expect(screen.getByText('Uploading DataCite Files')).toBeInTheDocument();
        expect(screen.getByText('Importing from Old Datasets')).toBeInTheDocument();
    });

    it('documents the portal and legacy sources used by datacenter imports', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

        await user.click(screen.getByRole('tab', { name: /Datasets/i }));

        expect(screen.getByText('Import all Resources from a Datacenter')).toBeInTheDocument();
        expect(screen.getByText(/uses the portal assignment for visible resources/i)).toBeInTheDocument();
        expect(screen.getByText(/determined from the legacy databases and the established DOI rules/i)).toBeInTheDocument();
        expect(screen.getByText(/not re-imported or overwritten/i)).toBeInTheDocument();
        expect(screen.getByText(/current datacenter assignments are preserved/i)).toBeInTheDocument();
        expect(
            screen.getByText(/may still enrich them with missing legacy download links or an external landing page URL from DataCite/i),
        ).toBeInTheDocument();
    });

    it('documents persistent IGSN page sizing and page controls for all physical-sample users', async () => {
        const { user } = renderDocsPage('beginner');
        await openPhysicalSamplesTab(user);

        expect(screen.getByRole('heading', { name: 'Pagination', level: 4 })).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') return false;

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('display 10, 100, or 1000 IGSNs at a time') &&
                    text.includes('stores this choice only in the current browser') &&
                    text.includes('returns to the first page')
                );
            }),
        ).toBeInTheDocument();
        expect(screen.getByText(/first, previous, next, or last page/i)).toBeInTheDocument();
    });

    it('shows landing pages documentation for beginner training', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="beginner" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        // Switch to Datasets tab where Landing Pages lives
        const datasetsTab = screen.getByRole('tab', { name: /Datasets/i });
        await user.click(datasetsTab);
        // Verify tab switched by checking Datasets-only content is rendered
        expect(screen.getByText('Uploading DataCite Files')).toBeInTheDocument();
        // Beginners can set up landing pages as part of the training workflow
        expect(screen.getByText('Creating Landing Pages')).toBeInTheDocument();
        expect(screen.getByText(/Beginner users can create, edit, preview, and publish landing pages/i)).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Expanding Citation Authors', level: 4 })).toBeInTheDocument();
        expect(screen.getByText(/keyboard focus stays on the control in both states/i)).toBeInTheDocument();
        expect(screen.getByText(/copy action uses whichever compact or expanded citation is currently visible/i)).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Legacy Creator and Contact Consolidation', level: 4 })).toBeInTheDocument();
        expect(screen.getByText(/same stored entity or valid ORCID/i)).toBeInTheDocument();
        expect(screen.getByText(/Ambiguous names and conflicting ORCIDs remain separate/i)).toBeInTheDocument();
        expect(screen.getByText(/Contact messages still use the email and route of the actual contact row/i)).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'License Display', level: 4 })).toBeInTheDocument();
        expect(screen.getByText(/Creative Commons Attribution 4\.0 International \(CC BY 4\.0\)/i)).toBeInTheDocument();
    });

    it('shows landing pages documentation for curator', async () => {
        const user = userEvent.setup();
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
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

        expect(screen.getAllByText('Preview LP').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Show LP').length).toBeGreaterThan(0);
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('The action bar stays available while you move through the form.') &&
                    text.includes('on touch screens it remains visible and compact.')
                );
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('From an unpublished record in the Data Editor, click Preview LP in the bottom-right action bar') &&
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

                return (
                    text.includes('Curators can delete draft, curation, and preview resources.') &&
                    text.includes('Admins and Group Leaders can additionally delete published resources.')
                );
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    text.includes('published resources require an explicit checkbox that is off by default') &&
                    text.includes('DataCite remains unchanged') &&
                    text.includes('Curators continue to see published resources as protected.')
                );
            }),
        ).toBeInTheDocument();
    });

    it('documents datacenter-filter persistence for beginners', async () => {
        const { user } = renderDocsPage('beginner');

        await openDatasetsTab(user);

        expect(screen.getByRole('heading', { name: 'Filtering by Datacenter', level: 4 })).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                const text = element?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    element?.tagName === 'P' &&
                    text.includes('remembers a selected datacenter or Without Datacenter in this browser') &&
                    text.includes('A datacenter supplied in the URL takes precedence') &&
                    text.includes('clearing the datacenter badge or all filters removes the saved selection')
                );
            }),
        ).toBeInTheDocument();
    });

    it('documents both review-link workflows, their recipients, and partial results for curators', async () => {
        const { user } = renderDocsPage('curator');

        await openDatasetsTab(user);

        expect(screen.getByText('Send Review Links (Curator and above)')).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                const text = element?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    element?.tagName === 'P' &&
                    text.includes('every selected resource has the Review status and a usable preview link') &&
                    text.includes('rejected before any email is queued')
                );
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                const text = element?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    element?.tagName === 'P' &&
                    text.includes('Send review link to invite contributors to review resources before publication') &&
                    text.includes('normal invitation workflow') &&
                    text.includes('does not tell recipients that a previous link has changed')
                );
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                const text = element?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    element?.tagName === 'P' &&
                    text.includes('Notify changed review link only for contacts who already received a review link') &&
                    text.includes('This separate action sends the migration notice')
                );
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                const text = element?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    element?.tagName === 'P' &&
                    text.includes('ContactPerson role and a valid email address receives one separate email per selected resource') &&
                    text.includes('using either the invitation or migration notice') &&
                    text.includes('included as Cc and Reply-To on every message')
                );
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, element) => {
                const text = element?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return (
                    element?.tagName === 'P' &&
                    text.includes('Missing or invalid ContactPerson addresses are skipped') &&
                    text.includes('reported as failed while eligible recipients and other resources continue')
                );
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Limit: up to 100 resources per batch and up to 10 send requests per user per minute.')).toBeInTheDocument();
    });

    it('hides resource delete documentation for beginners', async () => {
        const { user } = renderDocsPage('beginner');

        await openDatasetsTab(user);

        expect(screen.getByText('Quick Resource Actions')).toBeInTheDocument();
        expect(screen.getByText(/Beginners do not receive that link in the Resources list/i)).toBeInTheDocument();
        expect(screen.queryByText('Send Review Links (Curator and above)')).not.toBeInTheDocument();
        expect(screen.queryByText('Delete Selected Resources (Curator and above)')).not.toBeInTheDocument();
    });
    it('documents beginner test-only bulk DOI actions', async () => {
        const { user } = renderDocsPage('beginner');

        await openDatasetsTab(user);

        expect(screen.getByText('Bulk Register / Update DOI (all roles, Beginner test-only)')).toBeInTheDocument();
        expect(screen.getByText(/Beginner users can run the same training action/i)).toBeInTheDocument();
    });

    it('shows landing page templates for group leaders', async () => {
        const groupLeaderPage = renderDocsPage('group_leader');
        await openDatasetsTab(groupLeaderPage.user);
        expect(screen.getByText('Custom Landing Page Templates')).toBeInTheDocument();
        expect(screen.getByText('Additional Information')).toBeInTheDocument();
        expect(screen.getByText(/For IGSN templates, every module can also be moved between columns/i)).toBeInTheDocument();
        expect(screen.getByText(/Sample Image displays the locally managed or approved external legacy photo/i)).toBeInTheDocument();
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

                return text.includes(
                    'Related items appear in the Related Work section under the Citations heading, labelled with an Inline metadata badge.',
                );
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
        render(<Docs userRole="curator" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);

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

        expect(screen.getByText(/Beginners always register through the DataCite test API/i)).toBeInTheDocument();
    });

    it('documents DataCite mode, endpoints, prefixes, and the beginner forced-test state', async () => {
        const { user } = renderDocsPage('beginner', defaultEditorSettings, {
            ...defaultDataCite,
            isTestModeForcedForUser: true,
        });

        await openDatasetsTab(user);

        expect(
            screen.getByText((_, element) => {
                if (element?.tagName !== 'P') {
                    return false;
                }

                const text = element.textContent?.replace(/\s+/g, ' ').trim() ?? '';

                return text.includes('Current mode: Test.');
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('https://api.test.datacite.org')).toBeInTheDocument();
        expect(screen.getByText('10.83279, 10.83186, 10.83114')).toBeInTheDocument();
        expect(screen.getByText('https://api.datacite.org')).toBeInTheDocument();
        expect(screen.getByText('10.5880, 10.1594, 10.14470')).toBeInTheDocument();
        expect(screen.getByText(/ERNIE is currently forcing test mode for your account/i)).toBeInTheDocument();
    });

    it('documents the current funding reference editor fields', async () => {
        const { user } = renderDocsPage('beginner');

        await openDatasetsTab(user);

        expect(screen.getByText('Funder Name:')).toBeInTheDocument();
        expect(screen.getByText('Funder Identifier:')).toBeInTheDocument();
        expect(screen.getByText('Show award details:')).toBeInTheDocument();
        expect(screen.getByText('Award/Grant Number:')).toBeInTheDocument();
        expect(screen.getByText('Award URI:')).toBeInTheDocument();
        expect(screen.getByText('Award Title:')).toBeInTheDocument();
    });

    it('documents how to manage description language versions', async () => {
        const { user } = renderDocsPage('beginner');

        await openDatasetsTab(user);

        expect(screen.getByRole('heading', { name: 'Managing Description Types and Language Versions', level: 4 })).toBeInTheDocument();
        expect(screen.getByText(/Each group can contain several language versions/i)).toBeInTheDocument();
        expect(screen.getByText('Add Description Type')).toBeInTheDocument();
        expect(screen.getByText('Add language version')).toBeInTheDocument();
        expect(screen.getByText('No language specified')).toBeInTheDocument();
        expect(screen.getByText('Remove version')).toBeInTheDocument();
        expect(screen.getByText('Series Information:')).toBeInTheDocument();
    });

    it('keeps dataset field documentation close to the editor accordion order', async () => {
        const { user } = renderDocsPage('beginner');

        await openDatasetsTab(user);

        const titles = screen.getByRole('heading', { name: 'Titles', level: 3 });
        const licenses = screen.getByRole('heading', { name: 'Assigning Licenses', level: 3 });
        const authors = screen.getByRole('heading', { name: 'Managing Authors & Contributors', level: 3 });
        const descriptions = screen.getByRole('heading', { name: 'Descriptions', level: 3 });
        const relatedIdentifiers = screen.getByRole('heading', { name: 'Linking Related Resources', level: 3 });
        const relatedItems = screen.getByRole('heading', { name: /Related Items/i, level: 3 });
        const funding = screen.getByRole('heading', { name: 'Acknowledging Funding Sources', level: 3 });
        const portal = screen.getByRole('heading', { name: 'Searching Published Records in the Portal', level: 3 });

        expect(titles.compareDocumentPosition(licenses) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(licenses.compareDocumentPosition(authors) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(authors.compareDocumentPosition(descriptions) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(relatedIdentifiers.compareDocumentPosition(relatedItems) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(relatedItems.compareDocumentPosition(funding) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(funding.compareDocumentPosition(portal) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('documents the current schema version for IGSN exports', async () => {
        const { user } = renderDocsPage('beginner');

        await openPhysicalSamplesTab(user);

        expect(screen.getByText(/DataCite Schema 4\.7 before download/i)).toBeInTheDocument();
    });

    it('documents protected current and original IGSN repository contacts for beginners', async () => {
        const { user } = renderDocsPage('beginner');

        await openPhysicalSamplesTab(user);

        expect(screen.getByRole('heading', { name: 'Contacting an IGSN Repository' })).toBeInTheDocument();
        expect(screen.getByText(/Contact current repository/)).toBeInTheDocument();
        expect(screen.getByText(/Contact original repository/)).toBeInTheDocument();
        expect(screen.getByText(/keeps the repository email address on the server and never exposes it/i)).toBeInTheDocument();
        expect(screen.getByText(/shown only when the corresponding metadata contains a complete, valid email address/i)).toBeInTheDocument();
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
        render(<Docs userRole="admin" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        expect(screen.getByText('Check for updates by comparing local vs. remote counts')).toBeInTheDocument();
        expect(screen.getByText('Trigger vocabulary updates with one click')).toBeInTheDocument();
        expect(screen.getByText('Trigger background downloads of the full vocabulary data')).toBeInTheDocument();
    });

    it('shows thesaurus update actions for group_leader in editor settings', () => {
        render(<Docs userRole="group_leader" editorSettings={defaultEditorSettings} dataCite={defaultDataCite} />);
        expect(screen.getByText('Check for updates by comparing local vs. remote counts')).toBeInTheDocument();
        expect(screen.getByText('Trigger vocabulary updates with one click')).toBeInTheDocument();
        expect(screen.getByText('Trigger background downloads of the full vocabulary data')).toBeInTheDocument();
    });
});
