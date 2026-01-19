import { render, screen } from '@testing-library/react';
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

    it('renders combobox trigger button', () => {
        render(<MSLLaboratoriesField {...defaultProps} />);

        // The Combobox shows a button trigger
        expect(screen.getByRole('combobox')).toBeInTheDocument();
    });

    it('shows empty state when no laboratories selected', () => {
        render(<MSLLaboratoriesField {...defaultProps} />);

        expect(screen.getByText(/No laboratories selected yet/)).toBeInTheDocument();
    });

    it('opens combobox dropdown when clicked', async () => {
        const user = userEvent.setup();
        render(<MSLLaboratoriesField {...defaultProps} />);

        const comboboxTrigger = screen.getByRole('combobox');
        await user.click(comboboxTrigger);

        // Check that the dropdown opened with laboratory options
        expect(screen.getByText('TecLab Rock Physics')).toBeInTheDocument();
    });

    it('displays laboratories in dropdown', async () => {
        const user = userEvent.setup();
        render(<MSLLaboratoriesField {...defaultProps} />);

        const comboboxTrigger = screen.getByRole('combobox');
        await user.click(comboboxTrigger);

        expect(screen.getByText('TecLab Rock Physics')).toBeInTheDocument();
        expect(screen.getByText('INGV Seismology Lab')).toBeInTheDocument();
        expect(screen.getByText('Utrecht Geodynamics Lab')).toBeInTheDocument();
    });

    it('calls onChange when laboratory is selected from dropdown', async () => {
        const user = userEvent.setup();
        render(<MSLLaboratoriesField {...defaultProps} />);

        const comboboxTrigger = screen.getByRole('combobox');
        await user.click(comboboxTrigger);

        // Click on a laboratory option
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

    it('excludes already selected laboratories from dropdown options', async () => {
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

        const comboboxTrigger = screen.getByRole('combobox');
        await user.click(comboboxTrigger);

        // The selected lab should not appear in the dropdown
        expect(screen.queryByRole('option', { name: /TecLab Rock Physics/i })).not.toBeInTheDocument();
        // Other labs should still be available
        expect(screen.getByText('INGV Seismology Lab')).toBeInTheDocument();
    });

    it('handles multiple selected laboratories', () => {
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
