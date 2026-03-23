/**
 * @vitest-environment jsdom
 *
 * Focused test for the pendingOrcidData conditional rendering in ContributorItem.
 * Tests the {pendingOrcidData && <OrcidSuggestionsButton />} branch.
 */

import { cleanup, render, screen } from '@tests/vitest/utils/render';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ContributorItem from '@/components/curation/fields/contributor/contributor-item';
import type { ContributorEntry } from '@/components/curation/fields/contributor/types';

const mockApplyPendingData = vi.fn();
const mockClearPendingOrcidData = vi.fn();

// Mock useOrcidAutofill to return pendingOrcidData
vi.mock('@/hooks/use-orcid-autofill', () => ({
    useOrcidAutofill: vi.fn(() => ({
        isVerifying: false,
        verificationError: null,
        clearError: vi.fn(),
        orcidSuggestions: [],
        isLoadingSuggestions: false,
        showSuggestions: false,
        hideSuggestions: vi.fn(),
        handleOrcidSelect: vi.fn(),
        canRetry: false,
        retryVerification: vi.fn(),
        errorType: null,
        isFormatValid: false,
        pendingOrcidData: {
            affiliations: [{ value: 'New Research Lab', rorId: null, status: 'new' }],
            firstNameDiff: null,
            lastNameDiff: null,
            emailSuggestion: null,
        },
        clearPendingOrcidData: mockClearPendingOrcidData,
        applyPendingData: mockApplyPendingData,
    })),
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

describe('ContributorItem with pendingOrcidData', () => {
    const mockPersonContributor: ContributorEntry = {
        id: 'contributor-1',
        type: 'person',
        orcid: '0000-0001-2345-6789',
        firstName: 'Jane',
        lastName: 'Smith',
        email: '',
        website: '',
        roles: [{ value: 'DataCollector' }],
        rolesInput: 'DataCollector',
        orcidVerified: true,
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

    afterEach(() => {
        cleanup();
    });

    it('renders OrcidSuggestionsButton when pendingOrcidData is present', () => {
        render(<ContributorItem contributor={mockPersonContributor} {...mockProps} />);

        expect(screen.getByText('ORCID Suggestions')).toBeInTheDocument();
    });
});
