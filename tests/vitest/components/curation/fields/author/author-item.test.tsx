/**
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AuthorItem from '@/components/curation/fields/author/author-item';
import type { AuthorEntry } from '@/components/curation/fields/author/types';

// Mock ORCID Service
vi.mock('@/services/orcid', () => ({
    OrcidService: {
        isValidFormat: vi.fn((orcid: string) => /^\d{4}-\d{4}-\d{4}-\d{3}[0-9X]$/.test(orcid)),
        validateOrcid: vi.fn(() => Promise.resolve(true)),
        fetchOrcidRecord: vi.fn(() => Promise.resolve({
            'given-names': 'John',
            'family-name': 'Doe',
            emails: [{ email: 'john.doe@example.com' }],
            urls: [{ value: 'https://example.com' }],
            'employment-summary': [
                { 'organization': { 'name': 'Test University', 'disambiguated-organization': { 'disambiguated-organization-identifier': 'https://ror.org/test123' } } }
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

describe('AuthorItem Component', () => {
    const mockPersonAuthor: AuthorEntry = {
        id: 'author-1',
        type: 'person',
        orcid: '',
        firstName: 'John',
        lastName: 'Doe',
        email: 'john.doe@example.com',
        website: 'https://example.com',
        isContact: false,
        orcidVerified: false,
        affiliations: [],
        affiliationsInput: '',
    };

    const mockInstitutionAuthor: AuthorEntry = {
        id: 'author-2',
        type: 'institution',
        institutionName: 'Test University',
        affiliations: [],
        affiliationsInput: '',
    };

    const mockProps = {
        index: 0,
        onTypeChange: vi.fn(),
        onPersonFieldChange: vi.fn(),
        onInstitutionNameChange: vi.fn(),
        onContactChange: vi.fn(),
        onAffiliationsChange: vi.fn(),
        onAuthorChange: vi.fn(),
        onRemove: vi.fn(),
        canRemove: true,
        affiliationSuggestions: [],
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders person author correctly', () => {
        render(<AuthorItem author={mockPersonAuthor} {...mockProps} />);
        
        expect(screen.getByText('Author 1')).toBeInTheDocument();
        expect(screen.getByDisplayValue('John')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Doe')).toBeInTheDocument();
        expect(screen.getByDisplayValue('john.doe@example.com')).toBeInTheDocument();
    });

    it('renders institution author correctly', () => {
        render(<AuthorItem author={mockInstitutionAuthor} {...mockProps} />);
        
        expect(screen.getByText('Author 1')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Test University')).toBeInTheDocument();
    });

    it('shows remove button when canRemove is true', () => {
        render(<AuthorItem author={mockPersonAuthor} {...mockProps} canRemove={true} />);
        
        expect(screen.getByLabelText('Remove author 1')).toBeInTheDocument();
    });

    it('hides remove button when canRemove is false', () => {
        render(<AuthorItem author={mockPersonAuthor} {...mockProps} canRemove={false} />);
        
        expect(screen.queryByLabelText('Remove author 1')).not.toBeInTheDocument();
    });

    it('calls onRemove when remove button is clicked', async () => {
        const user = userEvent.setup();
        render(<AuthorItem author={mockPersonAuthor} {...mockProps} />);
        
        const removeButton = screen.getByLabelText('Remove author 1');
        await user.click(removeButton);
        
        expect(mockProps.onRemove).toHaveBeenCalledTimes(1);
    });

    it('calls onTypeChange when author type is changed', async () => {
        const user = userEvent.setup();
        render(<AuthorItem author={mockPersonAuthor} {...mockProps} />);
        
        const typeSelect = screen.getByLabelText('Author type');
        await user.click(typeSelect);
        
        // Wait for dropdown to open and click institution option
        await waitFor(() => {
            const institutionOption = screen.getByRole('option', { name: /institution/i });
            user.click(institutionOption);
        });
        
        await waitFor(() => {
            expect(mockProps.onTypeChange).toHaveBeenCalledWith('institution');
        });
    });

    it('calls onPersonFieldChange when first name is changed', async () => {
        const user = userEvent.setup();
        render(<AuthorItem author={mockPersonAuthor} {...mockProps} />);
        
        const firstNameInput = screen.getByLabelText('First name');
        await user.clear(firstNameInput);
        await user.type(firstNameInput, 'Jane');
        
        expect(mockProps.onPersonFieldChange).toHaveBeenCalledWith('firstName', 'Jane');
    });

    it('calls onContactChange when contact person checkbox is toggled', async () => {
        const user = userEvent.setup();
        render(<AuthorItem author={mockPersonAuthor} {...mockProps} />);
        
        const contactCheckbox = screen.getByRole('checkbox', { name: /contact person/i });
        await user.click(contactCheckbox);
        
        expect(mockProps.onContactChange).toHaveBeenCalledWith(true);
    });

    it('displays ORCID verified badge when orcidVerified is true', () => {
        const verifiedAuthor = { ...mockPersonAuthor, orcidVerified: true };
        render(<AuthorItem author={verifiedAuthor} {...mockProps} />);
        
        expect(screen.getByText('Verified')).toBeInTheDocument();
    });

    it('renders drag handle with correct aria-label', () => {
        render(<AuthorItem author={mockPersonAuthor} {...mockProps} />);
        
        expect(screen.getByLabelText('Reorder author 1')).toBeInTheDocument();
    });

    it('validates ORCID format', async () => {
        const user = userEvent.setup();
        render(<AuthorItem author={mockPersonAuthor} {...mockProps} />);
        
        const orcidInput = screen.getByLabelText(/ORCID/i);
        await user.type(orcidInput, '0000-0002-1825-0097');
        
        // Valid ORCID should show link to ORCID.org
        await waitFor(() => {
            expect(screen.getByLabelText('View on ORCID.org')).toBeInTheDocument();
        });
    });
});
