/**
 * @vitest-environment jsdom
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    OrcidSuggestionsModal,
    type OrcidSuggestionsModalProps,
} from '@/components/curation/modals/orcid-suggestions-modal';
import type { PendingOrcidData } from '@/hooks/use-orcid-autofill';

describe('OrcidSuggestionsModal', () => {
    const basePendingData: PendingOrcidData = {
        affiliations: [
            { value: 'New University', rorId: 'https://ror.org/abc123', status: 'new' },
            {
                value: 'Helmholtz Centre Potsdam',
                rorId: 'https://ror.org/def456',
                status: 'different',
                existingValue: 'GFZ German Research Centre',
            },
        ],
        firstNameDiff: { orcid: 'Johann', current: 'John' },
        lastNameDiff: { orcid: 'Müller', current: 'Mueller' },
        emailSuggestion: 'johann@example.com',
    };

    const defaultProps: OrcidSuggestionsModalProps = {
        open: true,
        onOpenChange: vi.fn(),
        pendingData: basePendingData,
        onAccept: vi.fn(),
        onDiscard: vi.fn(),
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('Rendering', () => {
        it('renders the modal when open', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            expect(screen.getByText('Additional ORCID Data Available')).toBeInTheDocument();
            expect(screen.getByText(/The ORCID profile contains data that differs/)).toBeInTheDocument();
        });

        it('does not render when closed', () => {
            render(<OrcidSuggestionsModal {...defaultProps} open={false} />);

            expect(screen.queryByText('Additional ORCID Data Available')).not.toBeInTheDocument();
        });

        it('renders the Affiliations section', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            expect(screen.getByText('Affiliations')).toBeInTheDocument();
            expect(screen.getByText('New University')).toBeInTheDocument();
            expect(screen.getByText('Helmholtz Centre Potsdam')).toBeInTheDocument();
        });

        it('renders NEW badge for new affiliations', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            expect(screen.getByText('New')).toBeInTheDocument();
        });

        it('renders DIFFERENT badge for different affiliations', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            expect(screen.getByText('Different')).toBeInTheDocument();
        });

        it('shows existing value for different-status affiliations', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            expect(screen.getByText('GFZ German Research Centre')).toBeInTheDocument();
        });

        it('renders ROR links for affiliations with rorId', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            expect(screen.getByText('ROR: https://ror.org/abc123')).toBeInTheDocument();
            expect(screen.getByText('ROR: https://ror.org/def456')).toBeInTheDocument();
        });

        it('renders the Name section with diffs', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            expect(screen.getByText('Name')).toBeInTheDocument();
            expect(screen.getByText('First Name')).toBeInTheDocument();
            expect(screen.getByText('Last Name')).toBeInTheDocument();
            expect(screen.getByText('Johann')).toBeInTheDocument();
            expect(screen.getByText('John')).toBeInTheDocument();
            expect(screen.getByText('Müller')).toBeInTheDocument();
            expect(screen.getByText('Mueller')).toBeInTheDocument();
        });

        it('renders the Email section', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            expect(screen.getByText('Email')).toBeInTheDocument();
            expect(screen.getByText('johann@example.com')).toBeInTheDocument();
        });

        it('renders only Affiliations section when no name diffs or email', () => {
            const pendingData: PendingOrcidData = {
                affiliations: [{ value: 'MIT', rorId: null, status: 'new' }],
                firstNameDiff: null,
                lastNameDiff: null,
                emailSuggestion: null,
            };

            render(<OrcidSuggestionsModal {...defaultProps} pendingData={pendingData} />);

            expect(screen.getByText('Affiliations')).toBeInTheDocument();
            expect(screen.queryByText('Name')).not.toBeInTheDocument();
            expect(screen.queryByText('Email')).not.toBeInTheDocument();
        });

        it('renders only Name section when no affiliations or email', () => {
            const pendingData: PendingOrcidData = {
                affiliations: [],
                firstNameDiff: { orcid: 'Johann', current: 'John' },
                lastNameDiff: null,
                emailSuggestion: null,
            };

            render(<OrcidSuggestionsModal {...defaultProps} pendingData={pendingData} />);

            expect(screen.queryByText('Affiliations')).not.toBeInTheDocument();
            expect(screen.getByText('Name')).toBeInTheDocument();
            expect(screen.queryByText('Email')).not.toBeInTheDocument();
        });

        it('renders only Email section when no affiliations or name diffs', () => {
            const pendingData: PendingOrcidData = {
                affiliations: [],
                firstNameDiff: null,
                lastNameDiff: null,
                emailSuggestion: 'test@example.com',
            };

            render(<OrcidSuggestionsModal {...defaultProps} pendingData={pendingData} />);

            expect(screen.queryByText('Affiliations')).not.toBeInTheDocument();
            expect(screen.queryByText('Name')).not.toBeInTheDocument();
            expect(screen.getByText('Email')).toBeInTheDocument();
        });
    });

    describe('Checkbox state', () => {
        it('pre-checks new affiliations', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            const newCheckbox = screen.getByRole('checkbox', { name: /New University/i });
            expect(newCheckbox).toBeChecked();
        });

        it('does not pre-check different affiliations', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            const diffCheckbox = screen.getByRole('checkbox', { name: /Helmholtz Centre Potsdam/i });
            expect(diffCheckbox).not.toBeChecked();
        });

        it('does not pre-check name diff checkboxes', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            const firstNameCheckbox = screen.getByRole('checkbox', { name: /First Name/i });
            const lastNameCheckbox = screen.getByRole('checkbox', { name: /Last Name/i });
            expect(firstNameCheckbox).not.toBeChecked();
            expect(lastNameCheckbox).not.toBeChecked();
        });

        it('does not pre-check email checkbox', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            const emailCheckbox = screen.getByRole('checkbox', { name: /johann@example.com/i });
            expect(emailCheckbox).not.toBeChecked();
        });
    });

    describe('Toggling checkboxes', () => {
        it('toggles an affiliation checkbox', async () => {
            const user = userEvent.setup();
            render(<OrcidSuggestionsModal {...defaultProps} />);

            const diffCheckbox = screen.getByRole('checkbox', { name: /Helmholtz Centre Potsdam/i });
            expect(diffCheckbox).not.toBeChecked();

            await user.click(diffCheckbox);
            expect(diffCheckbox).toBeChecked();

            await user.click(diffCheckbox);
            expect(diffCheckbox).not.toBeChecked();
        });

        it('unchecks a pre-checked new affiliation', async () => {
            const user = userEvent.setup();
            render(<OrcidSuggestionsModal {...defaultProps} />);

            const newCheckbox = screen.getByRole('checkbox', { name: /New University/i });
            expect(newCheckbox).toBeChecked();

            await user.click(newCheckbox);
            expect(newCheckbox).not.toBeChecked();
        });
    });

    describe('Accept', () => {
        it('calls onAccept with selected data and closes modal', async () => {
            const user = userEvent.setup();
            render(<OrcidSuggestionsModal {...defaultProps} />);

            // By default: new affiliation is pre-checked, name/email are not
            const acceptBtn = screen.getByRole('button', { name: /Accept Selected/i });
            await user.click(acceptBtn);

            expect(defaultProps.onAccept).toHaveBeenCalledWith({
                affiliations: [basePendingData.affiliations[0]], // only the 'new' one
                applyFirstName: false,
                applyLastName: false,
                applyEmail: false,
            });
            expect(defaultProps.onOpenChange).toHaveBeenCalledWith(false);
        });

        it('includes name diffs in accept when checked', async () => {
            const user = userEvent.setup();
            render(<OrcidSuggestionsModal {...defaultProps} />);

            const firstNameCheckbox = screen.getByRole('checkbox', { name: /First Name/i });
            await user.click(firstNameCheckbox);

            const acceptBtn = screen.getByRole('button', { name: /Accept Selected/i });
            await user.click(acceptBtn);

            expect(defaultProps.onAccept).toHaveBeenCalledWith(
                expect.objectContaining({
                    applyFirstName: true,
                    applyLastName: false,
                }),
            );
        });

        it('includes email in accept when checked', async () => {
            const user = userEvent.setup();
            render(<OrcidSuggestionsModal {...defaultProps} />);

            const emailCheckbox = screen.getByRole('checkbox', { name: /johann@example.com/i });
            await user.click(emailCheckbox);

            const acceptBtn = screen.getByRole('button', { name: /Accept Selected/i });
            await user.click(acceptBtn);

            expect(defaultProps.onAccept).toHaveBeenCalledWith(
                expect.objectContaining({
                    applyEmail: true,
                }),
            );
        });

        it('disables Accept button when nothing is selected', async () => {
            const user = userEvent.setup();
            render(<OrcidSuggestionsModal {...defaultProps} />);

            // Uncheck the pre-checked new affiliation
            const newCheckbox = screen.getByRole('checkbox', { name: /New University/i });
            await user.click(newCheckbox);

            const acceptBtn = screen.getByRole('button', { name: /Accept Selected/i });
            expect(acceptBtn).toBeDisabled();
        });

        it('enables Accept button when at least one item is selected', () => {
            render(<OrcidSuggestionsModal {...defaultProps} />);

            // New affiliation is pre-checked
            const acceptBtn = screen.getByRole('button', { name: /Accept Selected/i });
            expect(acceptBtn).toBeEnabled();
        });
    });

    describe('Discard', () => {
        it('calls onDiscard and closes modal', async () => {
            const user = userEvent.setup();
            render(<OrcidSuggestionsModal {...defaultProps} />);

            const discardBtn = screen.getByRole('button', { name: /Discard All/i });
            await user.click(discardBtn);

            expect(defaultProps.onDiscard).toHaveBeenCalledTimes(1);
            expect(defaultProps.onOpenChange).toHaveBeenCalledWith(false);
        });
    });
});
