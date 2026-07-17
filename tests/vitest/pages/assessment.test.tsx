import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import Assessment, { AssessmentTable } from '@/pages/assessment';
import { type AssessmentEntry, type AssessmentSummary } from '@/types/assessment';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: {
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
        scopeNote: 'F-UJI also counts digital-data checks in this dimension. ERNIE does not present those checks as actions for a physical sample.',
    },
};

describe('Assessment FAIR opportunity integration', () => {
    it('places the FAIR opportunity column between title and score', () => {
        render(<AssessmentTable entries={[resourceEntry]} summary={summary} scope="resource" />);

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
});
