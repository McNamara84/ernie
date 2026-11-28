import '@testing-library/jest-dom/vitest';

import type { ActiveVisit, GlobalEvent, Page, VisitOptions } from '@inertiajs/core';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ContactPersons from '@/pages/contact-persons';
import type { ContactPersonsPageProps } from '@/types/contact-persons';

const { get, on, unsubscribe, success, error } = vi.hoisted(() => {
    const unsubscribe = vi.fn();
    return {
        get: vi.fn<(url: string, data: Record<string, unknown>, options: VisitOptions) => void>(),
        on: vi.fn<(event: 'navigate', callback: (event: GlobalEvent<'navigate'>) => void) => () => void>().mockReturnValue(unsubscribe),
        unsubscribe,
        success: vi.fn(),
        error: vi.fn(),
    };
});
vi.mock('@inertiajs/react', () => ({ Head: () => null, Link: (props: ComponentProps<'a'>) => <a {...props} />, router: { get, on } }));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('sonner', () => ({ toast: { success, error } }));

const props: ContactPersonsPageProps = {
    search: '',
    pagination: { current_page: 1, last_page: 2, per_page: 25, total: 26 },
    groups: [
        {
            key: 'orcid:0000-0002-1825-0097',
            orcid: '0000-0002-1825-0097',
            variants: [
                { key: 'a'.repeat(64), name: 'Contact, Ada', email: 'ada@example.test', resource_count: 2, igsn_count: 1 },
                { key: 'b'.repeat(64), name: 'Contact, A.', email: null, resource_count: 1, igsn_count: 0 },
            ],
        },
    ],
};

function responsePage(search: string): Page {
    return {
        component: 'contact-persons/index',
        props: { ...props, search, errors: {} },
        url: `/contact-persons?search=${encodeURIComponent(search)}`,
        version: null,
        flash: {},
        rescuedProps: [],
        rememberedState: {},
    };
}

describe('Contact persons', () => {
    beforeEach(() => vi.clearAllMocks());
    afterEach(() => vi.useRealTimers());

    it('groups variants with one ORCID and links exactly to both existing lists', () => {
        render(<ContactPersons {...props} />);
        const orcid = screen.getByRole('link', { name: '0000-0002-1825-0097' });
        expect(orcid).toHaveAttribute('href', 'https://orcid.org/0000-0002-1825-0097');
        expect(orcid.closest('th')).toHaveAttribute('rowspan', '2');
        expect(screen.getByRole('link', { name: /View 2 resources/ })).toHaveAttribute('href', `/resources?contact=${'a'.repeat(64)}`);
        expect(screen.getByRole('link', { name: /View 1 IGSNs/ })).toHaveAttribute('href', `/igsns?contact=${'a'.repeat(64)}`);
        expect(screen.getAllByRole('button', { name: /Copy email/ })).toHaveLength(1);
        expect(screen.getByText('No email recorded')).toBeVisible();
    });

    it('copies the address once and reports success', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
        render(<ContactPersons {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Copy email ada@example.test' }));
        await waitFor(() => expect(success).toHaveBeenCalledWith('Email address copied'));
        expect(writeText).toHaveBeenCalledExactlyOnceWith('ada@example.test');
        expect(error).not.toHaveBeenCalled();
    });

    it.each(['rejected', 'unavailable'])('reports clipboard failures when %s without claiming success', async (mode) => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: mode === 'unavailable' ? undefined : { writeText: vi.fn().mockRejectedValue(new Error('Denied')) },
        });
        render(<ContactPersons {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Copy email ada@example.test' }));
        await waitFor(() => expect(error).toHaveBeenCalled());
        expect(success).not.toHaveBeenCalled();
        expect(screen.getByText('ada@example.test')).toBeVisible();
    });

    it('debounces search and resets the group page', () => {
        vi.useFakeTimers();
        render(<ContactPersons {...props} />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: ' Ada ' } });
        act(() => vi.advanceTimersByTime(399));
        expect(get).not.toHaveBeenCalled();
        act(() => vi.advanceTimersByTime(1));
        expect(get).toHaveBeenCalledWith('/contact-persons', { search: 'Ada', page: 1, per_page: 25 }, expect.any(Object));
    });

    it.each(['Grace', ''])('keeps a newer search "%s" and its debounce when an earlier response arrives', (newerSearch) => {
        vi.useFakeTimers();
        const { rerender } = render(<ContactPersons {...props} />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Ada' } });
        act(() => vi.advanceTimersByTime(400));
        const earlierVisit = get.mock.calls[0][2];
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: newerSearch } });
        act(() => vi.advanceTimersByTime(200));
        rerender(<ContactPersons {...props} search="Ada" />);
        act(() => {
            on.mock.calls[0][1](new CustomEvent('inertia:navigate', { detail: { type: 'visit', page: responsePage('Ada') } }));
            earlierVisit.onSuccess?.(responsePage('Ada'));
        });
        expect(screen.getByRole('searchbox')).toHaveValue(newerSearch);
        act(() => vi.advanceTimersByTime(199));
        expect(get).toHaveBeenCalledTimes(1);
        act(() => vi.advanceTimersByTime(1));
        expect(get).toHaveBeenCalledTimes(2);
        expect(get).toHaveBeenLastCalledWith(
            '/contact-persons',
            { ...(newerSearch ? { search: newerSearch } : {}), page: 1, per_page: 25 },
            expect.any(Object),
        );
    });

    it('synchronizes an unchanged input with the successful response without another visit', () => {
        vi.useFakeTimers();
        const { rerender } = render(<ContactPersons {...props} />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: ' Ada ' } });
        act(() => vi.advanceTimersByTime(400));
        rerender(<ContactPersons {...props} search="Ada" />);
        act(() => {
            get.mock.calls[0][2].onSuccess?.(responsePage('Ada'));
        });
        expect(screen.getByRole('searchbox')).toHaveValue('Ada');
        act(() => vi.advanceTimersByTime(400));
        expect(get).toHaveBeenCalledTimes(1);
    });

    it('synchronizes browser history and cancels a newer pending search', () => {
        vi.useFakeTimers();
        const { rerender } = render(<ContactPersons {...props} />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Pending' } });
        rerender(<ContactPersons {...props} search="Previous" />);
        act(() => {
            on.mock.calls[0][1](new CustomEvent('inertia:navigate', { detail: { type: 'history', page: responsePage('Previous') } }));
        });
        expect(screen.getByRole('searchbox')).toHaveValue('Previous');
        act(() => vi.advanceTimersByTime(400));
        expect(get).not.toHaveBeenCalled();
    });

    it('cancels pending searches and removes its navigation listener on unmount', () => {
        vi.useFakeTimers();
        const { unmount } = render(<ContactPersons {...props} />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Pending' } });
        unmount();
        act(() => vi.advanceTimersByTime(400));
        expect(get).not.toHaveBeenCalled();
        expect(unsubscribe).toHaveBeenCalledOnce();
    });

    it('cancels a pending search immediately when following a resource link', () => {
        vi.useFakeTimers();
        render(<ContactPersons {...props} />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Pending' } });
        fireEvent.click(screen.getByRole('link', { name: /View 2 resources/ }));
        act(() => vi.advanceTimersByTime(400));
        expect(get).not.toHaveBeenCalled();
    });

    it('preserves search on pagination and page-size changes', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<ContactPersons {...props} search="Ada" />);
        expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(get).toHaveBeenLastCalledWith('/contact-persons', { search: 'Ada', page: 2, per_page: 25 }, expect.any(Object));
        const pageSize = screen.getByRole('combobox', { name: 'Groups per page' });
        expect(pageSize).toHaveTextContent('25');
        await user.click(pageSize);
        expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual(['25', '50', '100']);
        await user.click(screen.getByRole('option', { name: '50' }));
        expect(get).toHaveBeenLastCalledWith('/contact-persons', { search: 'Ada', page: 1, per_page: 50 }, expect.any(Object));
        rerender(<ContactPersons {...props} search="Ada" pagination={{ ...props.pagination, per_page: 50 }} />);
        expect(pageSize).toHaveTextContent('50');
    });

    it('does not replay a pending search after an explicit page-size change', () => {
        vi.useFakeTimers();
        render(<ContactPersons {...props} />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Ada' } });
        fireEvent.keyDown(screen.getByRole('combobox', { name: 'Groups per page' }), { key: 'Enter' });
        const option = screen.getByRole('option', { name: '50' });
        act(() => option.focus());
        fireEvent.keyDown(option, { key: 'Enter' });
        act(() => vi.advanceTimersByTime(400));
        expect(get).toHaveBeenCalledExactlyOnceWith('/contact-persons', { search: 'Ada', page: 1, per_page: 50 }, expect.any(Object));
    });

    it.each([false, true])('restores page-size focus after loading unless the user moved it (moved: %s)', async (moveFocus) => {
        const user = userEvent.setup();
        const visit = {} as ActiveVisit;
        get.mockImplementationOnce((_url, _data, options) => options.onStart?.(visit));
        render(<ContactPersons {...props} />);
        const pageSize = screen.getByRole('combobox', { name: 'Groups per page' });
        await user.click(pageSize);
        await user.click(screen.getByRole('option', { name: '50' }));
        await waitFor(() => expect(screen.queryByRole('listbox')).not.toBeInTheDocument());
        expect(pageSize).toBeDisabled();
        const search = screen.getByRole('searchbox');
        if (moveFocus) await user.click(search);
        else expect(document.body).toHaveFocus();

        act(() => get.mock.calls[0][2].onFinish?.(visit));

        expect(pageSize).toBeEnabled();
        expect(moveFocus ? search : pageSize).toHaveFocus();
    });

    it('does not restore page-size focus or clear loading when a newer search supersedes its visit', async () => {
        const user = userEvent.setup();
        const visit = {} as ActiveVisit;
        get.mockImplementationOnce((_url, _data, options) => options.onStart?.(visit));
        render(<ContactPersons {...props} />);
        const pageSize = screen.getByRole('combobox', { name: 'Groups per page' });
        await user.click(pageSize);
        await user.click(screen.getByRole('option', { name: '50' }));
        const search = screen.getByRole('searchbox');
        await user.type(search, 'Ada');
        await waitFor(() => expect(get).toHaveBeenCalledTimes(2));

        act(() => get.mock.calls[0][2].onFinish?.(visit));
        expect(pageSize).toBeDisabled();
        expect(search).toHaveFocus();
        act(() => get.mock.calls[1][2].onFinish?.(visit));
        expect(pageSize).toBeEnabled();
        expect(search).toHaveFocus();
    });

    it.each(['Next', 'Previous'])('starts an edited search on page one when clicking %s before the debounce', (button) => {
        vi.useFakeTimers();
        render(<ContactPersons {...props} search="Ada" pagination={{ ...props.pagination, current_page: 3, last_page: 5 }} />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Grace' } });
        fireEvent.click(screen.getByRole('button', { name: button }));
        act(() => vi.advanceTimersByTime(400));
        expect(get).toHaveBeenCalledExactlyOnceWith('/contact-persons', { search: 'Grace', page: 1, per_page: 25 }, expect.any(Object));
    });

    it('starts on page one when pagination clears the applied search', () => {
        vi.useFakeTimers();
        render(<ContactPersons {...props} search="Ada" />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: '' } });
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        act(() => vi.advanceTimersByTime(400));
        expect(get).toHaveBeenCalledExactlyOnceWith('/contact-persons', { page: 1, per_page: 25 }, expect.any(Object));
    });

    it('keeps the requested page when only surrounding search whitespace changes', () => {
        vi.useFakeTimers();
        render(<ContactPersons {...props} search="Ada" />);
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: ' Ada ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        act(() => vi.advanceTimersByTime(400));
        expect(get).toHaveBeenCalledExactlyOnceWith('/contact-persons', { search: 'Ada', page: 2, per_page: 25 }, expect.any(Object));
    });

    it('shows missing identities and unavailable targets without fake links', () => {
        render(
            <ContactPersons
                {...props}
                groups={[{ key: 'person:7', orcid: null, variants: [{ ...props.groups[0].variants[0], resource_count: 0, igsn_count: 0 }] }]}
            />,
        );
        expect(screen.getByText('No valid ORCID')).toBeVisible();
        expect(screen.getByText('No listed resources')).toBeVisible();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it.each(['', 'missing'])('renders an empty state for search %s', (search) => {
        render(<ContactPersons {...props} search={search} groups={[]} pagination={{ current_page: 1, last_page: 1, per_page: 25, total: 0 }} />);
        expect(screen.getByRole('status')).toHaveTextContent(search ? 'No contact persons match your search.' : 'No contact persons recorded yet.');
        expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
    });
});
