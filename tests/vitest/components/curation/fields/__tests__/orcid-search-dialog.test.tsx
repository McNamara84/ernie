import { act, fireEvent, render as rawRender, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { settleRender } from '@tests/vitest/helpers/settled-render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { OrcidSearchDialog } from '@/components/curation/fields/orcid-search-dialog';
import { OrcidService } from '@/services/orcid';

vi.mock('@/services/orcid', () => ({ OrcidService: { searchOrcid: vi.fn() } }));
const search = vi.mocked(OrcidService.searchOrcid);
const render = settleRender(rawRender);
type Response = Awaited<ReturnType<typeof OrcidService.searchOrcid>>;
const alex = { orcid: '0000-0002-1825-0097', firstName: 'Alex', lastName: 'Taylor', creditName: null, institutions: ['GFZ', 'University'] };
const sam = { ...alex, orcid: '0000-0001-5109-3700', firstName: 'Sam', institutions: [] };
const found = { success: true, data: { results: [alex, sam], total: 84 } };
const empty = { success: true, data: { results: [], total: 0 } };

async function openDialog() {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    const view = await render(<OrcidSearchDialog onSelect={onSelect} triggerClassName="orcid-trigger" />);
    const trigger = screen.getByRole('button', { name: 'Search for ORCID' });
    await user.click(trigger);
    return {
        user,
        trigger,
        onSelect,
        view,
        input: screen.getByRole('searchbox', { name: 'Person name' }),
        submit: screen.getByRole('button', { name: 'Search' }),
    };
}

beforeEach(() => search.mockReset());

describe('manual ORCID search', () => {
    it('focuses the labelled input and enforces the trimmed 2–200 character range', async () => {
        const { input, submit, user, trigger } = await openDialog();
        expect(trigger).toHaveClass('orcid-trigger');
        expect(input).toHaveFocus();
        expect(screen.getByRole('dialog')).toHaveAccessibleDescription(/second comma/i);
        for (const value of ['', '   ', ' A ', 'x'.repeat(201)]) {
            fireEvent.change(input, { target: { value } });
            expect(submit).toBeDisabled();
            await user.keyboard('{Enter}');
        }
        expect(screen.getByRole('status')).toHaveTextContent('200 characters');
        expect(search).not.toHaveBeenCalled();
        for (const value of ['Li', 'x'.repeat(200)]) {
            fireEvent.change(input, { target: { value } });
            expect(submit).toBeEnabled();
        }
    });

    it('submits once with the person mode and shows the actual number of matching people', async () => {
        const pending = Promise.withResolvers<Response>();
        search.mockReturnValueOnce(pending.promise);
        const { user, input, onSelect } = await openDialog();
        await user.type(input, ' Ehrmann, Holger, GFZ ');
        expect(search).not.toHaveBeenCalled();
        await user.keyboard('{Enter}{Enter}');
        expect(search).toHaveBeenCalledExactlyOnceWith('Ehrmann, Holger, GFZ', 20, { mode: 'person', signal: expect.any(AbortSignal) });
        expect(screen.getByRole('status')).toHaveTextContent('Searching ORCID');
        await act(async () => pending.resolve(found));
        expect(screen.getByRole('status')).toHaveTextContent('Showing 2 of 84 results');
        const result = screen.getByRole('button', { name: /Taylor, Alex/ });
        expect(result).toHaveTextContent('GFZ • University');
        const link = screen.getByRole('link', { name: `View ${alex.orcid} on ORCID.org` });
        expect(link).toHaveAttribute('href', `https://orcid.org/${alex.orcid}`);
        expect(link).toHaveAttribute('target', '_blank');
        expect(result).not.toContainElement(link);
        expect(onSelect).not.toHaveBeenCalled();
    });

    it('moves focus between results and takes the explicitly selected person once', async () => {
        search.mockResolvedValue({ ...found, data: { ...found.data, total: 2 } });
        const { user, input, submit, onSelect, trigger } = await openDialog();
        await user.type(input, 'Taylor');
        await user.click(submit);
        expect(input).toHaveFocus();
        expect(screen.getByRole('status')).toHaveTextContent('2 results found');
        await user.keyboard('{ArrowDown}{ArrowUp}');
        expect(input).toHaveFocus();
        await user.keyboard('{ArrowDown}{ArrowDown}{ArrowUp}');
        expect(screen.getByRole('button', { name: /Taylor, Alex/ })).toHaveFocus();
        await user.keyboard('{ArrowDown}{ArrowDown}');
        expect(screen.getByRole('button', { name: /Taylor, Sam/ })).toHaveFocus();
        await user.keyboard('{Enter}');
        expect(onSelect).toHaveBeenCalledExactlyOnceWith(sam);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });

    it('uses Enter in the search field to search again without selecting a person or submitting the editor', async () => {
        const user = userEvent.setup();
        const onSelect = vi.fn();
        const onSubmit = vi.fn((event: React.FormEvent) => event.preventDefault());
        search.mockResolvedValue(found);
        await render(
            <form onSubmit={onSubmit}>
                <OrcidSearchDialog onSelect={onSelect} />
            </form>,
        );
        await user.click(screen.getByRole('button', { name: 'Search for ORCID' }));
        const input = screen.getByRole('searchbox');
        await user.type(input, 'Li{Enter}');
        expect(screen.getByRole('list')).toBeVisible();
        fireEvent.keyDown(input, { key: 'Enter', repeat: true });
        expect(search).toHaveBeenCalledTimes(1);
        await user.keyboard('{Enter}');
        expect(search).toHaveBeenCalledTimes(2);
        expect(onSelect).not.toHaveBeenCalled();
        await user.keyboard('{ArrowDown}{Enter}');
        expect(onSelect).toHaveBeenCalledExactlyOnceWith(alex);
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('uses the public credit name or an ID when personal names are missing, and selects by click', async () => {
        const record = { ...alex, lastName: '', firstName: '', creditName: 'Published Name' };
        search.mockResolvedValue({ success: true, data: { results: [record, { ...sam, lastName: '', firstName: '' }], total: 2 } });
        const { user, input, onSelect } = await openDialog();
        await user.type(input, 'Li{Enter}');
        expect(screen.getByRole('button', { name: /Name unavailable/ })).toHaveTextContent(sam.orcid);
        await user.click(screen.getByRole('button', { name: /Published Name/ }));
        expect(onSelect).toHaveBeenCalledExactlyOnceWith(record);
    });

    it('removes selectable results immediately when editing and ignores Enter during composition', async () => {
        search.mockResolvedValue(found);
        const { user, input, onSelect } = await openDialog();
        await user.type(input, 'Li{Enter}');
        await user.type(input, 'n');
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
        fireEvent.keyDown(input, { key: 'Enter', isComposing: true });
        expect(search).toHaveBeenCalledTimes(1);
        expect(onSelect).not.toHaveBeenCalled();
    });

    it.each(['success', 'failure'] as const)('ignores a stale %s and keeps the current request pending', async (outcome) => {
        const old = Promise.withResolvers<Response>();
        const current = Promise.withResolvers<Response>();
        search.mockReturnValueOnce(old.promise).mockReturnValueOnce(current.promise);
        const { user, input } = await openDialog();
        await user.type(input, 'Li{Enter}');
        const signal = search.mock.calls[0][2]?.signal;
        await user.type(input, 'n{Enter}');
        expect(signal?.aborted).toBe(true);
        await act(async () => {
            if (outcome === 'success') old.resolve(found);
            else old.reject(new Error('late'));
        });
        expect(screen.getByRole('status')).toHaveTextContent('Searching ORCID');
        await user.keyboard('{Enter}');
        expect(search).toHaveBeenCalledTimes(2);
        await act(async () => current.resolve(empty));
        expect(screen.getByRole('status')).toHaveTextContent('No ORCID records found');
    });

    it('keeps a reopened dialog empty and cancels a request on unmount', async () => {
        const old = Promise.withResolvers<Response>();
        const pending = Promise.withResolvers<Response>();
        search.mockReturnValueOnce(old.promise).mockReturnValueOnce(pending.promise);
        const { user, input, trigger, view } = await openDialog();
        await user.type(input, 'Li{Enter}{Escape}');
        expect(search.mock.calls[0][2]?.signal?.aborted).toBe(true);
        await user.click(trigger);
        await act(async () => old.resolve(found));
        const reopened = screen.getByRole('searchbox');
        expect(reopened).toHaveValue('');
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
        await user.type(reopened, 'Li{Enter}');
        view.unmount();
        expect(search.mock.calls[1][2]?.signal?.aborted).toBe(true);
        await act(async () => pending.resolve(found));
    });

    it('offers a retry after failure, preserving the text and distinguishing an empty search', async () => {
        search.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(empty);
        const { user, input } = await openDialog();
        await user.type(input, 'Li{Enter}');
        expect(screen.getByRole('status')).toHaveTextContent('Could not reach ORCID');
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        expect(input).toHaveValue('Li');
        expect(search.mock.calls.map(([term]) => term)).toEqual(['Li', 'Li']);
        expect(screen.getByRole('status')).toHaveTextContent('No ORCID records found');
        expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument();
    });

    it('shows a retryable error when a search returns neither results nor a diagnostic', async () => {
        search.mockResolvedValue({ success: false });
        const { input, user } = await openDialog();
        await user.type(input, 'Li{Enter}');
        expect(screen.getByRole('status')).toHaveTextContent('Could not search ORCID.');
        expect(screen.getByRole('button', { name: 'Try again' })).toBeEnabled();
    });

    it('shows a correctable input error and keeps the field editable', async () => {
        search.mockResolvedValue({ success: false, status: 422, error: 'Enter a name.' });
        const { user, input } = await openDialog();
        await user.type(input, 'Li,,GFZ{Enter}');
        expect(screen.getByRole('status')).toHaveTextContent('Enter a name.');
        expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument();
        await user.clear(input);
        expect(screen.getByRole('status')).toHaveTextContent('two characters');
    });
});
