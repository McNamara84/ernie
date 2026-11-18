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
        expect(screen.getByLabelText('Start UTC offset')).toHaveValue('+02:00');
        fireEvent.change(screen.getByLabelText('Start (Europe/Berlin)'), { target: { value: '2030-07-15T11:00' } });
        expect(screen.getByLabelText('Start UTC offset')).toHaveValue('');
        await user.selectOptions(screen.getByLabelText('Start UTC offset'), '+02:00');
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
