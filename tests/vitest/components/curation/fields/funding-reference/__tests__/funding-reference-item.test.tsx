import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { FundingReferenceItem } from '@/components/curation/fields/funding-reference/funding-reference-item';
import type { FundingReferenceEntry, RorFunder } from '@/components/curation/fields/funding-reference/types';

describe('FundingReferenceItem', () => {
    const mockOnFunderNameChange = vi.fn();
    const mockOnFieldsChange = vi.fn();
    const mockOnAwardNumberChange = vi.fn();
    const mockOnAwardUriChange = vi.fn();
    const mockOnAwardTitleChange = vi.fn();
    const mockOnToggleExpanded = vi.fn();
    const mockOnRemove = vi.fn();

    const defaultFunding: FundingReferenceEntry = {
        id: 'funding-1',
        funderName: '',
        funderIdentifier: '',
        funderIdentifierType: null,
        awardNumber: '',
        awardUri: '',
        awardTitle: '',
        isExpanded: false,
    };

    const mockRorFunders: RorFunder[] = [
        {
            rorId: 'https://ror.org/02e2c7k09',
            prefLabel: 'Deutsche Forschungsgemeinschaft',
            otherLabel: ['DFG'],
        },
        {
            rorId: 'https://ror.org/04xjs7w58',
            prefLabel: 'Helmholtz-Zentrum Potsdam',
            otherLabel: ['GFZ'],
        },
    ];

    const defaultProps = {
        funding: defaultFunding,
        index: 0,
        onFunderNameChange: mockOnFunderNameChange,
        onFieldsChange: mockOnFieldsChange,
        onAwardNumberChange: mockOnAwardNumberChange,
        onAwardUriChange: mockOnAwardUriChange,
        onAwardTitleChange: mockOnAwardTitleChange,
        onToggleExpanded: mockOnToggleExpanded,
        onRemove: mockOnRemove,
        canRemove: true,
        rorFunders: mockRorFunders,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the funding item with correct heading', () => {
        render(<FundingReferenceItem {...defaultProps} index={0} />);

        expect(screen.getByRole('heading', { name: 'Funding #1' })).toBeInTheDocument();
    });

    it('renders the funder name input', () => {
        render(<FundingReferenceItem {...defaultProps} />);

        expect(screen.getByLabelText(/Funder Name/i)).toBeInTheDocument();
    });

    it('renders the card with an editable visual treatment', () => {
        const validFunding = { ...defaultFunding, funderName: 'Deutsche Forschungsgemeinschaft' };
        render(<FundingReferenceItem {...defaultProps} funding={validFunding} />);

        const card = screen.getByTestId('funding-reference-card');
        expect(card).toHaveClass('bg-card');
        expect(card).toHaveClass('text-card-foreground');
        expect(card).toHaveClass('border-border');
        expect(card).toHaveClass('border-l-4');
        expect(card).toHaveClass('border-l-gfz-primary');
        expect(card).toHaveClass('shadow-md');
        expect(card).toHaveClass('focus-within:ring-[3px]');
        expect(card).not.toHaveClass('bg-muted');
        expect(card).not.toHaveClass('bg-muted/30');
    });

    it('calls onFunderNameChange when typing in funder name field', async () => {
        const user = userEvent.setup();
        render(<FundingReferenceItem {...defaultProps} />);

        const input = screen.getByLabelText(/Funder Name/i);
        await user.type(input, 'Test Funder');

        expect(mockOnFunderNameChange).toHaveBeenCalled();
    });

    it('shows remove button when canRemove is true', () => {
        render(<FundingReferenceItem {...defaultProps} canRemove={true} />);

        expect(screen.getByRole('button', { name: 'Remove funding 1' })).toBeInTheDocument();
    });

    it('hides remove button when canRemove is false', () => {
        render(<FundingReferenceItem {...defaultProps} canRemove={false} />);

        expect(screen.queryByRole('button', { name: 'Remove funding 1' })).not.toBeInTheDocument();
    });

    it('calls onRemove when remove button is clicked', async () => {
        const user = userEvent.setup();
        render(<FundingReferenceItem {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: 'Remove funding 1' }));

        expect(mockOnRemove).toHaveBeenCalledTimes(1);
    });

    it('shows "Show award details" button when collapsed', () => {
        render(<FundingReferenceItem {...defaultProps} />);

        expect(screen.getByRole('button', { name: /Show award details/i })).toBeInTheDocument();
    });

    it('shows "Hide award details" button when expanded', () => {
        const expandedFunding = { ...defaultFunding, isExpanded: true };
        render(<FundingReferenceItem {...defaultProps} funding={expandedFunding} />);

        expect(screen.getByRole('button', { name: /Hide award details/i })).toBeInTheDocument();
    });

    it('calls onToggleExpanded when toggle button is clicked', async () => {
        const user = userEvent.setup();
        render(<FundingReferenceItem {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: /Show award details/i }));

        expect(mockOnToggleExpanded).toHaveBeenCalledTimes(1);
    });

    it('renders award detail fields when expanded', () => {
        const expandedFunding = { ...defaultFunding, isExpanded: true };
        render(<FundingReferenceItem {...defaultProps} funding={expandedFunding} />);

        expect(screen.getByLabelText(/Award\/Grant Number/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/Award URI/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/Award Title/i)).toBeInTheDocument();
    });

    it('renders expanded award details without a muted disabled-looking background', () => {
        const expandedFunding = { ...defaultFunding, funderName: 'Deutsche Forschungsgemeinschaft', isExpanded: true };
        render(<FundingReferenceItem {...defaultProps} funding={expandedFunding} />);

        const awardDetails = screen.getByTestId('funding-reference-award-details');
        expect(awardDetails).toHaveClass('bg-background');
        expect(awardDetails).toHaveClass('border-dashed');
        expect(awardDetails).toHaveClass('border-input');
        expect(awardDetails).not.toHaveClass('bg-muted');
        expect(awardDetails).not.toHaveClass('bg-muted/30');
    });

    it('does not render award detail fields when collapsed', () => {
        render(<FundingReferenceItem {...defaultProps} />);

        expect(screen.queryByLabelText(/Award\/Grant Number/i)).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/Award URI/i)).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/Award Title/i)).not.toBeInTheDocument();
    });

    it('calls onAwardNumberChange when typing in award number field', async () => {
        const user = userEvent.setup();
        const expandedFunding = { ...defaultFunding, isExpanded: true };
        render(<FundingReferenceItem {...defaultProps} funding={expandedFunding} />);

        const input = screen.getByLabelText(/Award\/Grant Number/i);
        await user.type(input, 'ERC-2021');

        expect(mockOnAwardNumberChange).toHaveBeenCalled();
    });

    it('calls onAwardUriChange when typing in award URI field', async () => {
        const user = userEvent.setup();
        const expandedFunding = { ...defaultFunding, isExpanded: true };
        render(<FundingReferenceItem {...defaultProps} funding={expandedFunding} />);

        const input = screen.getByLabelText(/Award URI/i);
        await user.type(input, 'https://example.com');

        expect(mockOnAwardUriChange).toHaveBeenCalled();
    });

    it('calls onAwardTitleChange when typing in award title field', async () => {
        const user = userEvent.setup();
        const expandedFunding = { ...defaultFunding, isExpanded: true };
        render(<FundingReferenceItem {...defaultProps} funding={expandedFunding} />);

        const input = screen.getByLabelText(/Award Title/i);
        await user.type(input, 'Test Award Title');

        expect(mockOnAwardTitleChange).toHaveBeenCalled();
    });

    it('displays ROR badge when funder has identifier', () => {
        const fundingWithRor = {
            ...defaultFunding,
            funderName: 'Deutsche Forschungsgemeinschaft',
            funderIdentifier: 'https://ror.org/02e2c7k09',
            funderIdentifierType: 'ROR' as const,
        };
        render(<FundingReferenceItem {...defaultProps} funding={fundingWithRor} />);

        expect(screen.getByText(/ROR:/)).toBeInTheDocument();
    });

    it('renders funder identifier as a link', () => {
        const fundingWithRor = {
            ...defaultFunding,
            funderName: 'Deutsche Forschungsgemeinschaft',
            funderIdentifier: 'https://ror.org/02e2c7k09',
            funderIdentifierType: 'ROR' as const,
        };
        render(<FundingReferenceItem {...defaultProps} funding={fundingWithRor} />);

        const link = screen.getByRole('link');
        expect(link).toHaveAttribute('href', 'https://ror.org/02e2c7k09');
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('shows autocomplete suggestions when typing funder name', async () => {
        const user = userEvent.setup();
        const funding = { ...defaultFunding, funderName: 'Deutsche' };
        render(<FundingReferenceItem {...defaultProps} funding={funding} />);

        const input = screen.getByLabelText(/Funder Name/i);
        await user.click(input);

        await waitFor(() => {
            expect(screen.getByRole('listbox')).toBeInTheDocument();
        });
    });

    it('calls onFieldsChange when selecting a suggestion', async () => {
        const user = userEvent.setup();
        const funding = { ...defaultFunding, funderName: 'Deutsche' };
        render(<FundingReferenceItem {...defaultProps} funding={funding} />);

        const input = screen.getByLabelText(/Funder Name/i);
        await user.click(input);

        await waitFor(() => {
            expect(screen.getByRole('listbox')).toBeInTheDocument();
        });

        const suggestion = screen.getByRole('option', { name: /Deutsche Forschungsgemeinschaft/i });
        await user.click(suggestion);

        expect(mockOnFieldsChange).toHaveBeenCalledWith({
            funderName: 'Deutsche Forschungsgemeinschaft',
            funderIdentifier: 'https://ror.org/02e2c7k09',
            funderIdentifierType: 'ROR',
        });
    });

    it('clears ROR ID when user manually edits funder name after selection', async () => {
        const user = userEvent.setup();
        const fundingWithRor = {
            ...defaultFunding,
            funderName: 'Deutsche Forschungsgemeinschaft',
            funderIdentifier: 'https://ror.org/02e2c7k09',
            funderIdentifierType: 'ROR' as const,
        };
        render(<FundingReferenceItem {...defaultProps} funding={fundingWithRor} />);

        const input = screen.getByLabelText(/Funder Name/i);
        await user.type(input, 'X');

        expect(mockOnFieldsChange).toHaveBeenCalledWith({
            funderName: 'Deutsche ForschungsgemeinschaftX',
            funderIdentifier: '',
            funderIdentifierType: null,
        });
    });

    it('renders with correct index in heading', () => {
        render(<FundingReferenceItem {...defaultProps} index={4} />);

        expect(screen.getByRole('heading', { name: 'Funding #5' })).toBeInTheDocument();
    });

    it('displays prefilled values correctly', () => {
        const prefilledFunding = {
            ...defaultFunding,
            funderName: 'Test Funder Name',
            awardNumber: 'GRANT-123',
            awardUri: 'https://grant.example.com',
            awardTitle: 'Test Grant Title',
            isExpanded: true,
        };
        render(<FundingReferenceItem {...defaultProps} funding={prefilledFunding} />);

        expect(screen.getByLabelText(/Funder Name/i)).toHaveValue('Test Funder Name');
        expect(screen.getByLabelText(/Award\/Grant Number/i)).toHaveValue('GRANT-123');
        expect(screen.getByLabelText(/Award URI/i)).toHaveValue('https://grant.example.com');
        expect(screen.getByLabelText(/Award Title/i)).toHaveValue('Test Grant Title');
    });

    it('marks the funder name input invalid when it is empty', () => {
        render(<FundingReferenceItem {...defaultProps} />);

        const input = screen.getByLabelText(/Funder Name/i);
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(input).toHaveAttribute('aria-describedby', 'funding-1-funder-name-error');
        expect(screen.getByText('Funder name is required')).toBeInTheDocument();
    });

    it('marks the award URI input invalid when the expanded value is not a URL', () => {
        const invalidFunding = {
            ...defaultFunding,
            funderName: 'Deutsche Forschungsgemeinschaft',
            awardUri: 'not-a-url',
            isExpanded: true,
        };
        render(<FundingReferenceItem {...defaultProps} funding={invalidFunding} />);

        const input = screen.getByLabelText(/Award URI/i);
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(input).toHaveAttribute('aria-describedby', 'funding-1-award-uri-error');
        expect(screen.getByText('Invalid URL format')).toBeInTheDocument();
    });

    it('displays Crossref Funder ID badge with correct icon', () => {
        const fundingWithCrossref = {
            ...defaultFunding,
            funderName: 'National Science Foundation',
            funderIdentifier: 'https://doi.org/10.13039/100000001',
            funderIdentifierType: 'Crossref Funder ID' as const,
        };
        render(<FundingReferenceItem {...defaultProps} funding={fundingWithCrossref} />);

        expect(screen.getByText(/Crossref Funder ID:/)).toBeInTheDocument();
    });

    it('has accessible aria-labelledby on section', () => {
        render(<FundingReferenceItem {...defaultProps} />);

        const section = screen.getByRole('region', { hidden: true }) || document.querySelector('section');
        expect(section).toHaveAttribute('aria-labelledby', 'funding-1-heading');
    });
});
