import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import MSLLaboratoriesField from '@/components/curation/fields/msl-laboratories-field';
import type { MSLLaboratory, MSLLaboratoryVocabularyEntry } from '@/types';

const mockUseMslLaboratories = vi.hoisted(() => vi.fn());

vi.mock('@/hooks/use-msl-laboratories', () => ({
    useMSLLaboratories: mockUseMslLaboratories,
}));

const LABORATORIES: MSLLaboratoryVocabularyEntry[] = [
    {
        identifier: 'opaque-rock-gfz',
        name: 'Rock Lab',
        display_name: 'Rock Lab — GFZ',
        affiliation_name: 'GFZ German Research Centre for Geosciences',
        affiliation_ror: 'https://ror.org/04z8jg394',
        scientific_domain: 'Rock physics',
        country: 'Germany',
    },
    {
        identifier: 'opaque-rock-utrecht',
        name: 'Rock Lab',
        display_name: 'Rock Lab — Utrecht University',
        affiliation_name: 'Utrecht University',
        affiliation_ror: null,
        scientific_domain: 'Geochemistry',
        country: 'Netherlands',
    },
    {
        identifier: 'opaque-seismo-ingv',
        name: 'Seismology Lab',
        display_name: 'Seismology Lab — INGV',
        affiliation_name: 'Istituto Nazionale di Geofisica e Vulcanologia',
        affiliation_ror: 'https://ror.org/00bv4wp39',
        scientific_domain: 'Seismology',
        country: 'Italy',
    },
];

const AVAILABLE_RESULT = {
    laboratories: LABORATORIES,
    version: '1.1',
    lastUpdated: '2026-07-21T12:00:00+00:00',
    isLoading: false,
    isUnavailable: false,
    error: null,
    refetch: vi.fn(),
};

describe('MSLLaboratoriesField', () => {
    const onChange = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        mockUseMslLaboratories.mockReturnValue(AVAILABLE_RESULT);
    });

    const renderField = (selectedLaboratories: MSLLaboratory[] = [], isVocabularyAvailable = true) =>
        render(
            <MSLLaboratoriesField selectedLaboratories={selectedLaboratories} onChange={onChange} isVocabularyAvailable={isVocabularyAvailable} />,
        );

    const openLaboratorySearch = async (user = userEvent.setup()) => {
        await user.click(screen.getByRole('combobox', { name: 'Add Laboratory' }));
        return user;
    };

    it('shows the local vocabulary source and version', () => {
        renderField();

        expect(screen.getByText(/locally managed copy/i)).toBeInTheDocument();
        expect(screen.getByText('Utrecht University MSL Vocabularies')).toHaveAttribute(
            'href',
            'https://github.com/UtrechtUniversity/msl_vocabularies',
        );
        expect(screen.getByText(/Vocabulary version 1.1/)).toBeInTheDocument();
    });

    it('uses unique display names and rich metadata in the result list', async () => {
        renderField();
        await openLaboratorySearch();

        expect(screen.getByText('Rock Lab — GFZ')).toBeInTheDocument();
        expect(screen.getByText('Rock Lab — Utrecht University')).toBeInTheDocument();
        expect(screen.getByText('GFZ German Research Centre for Geosciences')).toBeInTheDocument();
        expect(screen.getAllByText('Rock physics').length).toBeGreaterThan(1);
        expect(screen.getAllByText('Germany').length).toBeGreaterThan(1);
    });

    it.each([
        ['Rock Lab', 'Rock Lab — GFZ'],
        ['Utrecht University', 'Rock Lab — Utrecht University'],
        ['Geochemistry', 'Rock Lab — Utrecht University'],
        ['Netherlands', 'Rock Lab — Utrecht University'],
        ['opaque-seismo-ingv', 'Seismology Lab — INGV'],
        ['Rock Lab — GFZ', 'Rock Lab — GFZ'],
    ])('finds a laboratory by %s', async (query, expectedDisplayName) => {
        renderField();
        const user = await openLaboratorySearch();

        await user.type(screen.getByPlaceholderText('Search laboratories...'), query);

        expect(screen.getByText(expectedDisplayName)).toBeInTheDocument();
    });

    it('combines scientific-domain and country filters', async () => {
        const user = userEvent.setup();
        renderField();

        await user.selectOptions(screen.getByLabelText('Scientific domain'), 'Geochemistry');
        await user.selectOptions(screen.getByLabelText('Country'), 'Netherlands');
        await openLaboratorySearch(user);

        expect(screen.getByText('Rock Lab — Utrecht University')).toBeInTheDocument();
        expect(screen.queryByText('Rock Lab — GFZ')).not.toBeInTheDocument();
        expect(screen.queryByText('Seismology Lab — INGV')).not.toBeInTheDocument();
    });

    it('keeps duplicate names uniquely selectable by identifier and stores only resource fields', async () => {
        renderField();
        const user = await openLaboratorySearch();
        await user.click(screen.getByText('Rock Lab — Utrecht University'));

        expect(onChange).toHaveBeenCalledWith([
            {
                identifier: 'opaque-rock-utrecht',
                name: 'Rock Lab',
                affiliation_name: 'Utrecht University',
                affiliation_ror: null,
            },
        ]);
    });

    it('removes selected identifiers from the result list', async () => {
        renderField([
            {
                identifier: 'opaque-rock-gfz',
                name: 'Rock Lab',
                affiliation_name: 'GFZ German Research Centre for Geosciences',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
        ]);

        await openLaboratorySearch();

        expect(screen.queryByRole('option', { name: /Rock Lab — GFZ/ })).not.toBeInTheDocument();
        expect(screen.getByText('Rock Lab — Utrecht University')).toBeInTheDocument();
    });

    it('enriches selected cards with current display name, domain, country, and a valid ROR link', () => {
        renderField([
            {
                identifier: 'opaque-rock-gfz',
                name: 'Stored Rock Lab',
                affiliation_name: 'Stored GFZ',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
        ]);

        expect(screen.getByRole('heading', { name: 'Rock Lab — GFZ' })).toBeInTheDocument();
        expect(screen.getAllByText('Rock physics').length).toBeGreaterThan(1);
        expect(screen.getAllByText('Germany').length).toBeGreaterThan(1);
        expect(screen.getByRole('link', { name: /ROR: 04z8jg394/ })).toHaveAttribute('href', 'https://ror.org/04z8jg394');
        expect(screen.getByText('opaque-rock-gfz')).toBeInTheDocument();
    });

    it('does not create a link for an invalid or missing ROR value', () => {
        renderField([
            {
                identifier: 'historic-lab',
                name: 'Historic Lab',
                affiliation_name: 'Historic Institution',
                affiliation_ror: 'javascript:alert(1)',
            },
        ]);

        expect(screen.getByText('No valid ROR ID available')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /ROR:/ })).not.toBeInTheDocument();
    });

    it('marks a stored laboratory that is absent from the current vocabulary', () => {
        renderField([
            {
                identifier: 'historic-lab',
                name: 'Historic Lab',
                affiliation_name: 'Historic Institution',
                affiliation_ror: null,
            },
        ]);

        expect(screen.getByText('Historic Lab')).toBeInTheDocument();
        expect(screen.getByText('Not present in the current MSL vocabulary')).toBeInTheDocument();
    });

    it('keeps stored cards visible after a loading failure', () => {
        mockUseMslLaboratories.mockReturnValue({
            ...AVAILABLE_RESULT,
            laboratories: null,
            error: 'Request failed with status 500',
        });

        renderField([
            {
                identifier: 'historic-lab',
                name: 'Historic Lab',
                affiliation_name: 'Historic Institution',
                affiliation_ror: null,
            },
        ]);

        expect(screen.getByText('Historic Lab')).toBeInTheDocument();
        expect(screen.getByText('Unable to load laboratory data')).toBeInTheDocument();
        expect(screen.queryByText('Not present in the current MSL vocabulary')).not.toBeInTheDocument();
    });

    it('prevents additions but preserves stored cards when disabled in settings', () => {
        mockUseMslLaboratories.mockReturnValue({
            ...AVAILABLE_RESULT,
            laboratories: null,
            isUnavailable: true,
        });

        renderField(
            [
                {
                    identifier: 'historic-lab',
                    name: 'Historic Lab',
                    affiliation_name: 'Historic Institution',
                    affiliation_ror: null,
                },
            ],
            false,
        );

        expect(mockUseMslLaboratories).toHaveBeenCalledWith({ enabled: false });
        expect(screen.getByText('Historic Lab')).toBeInTheDocument();
        expect(screen.getByText('Laboratory vocabulary unavailable')).toBeInTheDocument();
        expect(screen.queryByText('Add Laboratory')).not.toBeInTheDocument();
    });

    it('removes a stored laboratory without affecting the others', async () => {
        const selected = [
            {
                identifier: 'opaque-rock-gfz',
                name: 'Rock Lab',
                affiliation_name: 'GFZ German Research Centre for Geosciences',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
            {
                identifier: 'opaque-seismo-ingv',
                name: 'Seismology Lab',
                affiliation_name: 'INGV',
                affiliation_ror: 'https://ror.org/00bv4wp39',
            },
        ];
        const user = userEvent.setup();
        renderField(selected);

        await user.click(screen.getByRole('button', { name: 'Remove Rock Lab — GFZ (opaque-rock-gfz)' }));

        expect(onChange).toHaveBeenCalledWith([selected[1]]);
    });

    it('gives duplicate-name removal controls unique accessible names', () => {
        renderField([
            {
                identifier: 'opaque-rock-gfz',
                name: 'Rock Lab',
                affiliation_name: 'GFZ German Research Centre for Geosciences',
                affiliation_ror: 'https://ror.org/04z8jg394',
            },
            {
                identifier: 'opaque-rock-utrecht',
                name: 'Rock Lab',
                affiliation_name: 'Utrecht University',
                affiliation_ror: null,
            },
        ]);

        expect(screen.getByRole('button', { name: 'Remove Rock Lab — GFZ (opaque-rock-gfz)' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove Rock Lab — Utrecht University (opaque-rock-utrecht)' })).toBeInTheDocument();
    });
});
