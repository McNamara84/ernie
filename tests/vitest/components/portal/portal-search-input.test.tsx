/**
 * @vitest-environment jsdom
 */
import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalSearchInput } from '@/components/portal/PortalSearchInput';

const { axiosPostMock, suggestionsState } = vi.hoisted(() => ({
    axiosPostMock: vi.fn(),
    suggestionsState: {
        data: [
            { value: 'Seismology', scheme: null, count: 12 },
            { value: 'Paleoseismology', scheme: null, count: 3 },
        ],
        isFetching: false,
        isError: false,
    },
}));

vi.mock('axios', () => ({ default: { post: axiosPostMock } }));
vi.mock('@/hooks/use-portal-keyword-suggestions', () => ({ usePortalKeywordSuggestions: () => suggestionsState }));

function Harness({
    initialValue = '',
    selectedKeywords = [],
    onSubmit = vi.fn(),
    onKeywordSelect = vi.fn(),
    onKeywordsChange = vi.fn(),
}: {
    initialValue?: string;
    selectedKeywords?: string[];
    onSubmit?: (query: string) => void;
    onKeywordSelect?: (keyword: string) => void;
    onKeywordsChange?: (keywords: string[]) => void;
}) {
    const [value, setValue] = useState(initialValue);
    return (
        <PortalSearchInput
            value={value}
            onValueChange={setValue}
            onSubmit={onSubmit}
            selectedKeywords={selectedKeywords}
            onKeywordSelect={onKeywordSelect}
            onKeywordsChange={onKeywordsChange}
        />
    );
}

describe('PortalSearchInput', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        suggestionsState.data = [
            { value: 'Seismology', scheme: null, count: 12 },
            { value: 'Paleoseismology', scheme: null, count: 3 },
        ];
        suggestionsState.isFetching = false;
        suggestionsState.isError = false;
        axiosPostMock.mockResolvedValue({ status: 204 });
    });

    it('combines free text, exact keyword chips, and an icon-only search action', () => {
        render(<Harness initialValue="climate" selectedKeywords={['Seismology']} />);

        expect(screen.getByRole('combobox', { name: 'Search' })).toHaveValue('climate');
        expect(screen.getByText('Seismology')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Search' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /^Search$/ })).not.toHaveTextContent('Search');
    });

    it('submits arbitrary text and records analytics', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        render(<Harness initialValue="  climate change  " onSubmit={onSubmit} />);

        await user.click(screen.getByRole('button', { name: 'Search' }));

        expect(onSubmit).toHaveBeenCalledWith('climate change');
        expect(axiosPostMock).toHaveBeenCalledWith('/search/search-analytics', { search_term: 'climate change' });
    });

    it('shows asynchronous suggestions and turns a chosen suggestion into an exact keyword', async () => {
        const user = userEvent.setup();
        const onKeywordSelect = vi.fn();
        render(<Harness initialValue="seis" onKeywordSelect={onKeywordSelect} />);

        const input = screen.getByRole('combobox', { name: 'Search' });
        await user.click(input);
        await user.click(screen.getByRole('option', { name: /Seismology12/i }));

        expect(onKeywordSelect).toHaveBeenCalledWith('Seismology');
        expect(input).toHaveValue('');
    });

    it('selects only a keyboard-highlighted suggestion on Enter', async () => {
        const user = userEvent.setup();
        const onKeywordSelect = vi.fn();
        const onSubmit = vi.fn();
        render(<Harness initialValue="seis" onKeywordSelect={onKeywordSelect} onSubmit={onSubmit} />);

        const input = screen.getByRole('combobox', { name: 'Search' });
        await user.click(input);
        await user.keyboard('{ArrowDown}{Enter}');

        expect(onKeywordSelect).toHaveBeenCalledWith('Seismology');
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('uses Enter as a normal text search when no suggestion is highlighted', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        render(<Harness initialValue="custom phrase" onSubmit={onSubmit} />);

        await user.click(screen.getByRole('combobox', { name: 'Search' }));
        await user.keyboard('{Enter}');

        expect(onSubmit).toHaveBeenCalledWith('custom phrase');
    });

    it('removes exact keyword chips without changing the text query', async () => {
        const user = userEvent.setup();
        const onKeywordsChange = vi.fn();
        render(<Harness initialValue="climate" selectedKeywords={['Seismology', 'Geology']} onKeywordsChange={onKeywordsChange} />);

        const removeButton = screen.getByRole('button', { name: 'Remove keyword "Seismology"' });

        expect(removeButton).toHaveAttribute('data-slot', 'button');
        await user.click(removeButton);

        expect(onKeywordsChange).toHaveBeenCalledWith(['Geology']);
        expect(screen.getByRole('combobox', { name: 'Search' })).toHaveValue('climate');
    });

    it('does not open suggestions for fewer than two characters', async () => {
        const user = userEvent.setup();
        render(<Harness initialValue="s" />);

        const input = screen.getByRole('combobox', { name: 'Search' });

        await user.click(input);
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(input).not.toHaveAttribute('aria-controls');
        expect(input).not.toHaveAttribute('aria-activedescendant');
    });

    it('only exposes suggestion id references while the popup is visible', async () => {
        const user = userEvent.setup();
        render(<Harness initialValue="seis" />);

        const input = screen.getByRole('combobox', { name: 'Search' });
        expect(input).not.toHaveAttribute('aria-controls');
        expect(input).not.toHaveAttribute('aria-activedescendant');

        await user.click(input);
        const listbox = screen.getByRole('listbox', { name: 'Free keyword suggestions' });
        expect(input).toHaveAttribute('aria-controls', listbox.id);

        await user.keyboard('{ArrowDown}');
        expect(input).toHaveAttribute('aria-activedescendant', `${listbox.id}-0`);

        await user.keyboard('{Escape}');
        expect(screen.queryByRole('listbox', { name: 'Free keyword suggestions' })).not.toBeInTheDocument();
        expect(input).not.toHaveAttribute('aria-controls');
        expect(input).not.toHaveAttribute('aria-activedescendant');
    });
});
