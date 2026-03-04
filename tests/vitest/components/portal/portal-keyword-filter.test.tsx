/**
 * @vitest-environment jsdom
 */
import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalKeywordFilter } from '@/components/portal/PortalKeywordFilter';
import type { KeywordSuggestion } from '@/types/portal';

const createSuggestion = (overrides: Partial<KeywordSuggestion> = {}): KeywordSuggestion => ({
    value: 'Geophysics',
    scheme: null,
    count: 5,
    ...overrides,
});

describe('PortalKeywordFilter', () => {
    const suggestions: KeywordSuggestion[] = [
        createSuggestion({ value: 'Seismology', scheme: null, count: 3 }),
        createSuggestion({ value: 'Geology', scheme: null, count: 7 }),
        createSuggestion({ value: 'EARTH SCIENCE', scheme: 'Science Keywords', count: 12 }),
        createSuggestion({ value: 'SATELLITES', scheme: 'Platforms', count: 2 }),
        createSuggestion({ value: 'GPS RECEIVERS', scheme: 'Instruments', count: 4 }),
        createSuggestion({ value: 'Rock mechanics', scheme: 'EPOS MSL vocabulary', count: 6 }),
    ];

    const defaultProps = {
        suggestions,
        selectedKeywords: [] as string[],
        onKeywordsChange: vi.fn(),
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('rendering', () => {
        it('renders the Keywords label', () => {
            render(<PortalKeywordFilter {...defaultProps} />);

            expect(screen.getByText('Keywords')).toBeInTheDocument();
        });

        it('renders the combobox trigger button', () => {
            render(<PortalKeywordFilter {...defaultProps} />);

            expect(screen.getByRole('combobox')).toBeInTheDocument();
        });

        it('shows "Search keywords..." when no keywords selected', () => {
            render(<PortalKeywordFilter {...defaultProps} />);

            expect(screen.getByText('Search keywords...')).toBeInTheDocument();
        });

        it('shows selected count when keywords are selected', () => {
            render(<PortalKeywordFilter {...defaultProps} selectedKeywords={['Seismology', 'Geology']} />);

            expect(screen.getByText('2 keywords selected')).toBeInTheDocument();
        });

        it('shows singular form when one keyword is selected', () => {
            render(<PortalKeywordFilter {...defaultProps} selectedKeywords={['Seismology']} />);

            expect(screen.getByText('1 keyword selected')).toBeInTheDocument();
        });

        it('renders the helper text', () => {
            render(<PortalKeywordFilter {...defaultProps} />);

            expect(screen.getByText('Filter by free keywords, GCMD or MSL vocabularies')).toBeInTheDocument();
        });
    });

    describe('selected keyword chips', () => {
        it('does not render chips when no keywords are selected', () => {
            render(<PortalKeywordFilter {...defaultProps} />);

            expect(screen.queryByLabelText(/remove keyword/i)).not.toBeInTheDocument();
        });

        it('renders chips for selected keywords', () => {
            render(
                <PortalKeywordFilter
                    {...defaultProps}
                    selectedKeywords={['Seismology', 'Geology']}
                />,
            );

            expect(screen.getByText('Seismology')).toBeInTheDocument();
            expect(screen.getByText('Geology')).toBeInTheDocument();
        });

        it('renders remove button for each chip', () => {
            render(
                <PortalKeywordFilter
                    {...defaultProps}
                    selectedKeywords={['Seismology', 'Geology']}
                />,
            );

            expect(screen.getByLabelText('Remove keyword "Seismology"')).toBeInTheDocument();
            expect(screen.getByLabelText('Remove keyword "Geology"')).toBeInTheDocument();
        });

        it('calls onKeywordsChange without the removed keyword when chip remove is clicked', async () => {
            const user = userEvent.setup();
            const onKeywordsChange = vi.fn();

            render(
                <PortalKeywordFilter
                    {...defaultProps}
                    selectedKeywords={['Seismology', 'Geology']}
                    onKeywordsChange={onKeywordsChange}
                />,
            );

            await user.click(screen.getByLabelText('Remove keyword "Seismology"'));

            expect(onKeywordsChange).toHaveBeenCalledWith(['Geology']);
        });
    });

    describe('dropdown interaction', () => {
        it('opens the popover when combobox button is clicked', async () => {
            const user = userEvent.setup();
            render(<PortalKeywordFilter {...defaultProps} />);

            await user.click(screen.getByRole('combobox'));

            expect(screen.getByPlaceholderText('Type to filter keywords...')).toBeInTheDocument();
        });

        it('displays grouped suggestions with correct headings', async () => {
            const user = userEvent.setup();
            render(<PortalKeywordFilter {...defaultProps} />);

            await user.click(screen.getByRole('combobox'));

            expect(screen.getByText('Free Keywords')).toBeInTheDocument();
            expect(screen.getByText('GCMD Science Keywords')).toBeInTheDocument();
            expect(screen.getByText('GCMD Platforms')).toBeInTheDocument();
            expect(screen.getByText('GCMD Instruments')).toBeInTheDocument();
            expect(screen.getByText('MSL Vocabularies')).toBeInTheDocument();
        });

        it('displays suggestion values with counts', async () => {
            const user = userEvent.setup();
            render(<PortalKeywordFilter {...defaultProps} />);

            await user.click(screen.getByRole('combobox'));

            expect(screen.getByText('Seismology')).toBeInTheDocument();
            expect(screen.getByText('(3)')).toBeInTheDocument();
            expect(screen.getByText('EARTH SCIENCE')).toBeInTheDocument();
            expect(screen.getByText('(12)')).toBeInTheDocument();
        });

        it('calls onKeywordsChange with added keyword when an unselected item is clicked', async () => {
            const user = userEvent.setup();
            const onKeywordsChange = vi.fn();

            render(
                <PortalKeywordFilter
                    {...defaultProps}
                    onKeywordsChange={onKeywordsChange}
                />,
            );

            await user.click(screen.getByRole('combobox'));
            await user.click(screen.getByText('Seismology'));

            expect(onKeywordsChange).toHaveBeenCalledWith(['Seismology']);
        });

        it('calls onKeywordsChange without the keyword when a selected item is clicked (deselect)', async () => {
            const user = userEvent.setup();
            const onKeywordsChange = vi.fn();

            render(
                <PortalKeywordFilter
                    {...defaultProps}
                    selectedKeywords={['Seismology']}
                    onKeywordsChange={onKeywordsChange}
                />,
            );

            await user.click(screen.getByRole('combobox'));

            // "Seismology" appears both in the chip and the dropdown list.
            // Click the one inside the CommandItem (the second occurrence).
            const allSeismology = screen.getAllByText('Seismology');
            await user.click(allSeismology[allSeismology.length - 1]);

            expect(onKeywordsChange).toHaveBeenCalledWith([]);
        });

        it('preserves existing selection when adding a new keyword', async () => {
            const user = userEvent.setup();
            const onKeywordsChange = vi.fn();

            render(
                <PortalKeywordFilter
                    {...defaultProps}
                    selectedKeywords={['Seismology']}
                    onKeywordsChange={onKeywordsChange}
                />,
            );

            await user.click(screen.getByRole('combobox'));
            await user.click(screen.getByText('Geology'));

            expect(onKeywordsChange).toHaveBeenCalledWith(['Seismology', 'Geology']);
        });
    });

    describe('grouping', () => {
        it('places Free Keywords group first in the dropdown', async () => {
            const user = userEvent.setup();
            render(<PortalKeywordFilter {...defaultProps} />);

            await user.click(screen.getByRole('combobox'));

            // Verify Free Keywords heading appears before GCMD headings
            const freeKeywordsHeading = screen.getByText('Free Keywords');
            const gcmdHeading = screen.getByText('GCMD Science Keywords');

            // Free Keywords should appear before GCMD Science Keywords in the DOM
            expect(
                freeKeywordsHeading.compareDocumentPosition(gcmdHeading) &
                    Node.DOCUMENT_POSITION_FOLLOWING,
            ).toBeTruthy();
        });

        it('renders suggestions with no scheme under Free Keywords group', async () => {
            const user = userEvent.setup();
            render(
                <PortalKeywordFilter
                    {...defaultProps}
                    suggestions={[
                        createSuggestion({ value: 'MyFreeKeyword', scheme: null, count: 1 }),
                    ]}
                />,
            );

            await user.click(screen.getByRole('combobox'));

            expect(screen.getByText('Free Keywords')).toBeInTheDocument();
            expect(screen.getByText('MyFreeKeyword')).toBeInTheDocument();
        });

        it('handles empty suggestions array', () => {
            render(<PortalKeywordFilter {...defaultProps} suggestions={[]} />);

            expect(screen.getByRole('combobox')).toBeInTheDocument();
            expect(screen.getByText('Search keywords...')).toBeInTheDocument();
        });
    });

    describe('value deduplication', () => {
        it('deduplicates the same keyword value from different schemes, keeping the one with higher count', async () => {
            const user = userEvent.setup();
            const duplicateSuggestions: KeywordSuggestion[] = [
                createSuggestion({ value: 'Geochemistry', scheme: null, count: 3 }),
                createSuggestion({ value: 'Geochemistry', scheme: 'EPOS MSL vocabulary', count: 5 }),
            ];

            render(
                <PortalKeywordFilter
                    {...defaultProps}
                    suggestions={duplicateSuggestions}
                />,
            );

            await user.click(screen.getByRole('combobox'));

            // Only the MSL group should be rendered (higher count wins)
            expect(screen.queryByText('Free Keywords')).not.toBeInTheDocument();
            expect(screen.getByText('MSL Vocabularies')).toBeInTheDocument();

            // "Geochemistry" should appear only once
            const items = screen.getAllByText('Geochemistry');
            expect(items).toHaveLength(1);
        });

        it('keeps the free keyword when it has a higher count than the scheme variant', async () => {
            const user = userEvent.setup();
            const duplicateSuggestions: KeywordSuggestion[] = [
                createSuggestion({ value: 'Geochemistry', scheme: null, count: 10 }),
                createSuggestion({ value: 'Geochemistry', scheme: 'EPOS MSL vocabulary', count: 2 }),
            ];

            render(
                <PortalKeywordFilter
                    {...defaultProps}
                    suggestions={duplicateSuggestions}
                />,
            );

            await user.click(screen.getByRole('combobox'));

            // Only the Free Keywords group should be rendered (higher count wins)
            expect(screen.getByText('Free Keywords')).toBeInTheDocument();
            expect(screen.queryByText('MSL Vocabularies')).not.toBeInTheDocument();

            const items = screen.getAllByText('Geochemistry');
            expect(items).toHaveLength(1);
        });
    });
});
