/**
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ContributorItem from '@/components/curation/fields/contributor/contributor-item';
import type { ContributorEntry } from '@/components/curation/fields/contributor/types';

// Mock ORCID Service
vi.mock('@/services/orcid', () => ({
    OrcidService: {
        isValidFormat: vi.fn((orcid: string) => /^\d{4}-\d{4}-\d{4}-\d{3}[0-9X]$/.test(orcid)),
        validateOrcid: vi.fn(() => Promise.resolve(true)),
        fetchOrcidRecord: vi.fn(() => Promise.resolve({
            'given-names': 'Jane',
            'family-name': 'Smith',
            'employment-summary': [
                { 'organization': { 'name': 'Research Institute' } }
            ],
        })),
        searchOrcid: vi.fn(() => Promise.resolve([])),
    },
}));

// Mock Drag & Drop
vi.mock('@dnd-kit/sortable', () => ({
    useSortable: () => ({
        attributes: {},
        listeners: {},
        setNodeRef: vi.fn(),
        transform: null,
        transition: null,
        isDragging: false,
    }),
}));

vi.mock('@dnd-kit/utilities', () => ({
    CSS: {
        Transform: {
            toString: () => '',
        },
    },
}));

describe('ContributorItem Component', () => {
    const mockPersonContributor: ContributorEntry = {
        id: 'contributor-1',
        type: 'person',
        orcid: '',
        firstName: 'Jane',
        lastName: 'Smith',
        roles: [{ value: 'DataCollector' }],
        rolesInput: 'DataCollector',
        orcidVerified: false,
        affiliations: [],
        affiliationsInput: '',
    };

    const mockInstitutionContributor: ContributorEntry = {
        id: 'contributor-2',
        type: 'institution',
        institutionName: 'Research Institute',
        roles: [{ value: 'Sponsor' }],
        rolesInput: 'Sponsor',
        affiliations: [],
        affiliationsInput: '',
    };

    const mockProps = {
        index: 0,
        onTypeChange: vi.fn(),
        onRolesChange: vi.fn(),
        onPersonFieldChange: vi.fn(),
        onInstitutionNameChange: vi.fn(),
        onAffiliationsChange: vi.fn(),
        onContributorChange: vi.fn(),
        onRemove: vi.fn(),
        canRemove: true,
        affiliationSuggestions: [],
        personRoleOptions: ['DataCollector', 'DataCurator', 'Editor'] as const,
        institutionRoleOptions: ['Sponsor', 'Funder'] as const,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders person contributor correctly', () => {
        render(<ContributorItem contributor={mockPersonContributor} {...mockProps} />);
        
        expect(screen.getByText('Contributor 1')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Jane')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Smith')).toBeInTheDocument();
    });

    it('renders institution contributor correctly', () => {
        render(<ContributorItem contributor={mockInstitutionContributor} {...mockProps} />);
        
        expect(screen.getByText('Contributor 1')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Research Institute')).toBeInTheDocument();
    });

    it('shows remove button when canRemove is true', () => {
        render(<ContributorItem contributor={mockPersonContributor} {...mockProps} canRemove={true} />);
        
        expect(screen.getByLabelText('Remove contributor 1')).toBeInTheDocument();
    });

    it('hides remove button when canRemove is false', () => {
        render(<ContributorItem contributor={mockPersonContributor} {...mockProps} canRemove={false} />);
        
        expect(screen.queryByLabelText('Remove contributor 1')).not.toBeInTheDocument();
    });

    it('calls onRemove when remove button is clicked', async () => {
        const user = userEvent.setup();
        render(<ContributorItem contributor={mockPersonContributor} {...mockProps} />);
        
        const removeButton = screen.getByLabelText('Remove contributor 1');
        await user.click(removeButton);
        
        expect(mockProps.onRemove).toHaveBeenCalledTimes(1);
    });

    it('displays contributor roles', () => {
        render(<ContributorItem contributor={mockPersonContributor} {...mockProps} />);
        
        // Roles are shown in tag input
        expect(screen.getByDisplayValue('DataCollector')).toBeInTheDocument();
    });

    it('calls onTypeChange when contributor type is changed', async () => {
        const user = userEvent.setup();
        render(<ContributorItem contributor={mockPersonContributor} {...mockProps} />);
        
        const typeSelect = screen.getByLabelText('Contributor type');
        await user.click(typeSelect);
        
        await waitFor(() => {
            const institutionOption = screen.getByRole('option', { name: /institution/i });
            user.click(institutionOption);
        });
        
        await waitFor(() => {
            expect(mockProps.onTypeChange).toHaveBeenCalledWith('institution');
        });
    });

    it('renders drag handle with correct aria-label', () => {
        render(<ContributorItem contributor={mockPersonContributor} {...mockProps} />);
        
        expect(screen.getByLabelText('Reorder contributor 1')).toBeInTheDocument();
    });

    it('shows ORCID field for person type', () => {
        render(<ContributorItem contributor={mockPersonContributor} {...mockProps} />);
        
        expect(screen.getByLabelText(/ORCID/i)).toBeInTheDocument();
    });

    it('does not show ORCID field for institution type', () => {
        render(<ContributorItem contributor={mockInstitutionContributor} {...mockProps} />);
        
        expect(screen.queryByLabelText(/ORCID/i)).not.toBeInTheDocument();
    });

    it('displays ORCID verified badge when orcidVerified is true', () => {
        const verifiedContributor = { ...mockPersonContributor, orcidVerified: true };
        render(<ContributorItem contributor={verifiedContributor} {...mockProps} />);
        
        expect(screen.getByText('Verified')).toBeInTheDocument();
    });
});
