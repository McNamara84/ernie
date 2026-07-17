/**
 * @vitest-environment jsdom
 */
import userEvent from '@testing-library/user-event';
import { act, fireEvent, render, screen, waitFor, within } from '@tests/vitest/utils/render';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import { CiteThisResourceSection } from '@/pages/LandingPages/components/CiteThisResourceSection';
import type { LandingPageCitationStyle, LandingPageResource } from '@/types/landing-page';

const { mockToastSuccess, mockToastError } = vi.hoisted(() => ({
    mockToastSuccess: vi.fn(),
    mockToastError: vi.fn(),
}));

vi.mock('sonner', () => ({
    toast: {
        success: mockToastSuccess,
        error: mockToastError,
    },
}));

const writeText = vi.fn();

const resource: LandingPageResource = {
    id: 42,
    identifier: '10.5880/example.2026',
    doi: '10.5880/example.2026',
    publication_year: 2026,
    version: '1.0',
    language: 'en',
    titles: [{ id: 1, title: 'A Test Dataset', title_type: 'MainTitle' }],
    creators: [
        {
            id: 1,
            position: 1,
            affiliations: [],
            creatorable: {
                type: 'Person',
                id: 1,
                given_name: 'Ada',
                family_name: 'Lovelace',
                name_identifier: null,
                name_identifier_scheme: null,
                name: null,
            },
        },
        {
            id: 2,
            position: 2,
            affiliations: [],
            creatorable: {
                type: 'Person',
                id: 2,
                given_name: 'Grace',
                family_name: 'Hopper',
                name_identifier: null,
                name_identifier_scheme: null,
                name: null,
            },
        },
    ],
};

const citationStyles: LandingPageCitationStyle[] = [
    {
        id: 'apa-7',
        label: 'APA 7',
        available: true,
        html: '<div class="csl-entry">Lovelace, A., &amp; Hopper, G. (2026). <em>A Test Dataset</em>.</div>',
        text: 'Lovelace, A., & Hopper, G. (2026). A Test Dataset.',
    },
    {
        id: 'harvard',
        label: 'Harvard (Cite Them Right)',
        available: true,
        html: '<div class="csl-entry">Lovelace, A. and Hopper, G. (2026) <em>A Test Dataset</em>.</div>',
        text: 'Lovelace, A. and Hopper, G. (2026) A Test Dataset.',
    },
    {
        id: 'copernicus',
        label: 'Copernicus / EGU',
        available: true,
        html: '<div class="csl-entry">Lovelace, A. and Hopper, G.: <em>A Test Dataset</em>, 2026.</div>',
        text: 'Lovelace, A. and Hopper, G.: A Test Dataset, 2026.',
    },
    {
        id: 'agu',
        label: 'AGU',
        available: true,
        html: '<div class="csl-entry">Lovelace, A., and G. Hopper (2026), <em>A Test Dataset</em>.</div>',
        text: 'Lovelace, A., and G. Hopper (2026), A Test Dataset.',
    },
    {
        id: 'gsa',
        label: 'GSA',
        available: true,
        html: '<div class="csl-entry">Lovelace, A., and Hopper, G., 2026, <em>A Test Dataset</em>.</div>',
        text: 'Lovelace, A., and Hopper, G., 2026, A Test Dataset.',
    },
];

beforeAll(() => {
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => {};
    Element.prototype.releasePointerCapture = () => {};
    Element.prototype.scrollIntoView = () => {};
});

beforeEach(() => {
    vi.clearAllMocks();
    writeText.mockReset();
    Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: { writeText },
    });
});

afterEach(() => {
    vi.useRealTimers();
});

async function openCitationStyleSelect() {
    const user = userEvent.setup({ pointerEventsCheck: 0 });

    await user.click(screen.getByRole('combobox', { name: 'Citation style' }));

    return user;
}

async function chooseCitationStyle(label: string) {
    const clipboard = navigator.clipboard;
    const user = await openCitationStyleSelect();
    await user.click(await screen.findByRole('option', { name: label }));

    Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: clipboard,
    });
}

describe('CiteThisResourceSection', () => {
    it('renders all six styles in the required order and selects APA 7 by default', async () => {
        render(<CiteThisResourceSection resource={resource} citationStyles={citationStyles} />);

        expect(screen.getByRole('region', { name: 'Cite this Resource' })).toBeInTheDocument();
        const trigger = screen.getByRole('combobox', { name: 'Citation style' });
        expect(trigger).toHaveAttribute('data-citation-style', 'apa-7');
        expect(trigger).toHaveTextContent('APA 7');

        const user = await openCitationStyleSelect();
        const options = await screen.findAllByRole('option');
        expect(options.map((option) => option.getAttribute('data-citation-style'))).toEqual(['apa-7', 'harvard', 'copernicus', 'agu', 'gsa', 'gfz']);
        expect(options.map((option) => option.textContent)).toEqual([
            'APA 7',
            'Harvard (Cite Them Right)',
            'Copernicus / EGU',
            'AGU',
            'GSA',
            'GFZ Data Services (legacy)',
        ]);

        expect(screen.getByRole('listbox')).toHaveAttribute('data-print', 'hide');
        await user.keyboard('{Escape}');
        const content = screen.getByTestId('citation-content');
        expect(content).toHaveAttribute('data-citation-style', 'apa-7');
        expect(within(content).getByText('A Test Dataset').tagName).toBe('EM');
        expect(screen.getByRole('link', { name: 'Citation Style Language (CSL)' })).toHaveAttribute('href', 'https://citationstyles.org/');
    });

    it('changes the only visible citation when a different style is selected', async () => {
        render(<CiteThisResourceSection resource={resource} citationStyles={citationStyles} />);

        await chooseCitationStyle('Harvard (Cite Them Right)');

        const content = screen.getByTestId('citation-content');
        expect(content).toHaveAttribute('data-citation-style', 'harvard');
        expect(content).toHaveTextContent('Lovelace, A. and Hopper, G.');
        expect(content).not.toHaveTextContent('Lovelace, A., & Hopper, G.');
    });

    it('applies fixed layout rules to the sanitizer layout classes', () => {
        const stylesWithLayoutClasses = citationStyles.map((style) =>
            style.id === 'apa-7'
                ? {
                      ...style,
                      html: '<div class="csl-entry csl-hanging-indent csl-double-spaced"><div class="csl-left-margin">[1]</div><div class="csl-right-inline">Citation</div></div>',
                  }
                : style,
        );

        render(<CiteThisResourceSection resource={resource} citationStyles={stylesWithLayoutClasses} />);

        const content = screen.getByTestId('citation-content');
        const renderer = content.firstElementChild;

        expect(renderer).toHaveClass(
            '[&_.csl-hanging-indent]:pl-[2em]',
            '[&_.csl-hanging-indent]:[text-indent:-2em]',
            '[&_.csl-double-spaced]:[line-height:2]',
            '[&_.csl-left-margin]:block',
            '[&_.csl-left-margin]:float-left',
            '[&_.csl-right-inline]:ml-[35px]',
        );
        expect(content.querySelector('.csl-entry')).toHaveClass('csl-hanging-indent', 'csl-double-spaced');
        expect(content.querySelector('.csl-left-margin')).toHaveTextContent('[1]');
        expect(content.querySelector('.csl-right-inline')).toHaveTextContent('Citation');
    });

    it('disables unusable official outputs and falls back to the first available style', async () => {
        const stylesWithBrokenApa = citationStyles.map((style) =>
            style.id === 'apa-7' ? { ...style, available: false, html: null, text: null } : style,
        );

        render(<CiteThisResourceSection resource={resource} citationStyles={stylesWithBrokenApa} />);

        const trigger = screen.getByRole('combobox', { name: 'Citation style' });
        expect(trigger).toHaveAttribute('data-citation-style', 'harvard');
        expect(trigger).toHaveTextContent('Harvard (Cite Them Right)');

        await openCitationStyleSelect();
        expect(await screen.findByRole('option', { name: 'APA 7' })).toHaveAttribute('aria-disabled', 'true');
        expect(screen.getByTestId('citation-content')).toHaveAttribute('data-citation-style', 'harvard');
    });

    it('treats an allegedly available style without both output forms as unavailable', async () => {
        const incompleteStyles = citationStyles.map((style) => (style.id === 'apa-7' ? { ...style, available: true, text: null } : style));

        render(<CiteThisResourceSection resource={resource} citationStyles={incompleteStyles} />);

        const trigger = screen.getByRole('combobox', { name: 'Citation style' });
        expect(trigger).toHaveAttribute('data-citation-style', 'harvard');
        expect(trigger).toHaveTextContent('Harvard (Cite Them Right)');

        await openCitationStyleSelect();
        expect(await screen.findByRole('option', { name: 'APA 7' })).toHaveAttribute('aria-disabled', 'true');
    });

    it('falls back safely to GFZ when the official payload is absent', async () => {
        render(<CiteThisResourceSection resource={resource} citationStyles={undefined} citationAuthorLimit={1} />);

        const trigger = screen.getByRole('combobox', { name: 'Citation style' });
        expect(trigger).toHaveAttribute('data-citation-style', 'gfz');
        expect(trigger).toHaveTextContent('GFZ Data Services (legacy)');

        await openCitationStyleSelect();
        expect(
            screen
                .getAllByRole('option')
                .slice(0, 5)
                .every((option) => option.getAttribute('aria-disabled') === 'true'),
        ).toBe(true);
        expect(screen.getByTestId('citation-content')).toHaveTextContent(
            'Lovelace, A.; et al. (2026): A Test Dataset. GFZ Data Services. https://doi.org/10.5880/example.2026',
        );
    });

    it('applies the author limit only to GFZ and leaves official output untouched', async () => {
        render(<CiteThisResourceSection resource={resource} citationStyles={citationStyles} citationAuthorLimit={1} />);

        expect(screen.getByTestId('citation-content')).toHaveTextContent('Lovelace, A., & Hopper, G.');

        await chooseCitationStyle('GFZ Data Services (legacy)');

        expect(screen.getByTestId('citation-content')).toHaveTextContent('Lovelace, A.; et al.');
        expect(screen.getByTestId('citation-content')).not.toHaveTextContent('Hopper, G.');
    });

    it('copies the selected plain text instead of its HTML representation', async () => {
        writeText.mockResolvedValueOnce(undefined);
        render(<CiteThisResourceSection resource={resource} citationStyles={citationStyles} />);

        await chooseCitationStyle('Harvard (Cite Them Right)');
        fireEvent.click(screen.getByRole('button', { name: 'Copy citation to clipboard' }));

        await waitFor(() => {
            expect(writeText).toHaveBeenCalledWith(citationStyles[1].text);
        });
        expect(writeText).not.toHaveBeenCalledWith(citationStyles[1].html);
        expect(mockToastSuccess).toHaveBeenCalledWith('Citation copied to clipboard');
        expect(screen.getByRole('status')).toHaveTextContent('Citation copied to clipboard');
    });

    it('omits a missing DOI from GFZ and keeps the explanatory note out of the clipboard', async () => {
        writeText.mockResolvedValueOnce(undefined);
        render(<CiteThisResourceSection resource={{ ...resource, doi: null }} citationStyles={[]} />);

        const content = screen.getByTestId('citation-content');
        expect(content).toHaveTextContent('Lovelace, A.; Hopper, G. (2026): A Test Dataset. GFZ Data Services.');
        expect(content).not.toHaveTextContent('DOI');
        expect(content).not.toHaveTextContent('doi.org');
        expect(screen.getByTestId('citation-doi-note')).toHaveTextContent('DOI not yet available.');

        fireEvent.click(screen.getByRole('button', { name: 'Copy citation to clipboard' }));

        await waitFor(() => expect(writeText).toHaveBeenCalledOnce());
        expect(writeText.mock.calls[0][0]).not.toContain('DOI');
        expect(writeText.mock.calls[0][0]).not.toContain('not yet available');
    });

    it('clears success feedback after two seconds and cancels the timer on unmount', async () => {
        vi.useFakeTimers();
        writeText.mockResolvedValue(undefined);
        const { unmount } = render(<CiteThisResourceSection resource={resource} citationStyles={citationStyles} />);

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Copy citation to clipboard' }));
            await Promise.resolve();
        });

        expect(screen.getByRole('status')).toHaveTextContent('Citation copied to clipboard');
        expect(vi.getTimerCount()).toBe(1);

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Copy citation to clipboard' }));
            await Promise.resolve();
        });
        expect(writeText).toHaveBeenCalledTimes(2);
        expect(vi.getTimerCount()).toBe(1);

        act(() => vi.advanceTimersByTime(2000));
        expect(screen.getByRole('status')).toHaveTextContent('');

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Copy citation to clipboard' }));
            await Promise.resolve();
        });
        expect(vi.getTimerCount()).toBe(1);

        unmount();
        expect(vi.getTimerCount()).toBe(0);
    });

    it('reports clipboard failures and clears stale copied state', async () => {
        writeText.mockResolvedValueOnce(undefined).mockRejectedValueOnce(new Error('Permission denied'));
        render(<CiteThisResourceSection resource={resource} citationStyles={citationStyles} />);

        fireEvent.click(screen.getByRole('button', { name: 'Copy citation to clipboard' }));
        await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Citation copied to clipboard'));

        fireEvent.click(screen.getByRole('button', { name: 'Copy citation to clipboard' }));

        await waitFor(() => expect(mockToastError).toHaveBeenCalledWith('Failed to copy citation'));
        expect(screen.getByRole('status')).toHaveTextContent('');
        expect(screen.getByRole('button', { name: 'Copy citation to clipboard' })).toHaveAttribute('title', 'Copy citation');
    });

    it('marks interactive controls for print and provides 44px touch targets', () => {
        render(<CiteThisResourceSection resource={resource} citationStyles={citationStyles} />);

        const citationStyleSelect = screen.getByRole('combobox', { name: 'Citation style' });
        expect(citationStyleSelect.closest('[data-print="hide"]')).not.toBeNull();
        expect(citationStyleSelect).toHaveClass('min-h-11');
        const copyButton = screen.getByRole('button', { name: 'Copy citation to clipboard' });
        expect(copyButton).toHaveAttribute('data-print', 'hide');
        expect(copyButton).toHaveClass('min-h-11', 'min-w-11');
    });
});
