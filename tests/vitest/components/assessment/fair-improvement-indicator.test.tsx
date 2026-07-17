import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { FairImprovementIndicator } from '@/components/assessment/fair-improvement-indicator';
import { type FairImprovementOpportunity, type FairImprovementSeverity } from '@/types/assessment';

function availableOpportunity(
    overrides: Partial<Extract<FairImprovementOpportunity, { status: 'available' }>> = {},
): Extract<FairImprovementOpportunity, { status: 'available' }> {
    return {
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
            {
                key: 'distribution',
                actor: 'administrator',
                text: 'Expose the configured download as a machine-readable data distribution.',
            },
        ],
        ...overrides,
    };
}

describe('FairImprovementIndicator', () => {
    it.each([
        ['low', 'border-yellow-400'],
        ['medium', 'border-amber-500'],
        ['high', 'border-orange-500'],
        ['very-high', 'border-red-500'],
    ] satisfies Array<[FairImprovementSeverity, string]>)('uses the static %s severity style', (severity, expectedClass) => {
        render(<FairImprovementIndicator opportunity={availableOpportunity({ severity })} />);

        expect(screen.getByRole('button')).toHaveClass(expectedClass);
    });

    it('provides a non-color accessible name with dimension, severity, gap, and overall gain', () => {
        render(<FairImprovementIndicator opportunity={availableOpportunity()} />);

        expect(
            screen.getByRole('button', {
                name: 'Reusability: very high FAIR improvement potential; 4 of 6 F-UJI points are available, worth up to 15.38 overall percentage points.',
            }),
        ).toBeInTheDocument();
    });

    it('formats fractional point gaps without losing precision', () => {
        render(<FairImprovementIndicator opportunity={availableOpportunity({ missingPoints: 1.5, totalPoints: 6.5, potentialFairGain: 5 })} />);

        expect(screen.getByRole('button')).toHaveAccessibleName(/1\.50 of 6\.50 F-UJI points.*5\.00 overall percentage points/);
    });

    it('uses the shared shadcn button and opens on keyboard focus', async () => {
        const user = userEvent.setup();
        render(<FairImprovementIndicator opportunity={availableOpportunity()} />);

        await user.tab();

        const trigger = screen.getByRole('button');
        expect(trigger).toHaveFocus();
        expect(trigger).toHaveAttribute('data-variant', 'ghost');
        expect(trigger).toHaveAttribute('data-size', 'icon-sm');
        expect(trigger).toHaveClass('focus-visible:ring-[3px]');
        expect(await screen.findByRole('tooltip')).toHaveTextContent('Reusability offers the largest FAIR-score opportunity.');
    });

    it('shows no more than three ordered actions and identifies their actors', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        render(
            <FairImprovementIndicator
                opportunity={availableOpportunity({
                    suggestions: [
                        { key: 'one', actor: 'curator', text: 'First score-improving action.' },
                        { key: 'two', actor: 'administrator', text: 'Second score-improving action.' },
                        { key: 'three', actor: 'curator', text: 'Third score-improving action.' },
                        { key: 'four', actor: 'administrator', text: 'Unexpected fourth action.' },
                    ],
                })}
            />,
        );

        await user.hover(screen.getByRole('button'));

        const tooltip = await screen.findByRole('tooltip');
        const actions = tooltip.querySelectorAll('ol > li');

        expect(tooltip).toHaveTextContent('Increase the FAIR score by:');
        expect(actions).toHaveLength(3);
        expect(actions[0]).toHaveTextContent('First score-improving action. (Curator action)');
        expect(actions[1]).toHaveTextContent('Second score-improving action. (ERNIE administrator action)');
        expect(actions[2]).toHaveTextContent('Third score-improving action. (Curator action)');
        expect(tooltip).not.toHaveTextContent('Unexpected fourth action.');
    });

    it('shows reassessment guidance instead of stale actions', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        const guidanceMessage = 'Run the assessment again to refresh FAIR improvement guidance after the recent ERNIE changes.';

        render(
            <FairImprovementIndicator
                opportunity={availableOpportunity({
                    requiresReassessment: true,
                    guidanceMessage,
                })}
            />,
        );

        await user.hover(screen.getByRole('button'));

        const tooltip = await screen.findByRole('tooltip');
        expect(tooltip).toHaveTextContent(guidanceMessage);
        expect(tooltip).not.toHaveTextContent('Increase the FAIR score by:');
        expect(tooltip).not.toHaveTextContent('Add a licence');
    });

    it('shows neutral no-action guidance and an optional physical-sample scope note', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        render(
            <FairImprovementIndicator
                opportunity={availableOpportunity({
                    dimension: 'A',
                    dimensionLabel: 'Accessibility',
                    guidanceMessage: 'ERNIE has no verified score-improving action to recommend for this FAIR category yet.',
                    suggestions: [],
                    scopeNote:
                        'F-UJI also counts digital-data checks in this dimension. ERNIE does not present those checks as actions for a physical sample.',
                })}
            />,
        );

        await user.hover(screen.getByRole('button'));

        const tooltip = await screen.findByRole('tooltip');
        expect(tooltip).toHaveTextContent('ERNIE has no verified score-improving action');
        expect(tooltip).toHaveTextContent('F-UJI also counts digital-data checks');
    });

    it.each([
        [
            {
                status: 'complete',
                message: 'No FAIR improvement gap was found.',
            } satisfies FairImprovementOpportunity,
            'No FAIR improvement gap was found.',
        ],
        [
            {
                status: 'unavailable',
                reason: 'invalid-payload',
                message: 'Run the assessment again to calculate FAIR improvement opportunities.',
            } satisfies FairImprovementOpportunity,
            'Run the assessment again to calculate FAIR improvement opportunities.',
        ],
        [
            {
                status: 'unavailable',
                reason: 'invalid-scope',
                message: 'FAIR improvement guidance is unavailable because this entry has no IGSN sample metadata.',
            } satisfies FairImprovementOpportunity,
            'FAIR improvement guidance is unavailable because this entry has no IGSN sample metadata.',
        ],
    ])('renders the neutral dash with its distinct accessible explanation', async (opportunity, expectedMessage) => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        render(<FairImprovementIndicator opportunity={opportunity} />);

        const trigger = screen.getByRole('button', { name: expectedMessage });
        expect(trigger).toHaveAttribute('data-variant', 'ghost');
        expect(trigger).toHaveAttribute('data-size', 'icon-sm');
        expect(trigger).toHaveTextContent('—');

        await user.hover(trigger);

        expect(await screen.findByRole('tooltip')).toHaveTextContent(expectedMessage);
    });

    it('exposes a neutral-state explanation on keyboard focus', async () => {
        const user = userEvent.setup();
        const message = 'No FAIR improvement gap was found.';

        render(
            <FairImprovementIndicator
                opportunity={{
                    status: 'complete',
                    message,
                }}
            />,
        );

        await user.tab();

        expect(await screen.findByRole('tooltip')).toHaveTextContent(message);
    });
    it('uses singular point copy in both the accessible name and tooltip', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });

        render(
            <FairImprovementIndicator
                opportunity={availableOpportunity({
                    missingPoints: 1,
                    potentialFairGain: 3.85,
                    severity: 'low',
                })}
            />,
        );

        const trigger = screen.getByRole('button', {
            name: 'Reusability: low FAIR improvement potential; 1 F-UJI point out of 6 is available, worth up to 3.85 overall percentage points.',
        });

        await user.hover(trigger);

        expect(await screen.findByRole('tooltip')).toHaveTextContent('1 F-UJI point out of 6 is available (up to +3.85 percentage points overall).');
    });

    it.each([1, 2])('renders exactly %i ranked actions', async (count) => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        const allSuggestions: Extract<FairImprovementOpportunity, { status: 'available' }>['suggestions'] = [
            { key: 'one', actor: 'curator', text: 'First score-improving action.' },
            { key: 'two', actor: 'administrator', text: 'Second score-improving action.' },
        ];

        render(
            <FairImprovementIndicator
                opportunity={availableOpportunity({
                    suggestions: allSuggestions.slice(0, count),
                })}
            />,
        );

        await user.hover(screen.getByRole('button'));

        expect((await screen.findByRole('tooltip')).querySelectorAll('ol > li')).toHaveLength(count);
    });

    it('keeps the tooltip constrained, wrapping, and left aligned', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        render(<FairImprovementIndicator opportunity={availableOpportunity()} />);

        await user.hover(screen.getByRole('button'));

        await screen.findByRole('tooltip');
        expect(document.querySelector('[data-slot="tooltip-content"]')).toHaveClass('max-w-sm', 'whitespace-normal', 'text-left');
    });

    it('does not add a native title attribute to the tooltip trigger', () => {
        render(<FairImprovementIndicator opportunity={availableOpportunity()} />);

        expect(screen.getByRole('button')).not.toHaveAttribute('title');
    });

    it('never renders unexpected raw F-UJI identifiers or failure text', async () => {
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        const opportunityWithRawPayload = Object.assign(availableOpportunity(), {
            metric_identifier: 'FsF-R1-01M',
            test_status: 'fail',
            failureText: 'Resource uses no persistent identifiers.',
        });

        render(<FairImprovementIndicator opportunity={opportunityWithRawPayload} />);

        await user.hover(screen.getByRole('button'));

        const tooltip = await screen.findByRole('tooltip');
        expect(tooltip).not.toHaveTextContent('FsF-R1-01M');
        expect(tooltip).not.toHaveTextContent('Resource uses no persistent identifiers.');
        expect(tooltip).not.toHaveTextContent('fail');
    });
});
