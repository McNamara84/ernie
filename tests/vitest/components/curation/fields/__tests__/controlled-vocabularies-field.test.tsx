import '@testing-library/jest-dom/vitest';

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import ControlledVocabulariesField from '@/components/curation/fields/controlled-vocabularies-field';
import type { SelectedKeyword, VocabularyKeyword } from '@/types/vocabulary';

// Mock useDebounce to return the value immediately
vi.mock('@/hooks/use-debounce', () => ({
    useDebounce: (value: string) => value,
}));

describe('ControlledVocabulariesField - MSL Tab Auto-Switch', () => {
    const mockScienceKeywords: VocabularyKeyword[] = [
        {
            id: 'sci-1',
            text: 'Earth Science',
            language: 'en',
            scheme: 'GCMD Science Keywords',
            schemeURI: 'https://gcmd.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            description: 'Science keyword',
            children: [],
        },
    ];

    const mockPlatforms: VocabularyKeyword[] = [
        {
            id: 'plat-1',
            text: 'Satellites',
            language: 'en',
            scheme: 'GCMD Platforms',
            schemeURI: 'https://gcmd.nasa.gov/kms/concepts/concept_scheme/platforms',
            description: 'Platform keyword',
            children: [],
        },
    ];

    const mockInstruments: VocabularyKeyword[] = [
        {
            id: 'inst-1',
            text: 'Spectrometer',
            language: 'en',
            scheme: 'GCMD Instruments',
            schemeURI: 'https://gcmd.nasa.gov/kms/concepts/concept_scheme/instruments',
            description: 'Instrument keyword',
            children: [],
        },
    ];

    const mockMslVocabulary: VocabularyKeyword[] = [
        {
            id: 'msl-1',
            text: 'Rock Physics',
            language: 'en',
            scheme: 'EPOS MSL vocabulary',
            schemeURI: 'https://www.multiscale-laboratories.org/',
            description: 'MSL keyword',
            children: [],
        },
    ];

    const mockSelectedKeywords: SelectedKeyword[] = [];
    const mockOnChange = vi.fn();

    it('should not show MSL tab when showMslTab is false', () => {
        render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                mslVocabulary={mockMslVocabulary}
                selectedKeywords={mockSelectedKeywords}
                onChange={mockOnChange}
                showMslTab={false}
            />,
        );

        expect(screen.queryByRole('tab', { name: /MSL Vocabulary/i })).not.toBeInTheDocument();
    });

    it('should show MSL tab when showMslTab is true', () => {
        render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                mslVocabulary={mockMslVocabulary}
                selectedKeywords={mockSelectedKeywords}
                onChange={mockOnChange}
                showMslTab={true}
            />,
        );

        expect(screen.getByRole('tab', { name: /MSL Vocabulary/i })).toBeInTheDocument();
    });

    it('should automatically switch to MSL tab when autoSwitchToMsl is true', async () => {
        const { rerender } = render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                mslVocabulary={mockMslVocabulary}
                selectedKeywords={mockSelectedKeywords}
                onChange={mockOnChange}
                showMslTab={false}
                autoSwitchToMsl={false}
            />,
        );

        // Initially, Science Keywords tab should be active
        const scienceTab = screen.getByRole('tab', { name: /Science Keywords/i });
        expect(scienceTab).toHaveAttribute('aria-selected', 'true');

        // Trigger MSL tab appearance with auto-switch
        rerender(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                mslVocabulary={mockMslVocabulary}
                selectedKeywords={mockSelectedKeywords}
                onChange={mockOnChange}
                showMslTab={true}
                autoSwitchToMsl={true}
            />,
        );

        // MSL tab should now be active
        await waitFor(() => {
            const mslTab = screen.getByRole('tab', { name: /MSL Vocabulary/i });
            expect(mslTab).toHaveAttribute('aria-selected', 'true');
        });
    });

    it('should not auto-switch if MSL tab was already visible', async () => {
        const { rerender } = render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                mslVocabulary={mockMslVocabulary}
                selectedKeywords={mockSelectedKeywords}
                onChange={mockOnChange}
                showMslTab={true}
                autoSwitchToMsl={false}
            />,
        );

        // MSL tab is visible, switch to it manually
        const mslTab = screen.getByRole('tab', { name: /MSL Vocabulary/i });
        const user = userEvent.setup();
        await user.click(mslTab);

        expect(mslTab).toHaveAttribute('aria-selected', 'true');

        // Now manually switch to Science Keywords
        const scienceTab = screen.getByRole('tab', { name: /Science Keywords/i });
        await user.click(scienceTab);

        expect(scienceTab).toHaveAttribute('aria-selected', 'true');

        // Trigger autoSwitchToMsl without changing showMslTab
        rerender(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                mslVocabulary={mockMslVocabulary}
                selectedKeywords={mockSelectedKeywords}
                onChange={mockOnChange}
                showMslTab={true}
                autoSwitchToMsl={false}
            />,
        );

        // Science Keywords tab should still be active
        expect(scienceTab).toHaveAttribute('aria-selected', 'true');
    });

    it('should display green indicator on MSL tab when it has selected keywords', () => {
        const selectedMslKeywords: SelectedKeyword[] = [
            {
                id: 'msl-1',
                text: 'Rock Physics',
                path: 'Rock Physics',
                language: 'en',
                scheme: 'EPOS MSL vocabulary',
                schemeURI: 'https://www.multiscale-laboratories.org/',
            },
        ];

        render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                mslVocabulary={mockMslVocabulary}
                selectedKeywords={selectedMslKeywords}
                onChange={mockOnChange}
                showMslTab={true}
            />,
        );

        const mslTab = screen.getByRole('tab', { name: /MSL Vocabulary/i });
        // Check for the green indicator (aria-label or title)
        const indicator = mslTab.querySelector('[aria-label="Has keywords"]');
        expect(indicator).toBeInTheDocument();
    });

    it('should show selected MSL keywords in the display area', () => {
        const selectedMslKeywords: SelectedKeyword[] = [
            {
                id: 'msl-1',
                text: 'Rock Physics',
                path: 'Rock Physics',
                language: 'en',
                scheme: 'EPOS MSL vocabulary',
                schemeURI: 'https://www.multiscale-laboratories.org/',
            },
            {
                id: 'msl-2',
                text: 'Geochemistry',
                path: 'Geochemistry',
                language: 'en',
                scheme: 'EPOS MSL vocabulary',
                schemeURI: 'https://www.multiscale-laboratories.org/',
            },
        ];

        render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                mslVocabulary={mockMslVocabulary}
                selectedKeywords={selectedMslKeywords}
                onChange={mockOnChange}
                showMslTab={true}
            />,
        );

        // Check that both keywords are displayed
        expect(screen.getByText('Rock Physics')).toBeInTheDocument();
        expect(screen.getByText('Geochemistry')).toBeInTheDocument();
    });
});

describe('ControlledVocabulariesField - EuroSciVoc Tab', () => {
    const mockScienceKeywords: VocabularyKeyword[] = [
        {
            id: 'sci-1',
            text: 'Earth Science',
            language: 'en',
            scheme: 'GCMD Science Keywords',
            schemeURI: 'https://gcmd.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
            description: 'Science keyword',
            children: [],
        },
    ];

    const mockPlatforms: VocabularyKeyword[] = [];
    const mockInstruments: VocabularyKeyword[] = [];

    const mockEuroSciVocVocabulary: VocabularyKeyword[] = [
        {
            id: 'http://data.europa.eu/8mn/euroscivoc/concept/c_123',
            text: 'Physical Sciences',
            language: 'en',
            scheme: 'European Science Vocabulary (EuroSciVoc)',
            schemeURI: 'http://data.europa.eu/8mn/euroscivoc/40c0f173-baa3-48a3-9fe6-d6e8fb366a00',
            description: '',
            children: [
                {
                    id: 'http://data.europa.eu/8mn/euroscivoc/concept/c_456',
                    text: 'Astronomy',
                    language: 'en',
                    scheme: 'European Science Vocabulary (EuroSciVoc)',
                    schemeURI: 'http://data.europa.eu/8mn/euroscivoc/40c0f173-baa3-48a3-9fe6-d6e8fb366a00',
                    description: '',
                    children: [],
                },
            ],
        },
    ];

    const mockOnChange = vi.fn();

    it('should not show EuroSciVoc tab when showEuroSciVocTab is false', () => {
        render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                euroscivocVocabulary={mockEuroSciVocVocabulary}
                selectedKeywords={[]}
                onChange={mockOnChange}
                showEuroSciVocTab={false}
            />,
        );

        expect(screen.queryByRole('tab', { name: /EuroSciVoc/i })).not.toBeInTheDocument();
    });

    it('should show EuroSciVoc tab when showEuroSciVocTab is true', () => {
        render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                euroscivocVocabulary={mockEuroSciVocVocabulary}
                selectedKeywords={[]}
                onChange={mockOnChange}
                showEuroSciVocTab={true}
                enabledThesauri={{ science_keywords: true, platforms: true, instruments: true, chronostratigraphy: true, gemet: true, analytical_methods: true, euroscivoc: true }}
            />,
        );

        expect(screen.getByRole('tab', { name: /EuroSciVoc/i })).toBeInTheDocument();
    });

    it('should display green indicator on EuroSciVoc tab when it has selected keywords', () => {
        const selectedEuroSciVocKeywords: SelectedKeyword[] = [
            {
                id: 'http://data.europa.eu/8mn/euroscivoc/concept/c_123',
                text: 'Physical Sciences',
                path: 'Physical Sciences',
                language: 'en',
                scheme: 'European Science Vocabulary (EuroSciVoc)',
                schemeURI: 'http://data.europa.eu/8mn/euroscivoc/40c0f173-baa3-48a3-9fe6-d6e8fb366a00',
            },
        ];

        render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                euroscivocVocabulary={mockEuroSciVocVocabulary}
                selectedKeywords={selectedEuroSciVocKeywords}
                onChange={mockOnChange}
                showEuroSciVocTab={true}
                enabledThesauri={{ science_keywords: true, platforms: true, instruments: true, chronostratigraphy: true, gemet: true, analytical_methods: true, euroscivoc: true }}
            />,
        );

        const euroSciVocTab = screen.getByRole('tab', { name: /EuroSciVoc/i });
        const indicator = euroSciVocTab.querySelector('[aria-label="Has keywords"]');
        expect(indicator).toBeInTheDocument();
    });
    it('should show selected EuroSciVoc keywords in the display area', () => {
        const selectedEuroSciVocKeywords: SelectedKeyword[] = [
            {
                id: 'http://data.europa.eu/8mn/euroscivoc/concept/c_123',
                text: 'Physical Sciences',
                path: 'Natural Sciences > Physical Sciences',
                language: 'en',
                scheme: 'European Science Vocabulary (EuroSciVoc)',
                schemeURI: 'http://data.europa.eu/8mn/euroscivoc/40c0f173-baa3-48a3-9fe6-d6e8fb366a00',
            },
        ];

        render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                euroscivocVocabulary={mockEuroSciVocVocabulary}
                selectedKeywords={selectedEuroSciVocKeywords}
                onChange={mockOnChange}
                showEuroSciVocTab={true}
                enabledThesauri={{ science_keywords: true, platforms: true, instruments: true, chronostratigraphy: true, gemet: true, analytical_methods: true, euroscivoc: true }}
            />,
        );

        expect(screen.getByText('Natural Sciences > Physical Sciences')).toBeInTheDocument();
        // Label "EuroSciVoc:" appears in both the keyword group label and the tab
        const euroSciVocLabels = screen.getAllByText(/EuroSciVoc/);
        expect(euroSciVocLabels.length).toBeGreaterThanOrEqual(1);
    });

    it('should switch to EuroSciVoc tab on click', async () => {
        const user = userEvent.setup();

        render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={mockPlatforms}
                instruments={mockInstruments}
                euroscivocVocabulary={mockEuroSciVocVocabulary}
                selectedKeywords={[]}
                onChange={mockOnChange}
                showEuroSciVocTab={true}
                enabledThesauri={{ science_keywords: true, platforms: true, instruments: true, chronostratigraphy: true, gemet: true, analytical_methods: true, euroscivoc: true }}
            />,
        );

        const euroSciVocTab = screen.getByRole('tab', { name: /EuroSciVoc/i });
        await user.click(euroSciVocTab);

        expect(euroSciVocTab).toHaveAttribute('aria-selected', 'true');
    });
});
