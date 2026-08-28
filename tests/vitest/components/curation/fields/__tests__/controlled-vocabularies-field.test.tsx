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

        // Check that both keywords are displayed. Rock Physics also appears in the active tree.
        expect(screen.getAllByText('Rock Physics').length).toBeGreaterThan(0);
        expect(screen.getByText('Geochemistry')).toBeInTheDocument();
    });
});

describe('ControlledVocabulariesField - Initial selected keywords', () => {
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

    const mockGemetVocabulary: VocabularyKeyword[] = [
        {
            id: 'gemet-root',
            text: 'Environment',
            language: 'en',
            scheme: 'GEMET - GEneral Multilingual Environmental Thesaurus',
            schemeURI: 'http://www.eionet.europa.eu/gemet/concept/',
            description: 'Root group',
            children: [
                {
                    id: 'gemet-group',
                    text: 'Natural hazards',
                    language: 'en',
                    scheme: 'GEMET - GEneral Multilingual Environmental Thesaurus',
                    schemeURI: 'http://www.eionet.europa.eu/gemet/concept/',
                    description: 'Group',
                    children: [
                        {
                            id: 'gemet-earthquake',
                            text: 'earthquake',
                            language: 'en',
                            scheme: 'GEMET - GEneral Multilingual Environmental Thesaurus',
                            schemeURI: 'http://www.eionet.europa.eu/gemet/concept/',
                            description: 'Concept',
                            children: [],
                        },
                    ],
                },
            ],
        },
    ];

    it('activates the first visible tab with selected keywords and expands the selected tree path', () => {
        const selectedGemetKeywords: SelectedKeyword[] = [
            {
                id: 'gemet-earthquake',
                text: 'earthquake',
                path: 'Environment > Natural hazards > earthquake',
                language: 'en',
                scheme: 'GEMET - GEneral Multilingual Environmental Thesaurus',
                schemeURI: 'http://www.eionet.europa.eu/gemet/concept/',
            },
        ];

        render(
            <ControlledVocabulariesField
                scienceKeywords={mockScienceKeywords}
                platforms={[]}
                instruments={[]}
                gemetVocabulary={mockGemetVocabulary}
                selectedKeywords={selectedGemetKeywords}
                onChange={vi.fn()}
                showGemetTab={true}
                enabledThesauri={{
                    science_keywords: true,
                    platforms: true,
                    instruments: true,
                    chronostratigraphy: true,
                    gemet: true,
                    analytical_methods: true,
                    euroscivoc: true,
                    simple_lithology: true,
                }}
            />,
        );

        expect(screen.getByRole('tab', { name: /GEMET/i })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByText('Natural hazards')).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'earthquake' })).toBeChecked();
    });
});

describe('ControlledVocabulariesField - multilingual concept variants', () => {
    const conceptId = 'https://example.test/concepts/earthquake';
    const scheme = 'GCMD Science Keywords';
    const schemeURI = 'https://example.test/schemes/science';
    const scienceKeywords: VocabularyKeyword[] = [
        {
            id: conceptId,
            text: 'Earthquake',
            language: 'en',
            scheme,
            schemeURI,
            description: '',
            children: [],
        },
    ];
    const englishKeyword: SelectedKeyword = {
        id: conceptId,
        text: 'Earthquake',
        path: 'Earthquake',
        language: 'en',
        scheme,
        schemeURI,
    };
    const germanKeyword: SelectedKeyword = {
        id: conceptId,
        text: 'Earthquake',
        path: 'Earthquake',
        language: 'de',
        scheme,
        schemeURI,
    };

    it('removes only the selected language variant from its badge', async () => {
        const onChange = vi.fn();
        const user = userEvent.setup();

        render(
            <ControlledVocabulariesField
                scienceKeywords={scienceKeywords}
                platforms={[]}
                instruments={[]}
                selectedKeywords={[englishKeyword, germanKeyword]}
                onChange={onChange}
            />,
        );

        const removeButtons = screen.getAllByRole('button', { name: 'Remove Earthquake' });
        expect(removeButtons).toHaveLength(2);

        await user.click(removeButtons[1]);

        expect(onChange).toHaveBeenCalledWith([englishKeyword]);
    });

    it('toggles only the exact vocabulary variant when concept ids are shared', async () => {
        const onChange = vi.fn();
        const user = userEvent.setup();

        render(
            <ControlledVocabulariesField
                scienceKeywords={scienceKeywords}
                platforms={[]}
                instruments={[]}
                selectedKeywords={[englishKeyword, germanKeyword]}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByRole('checkbox', { name: 'Earthquake' }));

        expect(onChange).toHaveBeenCalledWith([germanKeyword]);
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
                enabledThesauri={{ science_keywords: true, platforms: true, instruments: true, chronostratigraphy: true, gemet: true, analytical_methods: true, euroscivoc: true, simple_lithology: true }}
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
                enabledThesauri={{ science_keywords: true, platforms: true, instruments: true, chronostratigraphy: true, gemet: true, analytical_methods: true, euroscivoc: true, simple_lithology: true }}
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
                enabledThesauri={{ science_keywords: true, platforms: true, instruments: true, chronostratigraphy: true, gemet: true, analytical_methods: true, euroscivoc: true, simple_lithology: true }}
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
                enabledThesauri={{ science_keywords: true, platforms: true, instruments: true, chronostratigraphy: true, gemet: true, analytical_methods: true, euroscivoc: true, simple_lithology: true }}
            />,
        );

        const euroSciVocTab = screen.getByRole('tab', { name: /EuroSciVoc/i });
        await user.click(euroSciVocTab);

        expect(euroSciVocTab).toHaveAttribute('aria-selected', 'true');
    });
});

describe('ControlledVocabulariesField - CGI Simple Lithology Tab', () => {
    const simpleLithologyVocabulary: VocabularyKeyword[] = [{
        id: 'http://resource.geosciml.org/classifier/cgi/lithology/rock',
        text: 'Rock',
        language: 'en',
        scheme: 'CGI Simple Lithology',
        schemeURI: 'http://resource.geosciml.org/classifierscheme/cgi/2016.01/simplelithology',
        description: '',
        children: [],
    }];

    it('shows the tab only when the vocabulary is enabled and available', () => {
        const props = {
            scienceKeywords: [] as VocabularyKeyword[],
            platforms: [] as VocabularyKeyword[],
            instruments: [] as VocabularyKeyword[],
            simpleLithologyVocabulary,
            selectedKeywords: [] as SelectedKeyword[],
            onChange: vi.fn(),
            enabledThesauri: {
                science_keywords: true,
                platforms: true,
                instruments: true,
                chronostratigraphy: true,
                gemet: true,
                analytical_methods: true,
                euroscivoc: true,
                simple_lithology: true,
            },
        };
        const { rerender } = render(
            <ControlledVocabulariesField {...props} showSimpleLithologyTab={false} />,
        );

        expect(screen.queryByRole('tab', { name: /Simple Lithology/i })).not.toBeInTheDocument();

        rerender(
            <ControlledVocabulariesField {...props} showSimpleLithologyTab={true} />,
        );

        expect(screen.getByRole('tab', { name: /Simple Lithology/i })).toBeInTheDocument();
    });

    it('keeps selected legacy keywords visible even when the tab is disabled', () => {
        const selectedKeyword: SelectedKeyword = {
            id: 'legacy:historical-rock',
            text: 'Historical rock',
            path: 'Rock > Historical rock',
            language: 'en',
            scheme: 'CGI Simple Lithology',
            schemeURI: 'http://resource.geosciml.org/classifierscheme/cgi/2016.01/simplelithology',
            isLegacy: true,
        };

        render(
            <ControlledVocabulariesField
                scienceKeywords={[]}
                platforms={[]}
                instruments={[]}
                simpleLithologyVocabulary={simpleLithologyVocabulary}
                selectedKeywords={[selectedKeyword]}
                onChange={vi.fn()}
                showSimpleLithologyTab={false}
            />,
        );

        expect(screen.getByText('Rock > Historical rock')).toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: /Simple Lithology/i })).not.toBeInTheDocument();
    });
});
