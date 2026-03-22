/**
 * @vitest-environment jsdom
 *
 * Focused test for the pendingOrcidData conditional rendering in AuthorItem.
 * Tests the {pendingOrcidData && <OrcidSuggestionsButton />} branch.
 */

import { cleanup, render, screen, waitFor } from '@tests/vitest/utils/render';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import AuthorItem from '@/components/curation/fields/author/author-item';
import type { AuthorEntry } from '@/components/curation/fields/author/types';

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
            affiliations: [{ value: 'New University', rorId: null, status: 'new' }],
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

describe('AuthorItem with pendingOrcidData', () => {
    const mockPersonAuthor: AuthorEntry = {
        id: 'author-1',
        type: 'person',
        orcid: '0000-0001-2345-6789',
        firstName: 'John',
        lastName: 'Doe',
        email: 'john@example.com',
        website: '',
        isContact: false,
        orcidVerified: true,
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

    afterEach(async () => {
        vi.useFakeTimers();
        vi.runAllTimers();
        vi.useRealTimers();
        cleanup();
        await waitFor(() => Promise.resolve());
    });

    it('renders OrcidSuggestionsButton when pendingOrcidData is present', () => {
        render(<AuthorItem author={mockPersonAuthor} {...mockProps} />);

        expect(screen.getByText('ORCID Suggestions')).toBeInTheDocument();
    });
});
