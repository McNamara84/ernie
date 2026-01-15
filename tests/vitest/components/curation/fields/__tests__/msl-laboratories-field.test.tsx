import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import MSLLaboratoriesField from '@/components/curation/fields/msl-laboratories-field';
import type { MSLLaboratory } from '@/types';

// Mock the hook
vi.mock('@/hooks/use-msl-laboratories', () => ({
    useMSLLaboratories: vi.fn(() => ({
        laboratories: [
            {
                identifier: 'lab1',
                name: 'TecLab Rock Physics',
                affiliation_name: 'GFZ German Research Centre for Geosciences',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
            {
                identifier: 'lab2',
                name: 'INGV Seismology Lab',
                affiliation_name: 'Istituto Nazionale di Geofisica e Vulcanologia',
                affiliation_ror: 'https://ror.org/00bv4wp39',
            },
            {
                identifier: 'lab3',
                name: 'Utrecht Geodynamics Lab',
                affiliation_name: 'Utrecht University',
                affiliation_ror: 'https://ror.org/04pp8hn57',
            },
        ],
        isLoading: false,
        error: null,
        refetch: vi.fn(),
    })),
}));

describe('MSLLaboratoriesField', () => {
    const mockOnChange = vi.fn();
    const defaultProps = {
        selectedLaboratories: [] as MSLLaboratory[],
        onChange: mockOnChange,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the component', () => {
        render(<MSLLaboratoriesField {...defaultProps} />);

        expect(screen.getByText('Add Laboratory')).toBeInTheDocument();
    });

    it('displays info banner about MSL source', () => {
        render(<MSLLaboratoriesField {...defaultProps} />);

        expect(screen.getByText(/Select the multi-scale laboratories/)).toBeInTheDocument();
        expect(screen.getByText('Utrecht University MSL Vocabularies')).toBeInTheDocument();
    });

    it('renders search input', () => {
        render(<MSLLaboratoriesField {...defaultProps} />);

        expect(screen.getByPlaceholderText(/Search for a laboratory/)).toBeInTheDocument();
    });

    it('shows empty state when no laboratories selected', () => {
        render(<MSLLaboratoriesField {...defaultProps} />);

        expect(screen.getByText(/No laboratories selected yet/)).toBeInTheDocument();
    });

    it('displays search results when typing', async () => {
        const user = userEvent.setup();
        render(<MSLLaboratoriesField {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/Search for a laboratory/);
        await user.type(searchInput, 'TecLab');

        await waitFor(() => {
            expect(screen.getByText('TecLab Rock Physics')).toBeInTheDocument();
        });
    });

    it('displays "no results" message when search yields nothing', async () => {
        const user = userEvent.setup();
        render(<MSLLaboratoriesField {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/Search for a laboratory/);
        await user.type(searchInput, 'NonExistentLab');

        await waitFor(() => {
            expect(screen.getByText(/No laboratories found matching/)).toBeInTheDocument();
        });
    });

    it('calls onChange when laboratory is selected', async () => {
        const user = userEvent.setup();
        render(<MSLLaboratoriesField {...defaultProps} />);

        const searchInput = screen.getByPlaceholderText(/Search for a laboratory/);
        await user.type(searchInput, 'TecLab');

        await waitFor(() => {
            expect(screen.getByText('TecLab Rock Physics')).toBeInTheDocument();
        });

        await user.click(screen.getByText('TecLab Rock Physics'));

        expect(mockOnChange).toHaveBeenCalledWith([
            expect.objectContaining({
                identifier: 'lab1',
                name: 'TecLab Rock Physics',
            }),
        ]);
    });

    it('displays selected laboratories', () => {
        const selectedLabs: MSLLaboratory[] = [
            {
                identifier: 'lab1',
                name: 'TecLab Rock Physics',
                affiliation_name: 'GFZ German Research Centre for Geosciences',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
        ];

        render(<MSLLaboratoriesField {...defaultProps} selectedLaboratories={selectedLabs} />);

        expect(screen.getByText('Selected Laboratories (1)')).toBeInTheDocument();
        expect(screen.getByText('🔬 TecLab Rock Physics')).toBeInTheDocument();
    });

    it('displays affiliation name for selected laboratory', () => {
        const selectedLabs: MSLLaboratory[] = [
            {
                identifier: 'lab1',
                name: 'TecLab Rock Physics',
                affiliation_name: 'GFZ German Research Centre for Geosciences',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
        ];

        render(<MSLLaboratoriesField {...defaultProps} selectedLaboratories={selectedLabs} />);

        expect(screen.getByText('GFZ German Research Centre for Geosciences')).toBeInTheDocument();
    });

    it('displays ROR badge with link', () => {
        const selectedLabs: MSLLaboratory[] = [
            {
                identifier: 'lab1',
                name: 'TecLab Rock Physics',
                affiliation_name: 'GFZ German Research Centre for Geosciences',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
        ];

        render(<MSLLaboratoriesField {...defaultProps} selectedLaboratories={selectedLabs} />);

        expect(screen.getByText(/ROR: 04z8jg394/)).toBeInTheDocument();
    });

    it('shows "No ROR ID available" badge when affiliation has no ROR', () => {
        const selectedLabs: MSLLaboratory[] = [
            {
                identifier: 'lab1',
                name: 'Test Lab',
                affiliation_name: 'Unknown Institution',
                affiliation_ror: '',
            },
        ];

        render(<MSLLaboratoriesField {...defaultProps} selectedLaboratories={selectedLabs} />);

        expect(screen.getByText(/No ROR ID available/)).toBeInTheDocument();
    });

    it('calls onChange to remove laboratory when remove button is clicked', async () => {
        const user = userEvent.setup();
        const selectedLabs: MSLLaboratory[] = [
            {
                identifier: 'lab1',
                name: 'TecLab Rock Physics',
                affiliation_name: 'GFZ German Research Centre for Geosciences',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
        ];

        render(<MSLLaboratoriesField {...defaultProps} selectedLaboratories={selectedLabs} />);

        const removeButton = screen.getByRole('button', { name: /Remove TecLab Rock Physics/i });
        await user.click(removeButton);

        expect(mockOnChange).toHaveBeenCalledWith([]);
    });

    it('excludes already selected laboratories from search results', async () => {
        const user = userEvent.setup();
        const selectedLabs: MSLLaboratory[] = [
            {
                identifier: 'lab1',
                name: 'TecLab Rock Physics',
                affiliation_name: 'GFZ German Research Centre for Geosciences',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
        ];

        render(<MSLLaboratoriesField {...defaultProps} selectedLaboratories={selectedLabs} />);

        const searchInput = screen.getByPlaceholderText(/Search for a laboratory/);
        await user.type(searchInput, 'Lab');

        await waitFor(() => {
            // Should show dropdown with results
            expect(screen.getByText(/result/)).toBeInTheDocument();
        });

        // The already selected lab should NOT be in the dropdown as a button to add
        // Get all buttons in the dropdown that could add a laboratory
        const dropdownButtons = screen.getAllByRole('button');
        // Filter to buttons that have TecLab in them AND are for adding (not removing)
        const tecLabAddButtons = dropdownButtons.filter(btn => {
            const text = btn.textContent || '';
            // Remove buttons have X icon and are in the selected card section
            const isRemoveButton = btn.querySelector('svg.lucide-x') !== null;
            return text.includes('TecLab Rock Physics') && !isRemoveButton;
        });
        expect(tecLabAddButtons.length).toBe(0); // Not in dropdown results
    });

    it('displays multiple selected laboratories', () => {
        const selectedLabs: MSLLaboratory[] = [
            {
                identifier: 'lab1',
                name: 'TecLab Rock Physics',
                affiliation_name: 'GFZ German Research Centre for Geosciences',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
            {
                identifier: 'lab2',
                name: 'INGV Seismology Lab',
                affiliation_name: 'Istituto Nazionale di Geofisica e Vulcanologia',
                affiliation_ror: 'https://ror.org/00bv4wp39',
            },
        ];

        render(<MSLLaboratoriesField {...defaultProps} selectedLaboratories={selectedLabs} />);

        expect(screen.getByText('Selected Laboratories (2)')).toBeInTheDocument();
        expect(screen.getByText('🔬 TecLab Rock Physics')).toBeInTheDocument();
        expect(screen.getByText('🔬 INGV Seismology Lab')).toBeInTheDocument();
    });
});
