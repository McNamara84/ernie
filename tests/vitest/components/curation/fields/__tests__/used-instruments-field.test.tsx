import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import UsedInstrumentsField from '@/components/curation/fields/used-instruments-field';
import type { InstrumentSelection } from '@/types';

// Mock the hook
const mockRefetch = vi.fn();
vi.mock('@/hooks/use-pid4inst-instruments', () => ({
    usePid4instInstruments: vi.fn(() => ({
        instruments: [
            {
                id: '123',
                pid: '21.T11998/0000-001A-2B3C-4',
                pidType: 'Handle',
                name: 'Magnetometer XYZ-500',
                description: 'A high-precision magnetometer',
                landingPage: 'https://b2inst.gwdg.de/records/123',
                owners: ['GFZ Potsdam'],
                manufacturers: ['Acme Instruments'],
                model: 'XYZ-500',
                instrumentTypes: ['Magnetometer'],
                measuredVariables: ['Magnetic field'],
            },
            {
                id: '456',
                pid: '21.T11998/0000-001A-2B3C-5',
                pidType: 'Handle',
                name: 'Seismometer ABC-100',
                description: 'Broadband seismometer',
                landingPage: 'https://b2inst.gwdg.de/records/456',
                owners: ['INGV'],
                manufacturers: ['Guralp'],
                model: 'ABC-100',
                instrumentTypes: ['Seismometer'],
                measuredVariables: ['Ground motion'],
            },
            {
                id: '789',
                pid: '21.T11998/0000-001A-2B3C-6',
                pidType: 'Handle',
                name: 'Spectrometer UV-2000',
                description: 'UV-Vis spectrometer',
                landingPage: 'https://b2inst.gwdg.de/records/789',
                owners: ['Utrecht University'],
                manufacturers: ['Shimadzu'],
                model: 'UV-2000',
                instrumentTypes: ['Spectrometer'],
                measuredVariables: ['UV absorption'],
            },
        ],
        isLoading: false,
        error: null,
        refetch: mockRefetch,
    })),
}));

describe('UsedInstrumentsField', () => {
    const mockOnChange = vi.fn();
    const defaultProps = {
        selectedInstruments: [] as InstrumentSelection[],
        onChange: mockOnChange,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the component with info banner', () => {
        render(<UsedInstrumentsField {...defaultProps} />);

        expect(screen.getByText(/Select the research instruments/)).toBeInTheDocument();
        expect(screen.getByText('b2inst Registry (PID4INST)')).toBeInTheDocument();
    });

    it('renders combobox trigger', () => {
        render(<UsedInstrumentsField {...defaultProps} />);

        expect(screen.getByRole('combobox')).toBeInTheDocument();
    });

    it('shows empty state when no instruments selected', () => {
        render(<UsedInstrumentsField {...defaultProps} />);

        expect(screen.getByText(/No instruments selected yet/)).toBeInTheDocument();
    });

    it('shows the Add Instrument label', () => {
        render(<UsedInstrumentsField {...defaultProps} />);

        expect(screen.getByText('Add Instrument')).toBeInTheDocument();
    });

    it('opens combobox dropdown when clicked and shows instruments', async () => {
        const user = userEvent.setup();
        render(<UsedInstrumentsField {...defaultProps} />);

        const comboboxTrigger = screen.getByRole('combobox');
        await user.click(comboboxTrigger);

        expect(await screen.findByText('Magnetometer XYZ-500')).toBeInTheDocument();
        expect(await screen.findByText('Seismometer ABC-100')).toBeInTheDocument();
        expect(await screen.findByText('Spectrometer UV-2000')).toBeInTheDocument();
    });

    it('calls onChange when an instrument is selected from the combobox', async () => {
        const user = userEvent.setup();
        render(<UsedInstrumentsField {...defaultProps} />);

        const comboboxTrigger = screen.getByRole('combobox');
        await user.click(comboboxTrigger);

        const option = await screen.findByText('Magnetometer XYZ-500');
        await user.click(option);

        expect(mockOnChange).toHaveBeenCalledWith([
            {
                pid: '21.T11998/0000-001A-2B3C-4',
                pidType: 'Handle',
                name: 'Magnetometer XYZ-500',
            },
        ]);
    });

    it('displays selected instruments as cards', () => {
        const selected: InstrumentSelection[] = [{ pid: '21.T11998/0000-001A-2B3C-4', pidType: 'Handle', name: 'Magnetometer XYZ-500' }];

        render(<UsedInstrumentsField selectedInstruments={selected} onChange={mockOnChange} />);

        expect(screen.getByText('Magnetometer XYZ-500')).toBeInTheDocument();
        expect(screen.getByText('Selected Instruments (1)')).toBeInTheDocument();
        expect(screen.getByText(/Handle: 21\.T11998\/0000-001A-2B3C-4/)).toBeInTheDocument();
    });

    it('displays correct count for multiple selected instruments', () => {
        const selected: InstrumentSelection[] = [
            { pid: '21.T11998/0000-001A-2B3C-4', pidType: 'Handle', name: 'Magnetometer XYZ-500' },
            { pid: '21.T11998/0000-001A-2B3C-5', pidType: 'Handle', name: 'Seismometer ABC-100' },
        ];

        render(<UsedInstrumentsField selectedInstruments={selected} onChange={mockOnChange} />);

        expect(screen.getByText('Selected Instruments (2)')).toBeInTheDocument();
    });

    it('calls onChange with instrument removed when remove button clicked', async () => {
        const user = userEvent.setup();
        const selected: InstrumentSelection[] = [
            { pid: '21.T11998/0000-001A-2B3C-4', pidType: 'Handle', name: 'Magnetometer XYZ-500' },
            { pid: '21.T11998/0000-001A-2B3C-5', pidType: 'Handle', name: 'Seismometer ABC-100' },
        ];

        render(<UsedInstrumentsField selectedInstruments={selected} onChange={mockOnChange} />);

        const removeButton = screen.getByRole('button', { name: /Remove Magnetometer XYZ-500/ });
        await user.click(removeButton);

        expect(mockOnChange).toHaveBeenCalledWith([{ pid: '21.T11998/0000-001A-2B3C-5', pidType: 'Handle', name: 'Seismometer ABC-100' }]);
    });

    it('excludes already selected instruments from dropdown options', async () => {
        const user = userEvent.setup();
        const selected: InstrumentSelection[] = [{ pid: '21.T11998/0000-001A-2B3C-4', pidType: 'Handle', name: 'Magnetometer XYZ-500' }];

        render(<UsedInstrumentsField selectedInstruments={selected} onChange={mockOnChange} />);

        const comboboxTrigger = screen.getByRole('combobox');
        await user.click(comboboxTrigger);

        // The already-selected instrument should not appear in the dropdown
        expect(screen.queryByRole('option', { name: /Magnetometer XYZ-500/ })).not.toBeInTheDocument();
        // Other instruments should still be available
        expect(await screen.findByText('Seismometer ABC-100')).toBeInTheDocument();
    });

    it('keeps matching PID values of different types separate when selecting and removing', async () => {
        const user = userEvent.setup();
        const selected: InstrumentSelection[] = [{ pid: '21.T11998/0000-001A-2B3C-4', pidType: 'DOI', name: 'Different identifier type' }];
        const { rerender } = render(<UsedInstrumentsField selectedInstruments={selected} onChange={mockOnChange} />);
        await user.click(screen.getByRole('combobox'));
        await user.click(await screen.findByRole('option', { name: /Magnetometer XYZ-500/ }));
        const handle = { pid: selected[0].pid, pidType: 'Handle', name: 'Magnetometer XYZ-500' };
        expect(mockOnChange).toHaveBeenLastCalledWith([...selected, handle]);
        rerender(<UsedInstrumentsField selectedInstruments={[...selected, handle]} onChange={mockOnChange} />);
        await user.click(screen.getByRole('button', { name: 'Remove Magnetometer XYZ-500' }));
        expect(mockOnChange).toHaveBeenLastCalledWith(selected);
    });

    it('generates correct Handle landing page URL', () => {
        const selected: InstrumentSelection[] = [{ pid: '21.T11998/0000-001A-2B3C-4', pidType: 'Handle', name: 'Magnetometer XYZ-500' }];

        render(<UsedInstrumentsField selectedInstruments={selected} onChange={mockOnChange} />);

        // Find the link inside the instrument card (not the info banner link)
        const links = screen.getAllByRole('link');
        const instrumentLink = links.find((link) => link.getAttribute('href')?.includes('hdl.handle.net'));
        expect(instrumentLink).toBeDefined();
        expect(instrumentLink).toHaveAttribute('href', 'https://hdl.handle.net/21.T11998/0000-001A-2B3C-4');
        expect(instrumentLink).toHaveAttribute('target', '_blank');
        expect(instrumentLink).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('does not show empty state when instruments are selected', () => {
        const selected: InstrumentSelection[] = [{ pid: '21.T11998/0000-001A-2B3C-4', pidType: 'Handle', name: 'Magnetometer XYZ-500' }];

        render(<UsedInstrumentsField selectedInstruments={selected} onChange={mockOnChange} />);

        expect(screen.queryByText(/No instruments selected yet/)).not.toBeInTheDocument();
    });

    describe('error handling', () => {
        it('shows error alert when hook returns an error', async () => {
            const { usePid4instInstruments } = await import('@/hooks/use-pid4inst-instruments');
            (usePid4instInstruments as unknown as ReturnType<typeof vi.fn>).mockReturnValue({
                instruments: null,
                isLoading: false,
                error: 'Instrument registry not yet downloaded. An administrator must first download it in Settings.',
                refetch: mockRefetch,
            });

            render(<UsedInstrumentsField {...defaultProps} />);

            expect(screen.getByText('Unable to load instrument data')).toBeInTheDocument();
            expect(screen.getByText(/Instrument registry not yet downloaded/)).toBeInTheDocument();
        });

        it('shows retry button on error and calls refetch', async () => {
            const { usePid4instInstruments } = await import('@/hooks/use-pid4inst-instruments');
            (usePid4instInstruments as unknown as ReturnType<typeof vi.fn>).mockReturnValue({
                instruments: null,
                isLoading: false,
                error: 'Failed to fetch',
                refetch: mockRefetch,
            });

            const user = userEvent.setup();
            render(<UsedInstrumentsField {...defaultProps} />);

            const retryButton = screen.getByRole('button', { name: /Retry/ });
            await user.click(retryButton);

            expect(mockRefetch).toHaveBeenCalled();
        });

        it('disables combobox when error is present', async () => {
            const { usePid4instInstruments } = await import('@/hooks/use-pid4inst-instruments');
            (usePid4instInstruments as unknown as ReturnType<typeof vi.fn>).mockReturnValue({
                instruments: null,
                isLoading: false,
                error: 'Some error',
                refetch: mockRefetch,
            });

            render(<UsedInstrumentsField {...defaultProps} />);

            const combobox = screen.getByRole('combobox');
            expect(combobox).toBeDisabled();
        });
    });

    describe('loading state', () => {
        it('disables combobox while loading', async () => {
            const { usePid4instInstruments } = await import('@/hooks/use-pid4inst-instruments');
            (usePid4instInstruments as unknown as ReturnType<typeof vi.fn>).mockReturnValue({
                instruments: null,
                isLoading: true,
                error: null,
                refetch: mockRefetch,
            });

            render(<UsedInstrumentsField {...defaultProps} />);

            const combobox = screen.getByRole('combobox');
            expect(combobox).toBeDisabled();
        });
    });

    describe('PID name resolution', () => {
        it('displays server-resolved names without overwriting the selection', async () => {
            const { usePid4instInstruments } = await import('@/hooks/use-pid4inst-instruments');
            (usePid4instInstruments as unknown as ReturnType<typeof vi.fn>).mockReturnValue({
                instruments: [
                    {
                        id: '123',
                        pid: '21.T11998/0000-001A-2B3C-4',
                        pidType: 'Handle',
                        name: 'Magnetometer XYZ-500',
                        description: '',
                        landingPage: '',
                        owners: [],
                        manufacturers: [],
                        model: null,
                        instrumentTypes: [],
                        measuredVariables: [],
                    },
                ],
                isLoading: false,
                error: null,
                refetch: mockRefetch,
            });

            // Simulate instrument imported via XML where name = pid (placeholder)
            const selected: InstrumentSelection[] = [
                { pid: '21.T11998/0000-001A-2B3C-4', pidType: 'Handle', name: 'Resolved name', resolution: { status: 'matched' } },
            ];

            render(<UsedInstrumentsField selectedInstruments={selected} onChange={mockOnChange} />);

            expect(screen.getByRole('heading', { name: 'Resolved name' })).toBeInTheDocument();
            expect(mockOnChange).not.toHaveBeenCalled();
        });
    });

    it.each([
        ['not_found', 'Instrument name not found in the local registry.'],
        ['unavailable', 'The local instrument registry is unavailable or disabled.'],
        ['ambiguous', 'Multiple registry records match this identifier.'],
        ['identifier_conflict', 'B2INST lists different identifiers for this instrument.'],
    ] as const)('shows the %s diagnosis while retaining the original PID', (status, message) => {
        render(
            <UsedInstrumentsField
                selectedInstruments={[
                    {
                        pid: '21.test/original',
                        pidType: 'Handle',
                        name: 'Instrument',
                        resolution: { status, identifiers: [{ value: '21.test/other', type: 'Handle', source: 'epic' }] },
                    },
                ]}
                onChange={mockOnChange}
            />,
        );
        expect(screen.getByText((text) => text.startsWith(message))).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Handle: 21.test\/original/ })).toHaveAttribute('href', 'https://hdl.handle.net/21.test/original');
        expect(mockOnChange).not.toHaveBeenCalled();
    });

    it('keeps existing instruments visible when selection is disabled and allows name retry', async () => {
        const resolveAgain = vi.fn();
        render(
            <UsedInstrumentsField
                selectedInstruments={[{ pid: '21.test/a', pidType: 'Handle', name: 'Retained' }]}
                selectionEnabled={false}
                onChange={mockOnChange}
                resolveAgain={resolveAgain}
            />,
        );
        expect(screen.getByRole('combobox')).toBeDisabled();
        expect(screen.getByRole('heading', { name: 'Retained' })).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Resolve names again' }));
        expect(resolveAgain).toHaveBeenCalledOnce();
    });
});
