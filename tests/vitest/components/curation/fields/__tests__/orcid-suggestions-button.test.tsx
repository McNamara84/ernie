/**
 * @vitest-environment jsdom
 */

import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { OrcidSuggestionsButton } from '@/components/curation/fields/orcid-suggestions-button';
import type { PendingOrcidData } from '@/hooks/use-orcid-autofill';

// Mock the OrcidSuggestionsModal to avoid rendering the full dialog
vi.mock('@/components/curation/modals/orcid-suggestions-modal', () => ({
    OrcidSuggestionsModal: ({ open }: { open: boolean }) =>
        open ? <div data-testid="orcid-suggestions-modal">Modal Open</div> : null,
}));

describe('OrcidSuggestionsButton', () => {
    const defaultProps = {
        onAccept: vi.fn(),
        onDiscard: vi.fn(),
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('returns null when count is 0', () => {
        const pendingData: PendingOrcidData = {
            affiliations: [],
            firstNameDiff: null,
            lastNameDiff: null,
            emailSuggestion: null,
        };

        const { container } = render(<OrcidSuggestionsButton pendingData={pendingData} {...defaultProps} />);
        expect(container.innerHTML).toBe('');
    });

    it('renders button with correct count for affiliations only', () => {
        const pendingData: PendingOrcidData = {
            affiliations: [
                { value: 'Uni A', rorId: null, status: 'new' },
                { value: 'Uni B', rorId: null, status: 'different', existingValue: 'Old B' },
            ],
            firstNameDiff: null,
            lastNameDiff: null,
            emailSuggestion: null,
        };

        render(<OrcidSuggestionsButton pendingData={pendingData} {...defaultProps} />);

        expect(screen.getByText('ORCID Suggestions')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
    });

    it('counts all pending items', () => {
        const pendingData: PendingOrcidData = {
            affiliations: [{ value: 'Uni A', rorId: null, status: 'new' }],
            firstNameDiff: { orcid: 'Johann', current: 'John' },
            lastNameDiff: { orcid: 'Müller', current: 'Mueller' },
            emailSuggestion: 'test@example.com',
        };

        render(<OrcidSuggestionsButton pendingData={pendingData} {...defaultProps} />);

        // 1 affiliation + 1 firstName + 1 lastName + 1 email = 4
        expect(screen.getByText('4')).toBeInTheDocument();
    });

    it('opens modal on click', async () => {
        const user = userEvent.setup();
        const pendingData: PendingOrcidData = {
            affiliations: [{ value: 'Uni A', rorId: null, status: 'new' }],
            firstNameDiff: null,
            lastNameDiff: null,
            emailSuggestion: null,
        };

        render(<OrcidSuggestionsButton pendingData={pendingData} {...defaultProps} />);

        expect(screen.queryByTestId('orcid-suggestions-modal')).not.toBeInTheDocument();

        await user.click(screen.getByText('ORCID Suggestions'));

        expect(screen.getByTestId('orcid-suggestions-modal')).toBeInTheDocument();
    });
});
