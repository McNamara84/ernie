/**
 * @vitest-environment jsdom
 */
import '@testing-library/jest-dom/vitest';

import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { PortalSearchInput } from '@/components/portal/PortalSearchInput';
import type { KeywordSuggestion, PortalBasePath } from '@/types/portal';

const { axiosPostMock, suggestionsState } = vi.hoisted(() => ({
    axiosPostMock: vi.fn(),
    suggestionsState: {
        data: undefined as KeywordSuggestion[] | undefined,
        isFetching: false,
        isError: false,
    },
}));

vi.mock('axios', () => ({ default: { post: axiosPostMock } }));
vi.mock('@/hooks/use-portal-keyword-suggestions', () => ({ usePortalKeywordSuggestions: () => suggestionsState }));

function Harness({
    basePath = '/doi-search',
    initialValue = '',
    selectedKeywords = [],
    onSubmit = vi.fn(),
    onKeywordSelect = vi.fn(),
    onKeywordsChange = vi.fn(),
}: {
    basePath?: PortalBasePath;
    initialValue?: string;
    selectedKeywords?: string[];
    onSubmit?: (query: string) => void;
    onKeywordSelect?: (keyword: string) => void;
    onKeywordsChange?: (keywords: string[]) => void;
}) {
    const [value, setValue] = useState(initialValue);
    return (
        <PortalSearchInput
            basePath={basePath}
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
        expect(axiosPostMock).not.toHaveBeenCalled();
    });

    it('records IGSN searches on the IGSN endpoint', async () => {
        const user = userEvent.setup();
        render(<Harness basePath="/igsn-search" initialValue="basalt sample" />);

        await user.click(screen.getByRole('button', { name: 'Search' }));

        expect(axiosPostMock).not.toHaveBeenCalled();
    });

    it('explains the wildcard syntax only in the IGSN portal', () => {
        const { unmount } = render(<Harness basePath="/igsn-search" />);
        expect(screen.getByText(/Use \* to replace any number of characters/)).toBeInTheDocument();

        unmount();
        render(<Harness basePath="/doi-search" />);
        expect(screen.queryByText(/Use \* to replace any number of characters/)).not.toBeInTheDocument();
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
        await user.keyboard('{ArrowDown}{ArrowUp}');
        expect(screen.getByRole('option', { selected: true })).toHaveTextContent('Paleoseismology');
        await user.keyboard('{ArrowUp}{Enter}');

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

    it.each(['/doi-search', '/igsn-search'] as const)('silently accepts free text when %s returns no keyword matches', async (basePath) => {
        const user = userEvent.setup();
        const props = { basePath, onSubmit: vi.fn() };
        suggestionsState.data = undefined;
        const { rerender } = render(<Harness {...props} />);
        const input = screen.getByRole('combobox', { name: 'Search' });
        await user.type(input, '  unknown phrase  ');
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();

        suggestionsState.isFetching = true;
        rerender(<Harness {...props} />);
        expect(screen.getByText('Loading keywords...')).toBeVisible();

        suggestionsState.data = [];
        suggestionsState.isFetching = false;
        rerender(<Harness {...props} />);

        expect(screen.queryByText(/no matching keywords/i)).not.toBeInTheDocument();
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(input).toHaveAttribute('aria-expanded', 'false');
        expect(input).not.toHaveAttribute('aria-controls');
        expect(input).not.toHaveAttribute('aria-activedescendant');
        await user.keyboard('{Enter}');
        expect(props.onSubmit).toHaveBeenCalledWith('unknown phrase');
    });

    it('closes the list when all matches are already selected as filters', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        const { rerender } = render(<Harness initialValue="seis" selectedKeywords={['Seismology']} onSubmit={onSubmit} />);
        const input = screen.getByRole('combobox', { name: 'Search' });
        await user.click(input);
        await user.keyboard('{ArrowDown}');
        const remainingOption = screen.getByRole('option');
        expect(remainingOption).toHaveTextContent('Paleoseismology');
        expect(input).toHaveAttribute('aria-activedescendant', remainingOption.id);

        rerender(<Harness initialValue="seis" selectedKeywords={['Seismology', 'Paleoseismology']} onSubmit={onSubmit} />);

        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(input).toHaveAttribute('aria-expanded', 'false');
        expect(input).not.toHaveAttribute('aria-activedescendant');
        await user.click(screen.getByRole('button', { name: 'Search' }));
        expect(onSubmit).toHaveBeenCalledWith('seis');
    });

    it('submits text if a refresh removes the highlighted keyword but leaves another match', async () => {
        const user = userEvent.setup();
        const props = { initialValue: 'seis', onSubmit: vi.fn(), onKeywordSelect: vi.fn() };
        const { rerender } = render(<Harness {...props} />);
        const input = screen.getByRole('combobox', { name: 'Search' });
        await user.click(input);
        await user.keyboard('{ArrowDown}');
        expect(screen.getByRole('option', { selected: true })).toHaveTextContent('Seismology');

        suggestionsState.data = [{ value: 'Paleoseismology', scheme: null, count: 3 }];
        rerender(<Harness {...props} />);

        expect(screen.getByRole('option', { selected: false })).toHaveTextContent('Paleoseismology');
        expect(input).not.toHaveAttribute('aria-activedescendant');
        await user.keyboard('{Enter}');
        expect(props.onSubmit).toHaveBeenCalledWith('seis');
        expect(props.onKeywordSelect).not.toHaveBeenCalled();
    });

    it('displays a failed lookup and still lets Enter submit a text search', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        suggestionsState.data = [];
        suggestionsState.isError = true;
        render(<Harness initialValue="climate" onSubmit={onSubmit} />);
        const input = screen.getByRole('combobox', { name: 'Search' });
        await user.click(input);

        expect(screen.getByText('Keyword suggestions are unavailable.')).toBeVisible();
        expect(input).toHaveAttribute('aria-expanded', 'true');
        expect(input).not.toHaveAttribute('aria-activedescendant');
        await user.keyboard('{Enter}');
        expect(onSubmit).toHaveBeenCalledWith('climate');
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

    it('opens suggestions at two characters and closes them when the search is cleared', async () => {
        const user = userEvent.setup();
        render(<Harness initialValue="s" />);

        const input = screen.getByRole('combobox', { name: 'Search' });

        await user.click(input);
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
        expect(input).not.toHaveAttribute('aria-controls');
        expect(input).not.toHaveAttribute('aria-activedescendant');

        await user.type(input, 'e');
        expect(screen.getByRole('listbox')).toBeVisible();
        await user.click(screen.getByRole('button', { name: 'Clear search' }));
        expect(input).toHaveValue('');
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
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
