import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import Assessment, { AssessmentTable } from '@/pages/assessment';
import { type AssessmentEntry, type AssessmentSummary } from '@/types/assessment';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: React.AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: {
        get: vi.fn(),
        reload: vi.fn(),
    },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const summary: AssessmentSummary = {
    total: 1,
    assessed: 1,
    failed: 0,
    skipped: 0,
    unassessed: 0,
};

const resourceEntry: AssessmentEntry = {
    id: 1,
    doi: '10.5880/example.resource',
    mainTitle: 'Digital test resource',
    score: 42.31,
    assessedAt: '2026-07-17T10:00:00Z',
    hasPendingSuggestions: false,
    improvementOpportunity: {
        status: 'available',
        dimension: 'R',
        dimensionLabel: 'Reusability',
        missingPoints: 4,
        totalPoints: 6,
        potentialFairGain: 15.38,
        severity: 'very-high',
        requiresReassessment: false,
        suggestions: [
            {
                key: 'license',
                actor: 'curator',
                text: 'Add a licence in ERNIE and publish it with the digital resource metadata.',
            },
        ],
    },
};

const igsnEntry: AssessmentEntry = {
    id: 2,
    doi: '10.60510/GFZ.TEST',
    mainTitle: 'Physical test sample',
    score: 53.85,
    assessedAt: '2026-07-17T10:00:00Z',
    hasPendingSuggestions: false,
    improvementOpportunity: {
        status: 'available',
        dimension: 'F',
        dimensionLabel: 'Findability',
        missingPoints: 3,
        totalPoints: 7,
        potentialFairGain: 11.54,
        severity: 'high',
        requiresReassessment: false,
        suggestions: [
            {
                key: 'igsn-registration',
                actor: 'curator',
                text: 'Register the IGSN with DataCite and point it to a published ERNIE sample landing page.',
            },
        ],
        scopeNote:
            'This dimension also includes checks for downloadable digital data. ERNIE does not present those checks as actions for a physical sample.',
    },
};

describe('Assessment FAIR opportunity integration', () => {
    it.each([
        ['Resource', 'resource', resourceEntry],
        ['IGSN', 'igsn', igsnEntry],
    ] as const)('links the %s DOI to its resolver in a new tab on desktop and mobile', (_label, scope, entry) => {
        render(
            <AssessmentTable entries={[entry]} summary={summary} scope={scope} canRunAssessments canAccessAssistance showImprovementActorLabels />,
        );

        const doi = entry.doi;

        if (doi === null) {
            throw new Error('The DOI link test requires an entry with a DOI.');
        }

        const doiLinks = screen.getAllByRole('link', { name: doi });

        expect(doiLinks).toHaveLength(2);
        for (const link of doiLinks) {
            expect(link).toHaveAttribute('href', `https://doi.org/${doi}`);
            expect(link).toHaveAttribute('target', '_blank');
            expect(link).toHaveAttribute('rel', 'noopener noreferrer');
            expect(link).toHaveAttribute('title', doi);
        }
    });

    it('keeps question marks and hashes inside the DOI resolver path', () => {
        const doi = '10.1234/test?query=value#section';

        render(
            <AssessmentTable
                entries={[{ ...resourceEntry, doi }]}
                summary={summary}
                scope="resource"
                canRunAssessments
                canAccessAssistance
                showImprovementActorLabels
            />,
        );

        const doiLinks = screen.getAllByRole('link', { name: doi });

        expect(doiLinks).toHaveLength(2);
        for (const link of doiLinks) {
            expect(link).toHaveAttribute('href', 'https://doi.org/10.1234/test%3Fquery=value%23section');
        }
    });

    it('keeps missing desktop and mobile DOIs as unlinked N/A text', () => {
        render(
            <AssessmentTable
                entries={[{ ...resourceEntry, doi: null }]}
                summary={summary}
                scope="resource"
                canRunAssessments
                canAccessAssistance
                showImprovementActorLabels
            />,
        );

        expect(screen.getAllByText('N/A')).toHaveLength(2);
        expect(screen.queryByRole('link', { name: 'N/A' })).not.toBeInTheDocument();
    });

    it('places the FAIR opportunity column between title and score', () => {
        render(
            <AssessmentTable
                entries={[resourceEntry]}
                summary={summary}
                scope="resource"
                canRunAssessments
                canAccessAssistance
                showImprovementActorLabels
            />,
        );

        const headers = screen.getAllByRole('columnheader').map((header) => header.textContent);

        expect(headers).toEqual(['DOI', 'Main Title', 'FAIR opportunity', 'Score']);
        expect(screen.getByText('42.31%')).toBeInTheDocument();
    });

    it('renders an opportunity indicator in both Resource and IGSN tables', () => {
        render(
            <Assessment
                fujiConfigured
                fujiHealthy
                fujiStatusMessage={null}
                fujiStatusCode={200}
                canRunAssessments
                canAccessAssistance
                showImprovementActorLabels
                includeExternalResources={false}
                includeDraftReviewResources={false}
                resourcesNeedingAttention={[resourceEntry]}
                igsnsNeedingAttention={[igsnEntry]}
                resourceAssessmentSummary={summary}
                igsnAssessmentSummary={summary}
            />,
        );

        expect(screen.getByRole('heading', { name: 'Resources needing your attention' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'IGSNs needing your attention' })).toBeInTheDocument();
        expect(screen.getAllByRole('columnheader', { name: 'FAIR opportunity' })).toHaveLength(2);
        expect(screen.getByRole('button', { name: /Reusability: very high FAIR improvement potential/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Findability: high FAIR improvement potential/ })).toBeInTheDocument();
    });

    it('shows actor labels only when requested for an administrator', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        const commonProps = {
            fujiConfigured: true,
            fujiHealthy: true,
            fujiStatusMessage: null,
            fujiStatusCode: 200,
            canRunAssessments: true,
            canAccessAssistance: true,
            includeExternalResources: false,
            includeDraftReviewResources: false,
            resourcesNeedingAttention: [resourceEntry],
            igsnsNeedingAttention: [],
            resourceAssessmentSummary: summary,
            igsnAssessmentSummary: summary,
        };

        const view = render(<Assessment {...commonProps} showImprovementActorLabels={false} />);

        await user.hover(screen.getByRole('button', { name: /Reusability: very high FAIR improvement potential/ }));
        expect(await screen.findByRole('tooltip')).not.toHaveTextContent('Curator action');

        view.unmount();
        render(<Assessment {...commonProps} showImprovementActorLabels />);

        await user.hover(screen.getByRole('button', { name: /Reusability: very high FAIR improvement potential/ }));
        expect(await screen.findByRole('tooltip')).toHaveTextContent('Curator action');
    });
});
