import '@testing-library/jest-dom/vitest';

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { newsManagementProps } from '@tests/vitest/fixtures/news';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ManageNews from '@/pages/manage-news';
import type { NewsManagementProps } from '@/types/news';

const mocks = vi.hoisted(() => ({ request: vi.fn(), post: vi.fn(), reload: vi.fn(), toast: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { reload: mocks.reload } }));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: ReactNode }) => <main>{children}</main> }));
vi.mock('sonner', () => ({ toast: { success: mocks.toast } }));
vi.mock('axios', () => ({
    default: {
        request: mocks.request,
        post: mocks.post,
        isAxiosError: (error: unknown) => Boolean(error && typeof error === 'object' && 'isAxiosError' in error),
    },
}));

let serverProps: NewsManagementProps;
const row = (title: string) => within(screen.getByRole('row', { name: new RegExp(title) }));

async function chooseOption(user: ReturnType<typeof userEvent.setup>, label: string, option: string) {
    await user.click(screen.getByRole('combobox', { name: label }));
    await user.click(screen.getByRole('option', { name: option }));
}

beforeEach(() => {
    vi.clearAllMocks();
    serverProps = structuredClone(newsManagementProps);
    mocks.request.mockResolvedValue({ data: { id: 2 } });
    mocks.post.mockResolvedValue({ data: { html: '<p><strong>Preview content</strong></p>' } });
    mocks.reload.mockImplementation((options: { onSuccess?: (page: { props: NewsManagementProps }) => void; onFinish?: () => void }) => {
        options.onSuccess?.({ props: serverProps });
        options.onFinish?.();
    });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
});

describe('News management', () => {
    it('shows publication status and protects the active regular news', () => {
        render(<ManageNews {...serverProps} />);
        expect(screen.getByRole('heading', { level: 1, name: 'Manage News' })).toBeVisible();
        expect(row('Regular announcement').getByRole('button', { name: 'Archive' })).toBeDisabled();
        expect(row('Regular announcement').getByRole('button', { name: 'Set as active' })).toBeDisabled();
        expect(row('Regular announcement').getByRole('button', { name: 'Archive' })).toHaveAttribute(
            'title',
            expect.stringContaining('Select another regular news'),
        );
        expect(screen.queryByText('Archived announcement')).not.toBeInTheDocument();
    });

    it('distinguishes the temporary display from the underlying regular selection', () => {
        serverProps.currently_displayed = { id: 4, title: 'Upcoming downtime', html: '<p>Downtime.</p>' };
        render(<ManageNews {...serverProps} />);
        expect(screen.getByText(/temporary announcement currently replaces it until/)).toHaveTextContent('15 Jul 2030, 13:00');
        expect(screen.getByRole('article', { name: 'Upcoming downtime' })).toBeVisible();
    });

    it('distinguishes repeated Berlin times by their UTC offsets in periods, update dates and publication status', () => {
        serverProps.news[2] = {
            ...serverProps.news[2],
            starts_at: '2030-10-27T02:30:00+02:00',
            ends_at: '2030-10-27T02:30:00+01:00',
            updated_at: '2030-10-27T01:30:00+00:00',
            is_scheduled: true,
            status: 'Currently displayed',
        };
        serverProps.currently_displayed = { id: 3, title: 'Maintenance', html: '<p>Maintenance text.</p>' };
        render(<ManageNews {...serverProps} />);

        const cells = row('Maintenance').getAllByRole('cell');
        expect(cells[2]).toHaveTextContent('27 Oct 2030, 02:30 GMT+02:00');
        expect(cells[2]).toHaveTextContent('to 27 Oct 2030, 02:30 GMT+01:00');
        expect(cells[3]).toHaveTextContent('27 Oct 2030, 02:30 GMT+01:00');
        expect(screen.getByText(/temporary announcement currently replaces it until/)).toHaveTextContent('27 Oct 2030, 02:30 GMT+01:00');
    });

    it('creates a draft without publishing it and reloads the returned record', async () => {
        const user = userEvent.setup();
        render(<ManageNews {...serverProps} />);
        await user.type(screen.getByLabelText('Title'), 'My news');
        await user.type(screen.getByLabelText('News text (Markdown)'), '**Draft**');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() =>
            expect(mocks.request).toHaveBeenCalledWith(
                expect.objectContaining({
                    url: '/manage-news',
                    method: 'post',
                    data: expect.objectContaining({ title: 'My news', body_markdown: '**Draft**', kind: 'regular' }),
                }),
            ),
        );
        expect(mocks.request).toHaveBeenCalledTimes(1);
        expect(mocks.reload).toHaveBeenCalled();
        expect(mocks.toast).toHaveBeenCalledWith('News saved.');
    });

    it('edits with the record revision and protects the type of published news', async () => {
        const user = userEvent.setup();
        render(<ManageNews {...serverProps} />);
        await user.click(row('Regular announcement').getByRole('button', { name: 'Edit' }));
        expect(screen.getByLabelText('Type')).toBeDisabled();
        expect(screen.getByText(/Saved changes affect its public display/)).toBeVisible();
        await user.clear(screen.getByLabelText('Title'));
        await user.type(screen.getByLabelText('Title'), 'Changed title');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() =>
            expect(mocks.request).toHaveBeenCalledWith(
                expect.objectContaining({
                    url: '/manage-news/1',
                    method: 'patch',
                    data: expect.objectContaining({ title: 'Changed title', revision: 2 }),
                }),
            ),
        );
    });

    it('preserves Berlin wall times and explicit offsets when editing a temporary announcement', async () => {
        const user = userEvent.setup();
        render(<ManageNews {...serverProps} />);
        await user.click(row('Maintenance').getByRole('button', { name: 'Edit' }));
        expect(screen.getByLabelText('Start (Europe/Berlin)')).toHaveValue('2030-07-15T12:00');
        expect(screen.getByRole('combobox', { name: 'Start UTC offset' })).toHaveTextContent('+02:00');
        fireEvent.change(screen.getByLabelText('Start (Europe/Berlin)'), { target: { value: '2030-07-15T11:00' } });
        expect(screen.getByRole('combobox', { name: 'Start UTC offset' })).toHaveTextContent('Automatic (unambiguous times)');
        await chooseOption(user, 'Start UTC offset', '+02:00 · Summer time (first occurrence)');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() =>
            expect(mocks.request).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: expect.objectContaining({
                        starts_at: '2030-07-15T11:00',
                        starts_at_offset: '+02:00',
                        ends_at: '2030-07-15T13:00',
                        ends_at_offset: '+02:00',
                    }),
                }),
            ),
        );
    });

    it('creates temporary drafts through the shared type and UTC offset controls', async () => {
        const user = userEvent.setup();
        render(<ManageNews {...serverProps} />);
        await user.type(screen.getByLabelText('Title'), 'Clock change maintenance');
        await user.type(screen.getByLabelText('News text (Markdown)'), 'One hour of maintenance.');
        await chooseOption(user, 'Type', 'Temporary announcement');
        fireEvent.change(screen.getByLabelText('Start (Europe/Berlin)'), { target: { value: '2030-10-27T02:30' } });
        fireEvent.change(screen.getByLabelText('End (Europe/Berlin)'), { target: { value: '2030-10-27T02:30' } });
        await chooseOption(user, 'Start UTC offset', '+02:00 · Summer time (first occurrence)');
        await chooseOption(user, 'End UTC offset', '+01:00 · Standard time (second occurrence)');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() =>
            expect(mocks.request).toHaveBeenCalledWith({
                url: '/manage-news',
                method: 'post',
                data: expect.objectContaining({
                    kind: 'temporary',
                    starts_at: '2030-10-27T02:30',
                    ends_at: '2030-10-27T02:30',
                    starts_at_offset: '+02:00',
                    ends_at_offset: '+01:00',
                }),
            }),
        );
    });

    it('sends empty offsets when automatic resolution is selected instead of the UI option value', async () => {
        const user = userEvent.setup();
        mocks.request.mockResolvedValue({ data: { id: 3 } });
        render(<ManageNews {...serverProps} />);
        await user.click(row('Maintenance').getByRole('button', { name: 'Edit' }));
        await chooseOption(user, 'Start UTC offset', 'Automatic (unambiguous times)');
        await chooseOption(user, 'End UTC offset', 'Automatic (unambiguous times)');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() =>
            expect(mocks.request).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: expect.objectContaining({ starts_at_offset: '', ends_at_offset: '' }),
                }),
            ),
        );
    });

    it('clears the old period and UTC offsets when changing a temporary draft to regular news', async () => {
        const user = userEvent.setup();
        render(<ManageNews {...serverProps} />);
        await user.click(row('Maintenance').getByRole('button', { name: 'Edit' }));
        await chooseOption(user, 'Type', 'Regular news');
        expect(screen.queryByLabelText('Start (Europe/Berlin)')).not.toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: 'Start UTC offset' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() =>
            expect(mocks.request).toHaveBeenCalledWith(
                expect.objectContaining({
                    data: expect.objectContaining({ kind: 'regular', starts_at: '', ends_at: '', starts_at_offset: '', ends_at_offset: '' }),
                }),
            ),
        );
    });

    it('selects the news type with the keyboard and returns focus to the shared trigger', async () => {
        const user = userEvent.setup();
        render(<ManageNews {...serverProps} />);
        const type = screen.getByRole('combobox', { name: 'Type' });
        act(() => type.focus());
        await user.keyboard('{Enter}');
        await waitFor(() => expect(screen.getByRole('option', { name: 'Regular news' })).toHaveFocus());
        await user.keyboard('{End}{Enter}');
        expect(type).toHaveTextContent('Temporary announcement');
        await waitFor(() => expect(type).toHaveFocus());
        expect(screen.getByLabelText('Start (Europe/Berlin)')).toBeVisible();
    });

    it('disables all shared editor selects while saving and re-enables them afterward', async () => {
        const user = userEvent.setup();
        let finishSave!: (value: { data: { id: number } }) => void;
        mocks.request.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finishSave = resolve;
                }),
        );
        render(<ManageNews {...serverProps} />);
        await user.click(row('Maintenance').getByRole('button', { name: 'Edit' }));
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(screen.getAllByRole('combobox')).toHaveLength(3);
        for (const select of screen.getAllByRole('combobox')) expect(select).toBeDisabled();

        await act(async () => finishSave({ data: { id: 3 } }));
        for (const select of screen.getAllByRole('combobox')) expect(select).toBeEnabled();
    });

    it('filters the archive with the shared checkbox using Space and its associated label', async () => {
        const user = userEvent.setup();
        render(<ManageNews {...serverProps} />);
        const filter = screen.getByRole('checkbox', { name: 'Show archived news' });
        expect(filter).not.toBeChecked();
        act(() => filter.focus());
        await user.keyboard('[Space]');
        expect(filter).toBeChecked();
        expect(row('Archived announcement').getByRole('button', { name: 'Restore' })).toBeVisible();
        expect(screen.queryByRole('row', { name: /Regular announcement/ })).not.toBeInTheDocument();

        await user.click(screen.getByText('Show archived news', { selector: 'label' }));
        expect(filter).not.toBeChecked();
        expect(row('Regular announcement').getByRole('button', { name: 'Edit' })).toBeVisible();
        expect(screen.queryByRole('row', { name: /Archived announcement/ })).not.toBeInTheDocument();
    });

    it.each([
        ['Draft announcement', 'Set as active', '/manage-news/2/activate', 1],
        ['Maintenance', 'Schedule', '/manage-news/3/schedule', 3],
        ['Upcoming downtime', 'Cancel schedule', '/manage-news/4/cancel-schedule', 2],
    ])('sends revisions for %s / %s', async (title, button, url, revision) => {
        const user = userEvent.setup();
        render(<ManageNews {...serverProps} />);
        await user.click(row(title).getByRole('button', { name: button }));
        await waitFor(() => expect(mocks.request).toHaveBeenCalledWith({ url, method: 'post', data: { revision, state_revision: 7 } }));
    });

    it.each([
        ['Draft announcement', 'Set as active', 'activate'],
        ['Maintenance', 'Schedule', 'schedule'],
        ['Upcoming downtime', 'Cancel schedule', 'cancel-schedule'],
    ])('requires saving edits before %s / %s and then uses the saved revision', async (title, button, operation) => {
        const user = userEvent.setup();
        const { rerender } = render(<ManageNews {...serverProps} />);
        const item = serverProps.news.find((entry) => entry.title === title)!;
        await user.click(row(title).getByRole('button', { name: 'Edit' }));
        fireEvent.change(screen.getByLabelText('News text (Markdown)'), { target: { value: 'Unsaved editorial changes.' } });

        expect(row(title).getByRole('button', { name: button })).toBeDisabled();
        expect(row(title).getByRole('button', { name: 'Archive' })).toBeDisabled();
        expect(screen.getByText('Save or reload your changes before changing this news status.')).toBeVisible();
        await user.click(row(title).getByRole('button', { name: button }));
        expect(mocks.request).not.toHaveBeenCalled();
        expect(screen.getByLabelText('News text (Markdown)')).toHaveValue('Unsaved editorial changes.');

        serverProps = {
            ...serverProps,
            state_revision: 8,
            news: serverProps.news.map((entry) =>
                entry.id === item.id ? { ...entry, body_markdown: 'Unsaved editorial changes.', revision: item.revision + 1 } : entry,
            ),
        };
        mocks.request.mockResolvedValue({ data: { id: item.id } });
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(mocks.reload).toHaveBeenCalled());
        rerender(<ManageNews {...serverProps} />);

        expect(mocks.request).toHaveBeenNthCalledWith(1, {
            url: `/manage-news/${item.id}`,
            method: 'patch',
            data: expect.objectContaining({ body_markdown: 'Unsaved editorial changes.', revision: item.revision }),
        });
        expect(screen.queryByText('Save or reload your changes before changing this news status.')).not.toBeInTheDocument();
        expect(row(title).getByRole('button', { name: button })).toBeEnabled();
        await user.click(row(title).getByRole('button', { name: button }));
        await waitFor(() =>
            expect(mocks.request).toHaveBeenNthCalledWith(2, {
                url: `/manage-news/${item.id}/${operation}`,
                method: 'post',
                data: { revision: item.revision + 1, state_revision: 8 },
            }),
        );
    });

    it('requires confirmation before reloading dirty edits and re-enables status actions with the latest revision', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<ManageNews {...serverProps} />);
        await user.click(row('Maintenance').getByRole('button', { name: 'Edit' }));
        fireEvent.change(screen.getByLabelText('News text (Markdown)'), { target: { value: 'Unsaved text.' } });
        expect(row('Maintenance').getByRole('button', { name: 'Schedule' })).toBeDisabled();

        vi.mocked(window.confirm).mockReturnValue(false);
        await user.click(screen.getByRole('button', { name: 'Reload latest version' }));
        expect(window.confirm).toHaveBeenCalledWith('Reload the latest news and discard the edits in this form?');
        expect(mocks.reload).not.toHaveBeenCalled();
        expect(screen.getByLabelText('News text (Markdown)')).toHaveValue('Unsaved text.');
        expect(row('Maintenance').getByRole('button', { name: 'Schedule' })).toBeDisabled();

        serverProps = {
            ...serverProps,
            news: serverProps.news.map((item) => (item.id === 3 ? { ...item, body_markdown: 'Latest saved text.', revision: 4 } : item)),
        };
        vi.mocked(window.confirm).mockReturnValue(true);
        await user.click(screen.getByRole('button', { name: 'Reload latest version' }));
        rerender(<ManageNews {...serverProps} />);
        expect(screen.getByLabelText('News text (Markdown)')).toHaveValue('Latest saved text.');
        expect(row('Maintenance').getByRole('button', { name: 'Schedule' })).toBeEnabled();
        await user.click(row('Maintenance').getByRole('button', { name: 'Schedule' }));
        await waitFor(() =>
            expect(mocks.request).toHaveBeenCalledWith({ url: '/manage-news/3/schedule', method: 'post', data: { revision: 4, state_revision: 7 } }),
        );
    });

    it('re-enables status actions when unsaved period changes are reverted', async () => {
        const user = userEvent.setup();
        render(<ManageNews {...serverProps} />);
        await user.click(row('Maintenance').getByRole('button', { name: 'Edit' }));
        const periodEnd = screen.getByLabelText('End (Europe/Berlin)');
        fireEvent.change(periodEnd, { target: { value: '2030-07-15T14:00' } });
        expect(row('Maintenance').getByRole('button', { name: 'Schedule' })).toBeDisabled();
        fireEvent.change(periodEnd, { target: { value: '2030-07-15T13:00' } });
        await chooseOption(user, 'End UTC offset', '+02:00 · Summer time (first occurrence)');
        expect(row('Maintenance').getByRole('button', { name: 'Schedule' })).toBeEnabled();
        expect(mocks.request).not.toHaveBeenCalled();
    });

    it('allows status actions on other records without discarding the dirty form', async () => {
        const user = userEvent.setup();
        render(<ManageNews {...serverProps} />);
        await user.click(row('Draft announcement').getByRole('button', { name: 'Edit' }));
        fireEvent.change(screen.getByLabelText('News text (Markdown)'), { target: { value: 'Keep my draft edits.' } });
        expect(row('Maintenance').getByRole('button', { name: 'Schedule' })).toBeEnabled();
        await user.click(row('Maintenance').getByRole('button', { name: 'Schedule' }));
        await waitFor(() => expect(mocks.request).toHaveBeenCalledTimes(1));
        expect(mocks.request).toHaveBeenCalledWith({ url: '/manage-news/3/schedule', method: 'post', data: { revision: 3, state_revision: 7 } });
        expect(screen.getByLabelText('Title')).toHaveValue('Draft announcement');
        expect(screen.getByLabelText('News text (Markdown)')).toHaveValue('Keep my draft edits.');
        expect(row('Draft announcement').getByRole('button', { name: 'Set as active' })).toBeDisabled();
    });

    it('confirms archiving and restores news without publishing them', async () => {
        const user = userEvent.setup();
        render(<ManageNews {...serverProps} />);
        await user.click(row('Maintenance').getByRole('button', { name: 'Archive' }));
        expect(mocks.request).not.toHaveBeenCalled();
        expect(screen.getByRole('alertdialog')).toHaveTextContent('cancels its schedule');
        await user.click(screen.getByRole('button', { name: 'Archive news' }));
        await waitFor(() => expect(mocks.request).toHaveBeenCalledWith(expect.objectContaining({ url: '/manage-news/3/archive' })));
        await user.click(screen.getByLabelText('Show archived news'));
        expect(screen.queryByRole('row', { name: /Maintenance/ })).not.toBeInTheDocument();
        await user.click(row('Archived announcement').getByRole('button', { name: 'Restore' }));
        await waitFor(() => expect(mocks.request).toHaveBeenCalledWith(expect.objectContaining({ url: '/manage-news/5/restore' })));
    });

    it('preserves entered text on conflicts and reloads only after explicit confirmation', async () => {
        const user = userEvent.setup();
        mocks.request.mockRejectedValue({
            isAxiosError: true,
            response: { status: 409, data: { errors: { revision: ['Changed in another session.'] } } },
        });
        render(<ManageNews {...serverProps} />);
        await user.click(row('Draft announcement').getByRole('button', { name: 'Edit' }));
        await user.clear(screen.getByLabelText('Title'));
        await user.type(screen.getByLabelText('Title'), 'Keep my edits');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Changed in another session.');
        expect(screen.getByLabelText('Title')).toHaveValue('Keep my edits');
        await user.click(within(screen.getByRole('alert')).getByRole('button', { name: 'Reload latest version' }));
        expect(window.confirm).toHaveBeenCalled();
        expect(screen.getByLabelText('Title')).toHaveValue('Draft announcement');
    });

    it('keeps the draft and restores buttons after a network failure', async () => {
        const user = userEvent.setup();
        mocks.request.mockRejectedValue(new Error('offline'));
        render(<ManageNews {...serverProps} />);
        await user.type(screen.getByLabelText('Title'), 'Keep');
        await user.type(screen.getByLabelText('News text (Markdown)'), 'Text');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('The request failed.');
        expect(screen.getByLabelText('Title')).toHaveValue('Keep');
        expect(screen.getByRole('button', { name: 'Save draft' })).toBeEnabled();
    });

    it('uses the debounced server preview and ignores older responses', async () => {
        let resolveFirst!: (value: { data: { html: string } }) => void;
        mocks.post.mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    resolveFirst = resolve;
                }),
        );
        render(<ManageNews {...serverProps} />);
        fireEvent.change(screen.getByLabelText('News text (Markdown)'), { target: { value: 'First' } });
        await waitFor(() =>
            expect(mocks.post).toHaveBeenCalledWith(
                '/manage-news/preview',
                { body_markdown: 'First' },
                expect.objectContaining({ signal: expect.any(AbortSignal) }),
            ),
        );
        fireEvent.change(screen.getByLabelText('News text (Markdown)'), { target: { value: 'Second' } });
        expect(await screen.findByText('Preview content')).toBeVisible();
        await act(async () => resolveFirst({ data: { html: '<p>Stale preview</p>' } }));
        expect(screen.queryByText('Stale preview')).not.toBeInTheDocument();
        expect(screen.getByText('Preview content').tagName).toBe('STRONG');
    });

    it('reports preview errors without losing content', async () => {
        mocks.post.mockRejectedValue(new Error('offline'));
        render(<ManageNews {...serverProps} />);
        fireEvent.change(screen.getByLabelText('News text (Markdown)'), { target: { value: 'Keep this text' } });
        expect(await screen.findByText('The preview could not be loaded. Your text is kept.')).toBeVisible();
        expect(screen.getByLabelText('News text (Markdown)')).toHaveValue('Keep this text');
    });

    it('does not discard unsaved edits when switching is cancelled', async () => {
        const user = userEvent.setup();
        vi.mocked(window.confirm).mockReturnValue(false);
        render(<ManageNews {...serverProps} />);
        await user.type(screen.getByLabelText('Title'), 'Unsaved');
        await user.click(row('Draft announcement').getByRole('button', { name: 'Edit' }));
        expect(screen.getByLabelText('Title')).toHaveValue('Unsaved');
    });
});
