import '@testing-library/jest-dom/vitest';

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { SidebarProvider } from '@/components/ui/sidebar';
import type { FeedbackTechnicalSnapshot } from '@/types/feedback';

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    snapshot: vi.fn(),
    toastSuccess: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: {
                user: {
                    id: 17,
                    name: 'Jane Curator',
                    email: 'jane@example.org',
                    role: 'curator',
                },
            },
        },
    }),
}));

vi.mock('axios', () => ({
    default: {
        isAxiosError: (error: unknown) => typeof error === 'object' && error !== null && 'isAxiosError' in error,
        post: mocks.post,
    },
}));

vi.mock('sonner', () => ({
    toast: { success: mocks.toastSuccess },
}));

vi.mock('@/lib/feedback-diagnostics', () => ({
    createFeedbackTechnicalSnapshot: mocks.snapshot,
}));

import { UserFeedbackDialog } from '@/components/user-feedback-dialog';

const technicalSnapshot: FeedbackTechnicalSnapshot = {
    page: { path: '/resources', title: 'Resources — ERNIE' },
    environment: {
        appearance: 'system',
        resolved_theme: 'dark',
        viewport_width: 1440,
        viewport_height: 900,
        device_pixel_ratio: 2,
        locale: 'en-GB',
        timezone: 'Europe/Berlin',
    },
    diagnostics: [
        { type: 'navigation', occurred_at: '2026-09-02T12:00:00.000Z', path: '/resources' },
        {
            type: 'http_error',
            occurred_at: '2026-09-02T12:01:00.000Z',
            method: 'GET',
            path: '/resource-inventory',
            status: 503,
            message: 'HTTP request failed (503)',
        },
    ],
};

function renderDialog() {
    return render(
        <SidebarProvider defaultOpen>
            <UserFeedbackDialog />
        </SidebarProvider>,
    );
}

async function openDialog() {
    const user = userEvent.setup();
    const trigger = screen.getByRole('button', { name: /give feedback/i });
    await user.click(trigger);
    return { dialog: screen.getByRole('dialog'), trigger, user };
}

async function completeValidForm(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole('radio', { name: /idea/i }));
    await user.type(screen.getByRole('textbox', { name: /your feedback/i }), 'Please keep the new resource filters after navigating back.');
}

describe('UserFeedbackDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.snapshot.mockReturnValue(structuredClone(technicalSnapshot));
        mocks.post.mockResolvedValue({ data: { message: 'Feedback submitted.', feedback_id: 'feedback-id' } });
    });

    it('offers a quiet, accessible sidebar action and explains the feedback flow', async () => {
        renderDialog();
        const { dialog } = await openDialog();

        expect(within(dialog).getByRole('heading', { name: 'Give feedback' })).toBeInTheDocument();
        expect(within(dialog).getByText(/usually takes less than a minute/i)).toBeInTheDocument();
        expect(within(dialog).getAllByRole('radio')).toHaveLength(4);
        expect(within(dialog).getByRole('radio', { name: /problem/i })).not.toBeChecked();
        expect(within(dialog).getByRole('radio', { name: /idea/i })).not.toBeChecked();
        expect(within(dialog).getByRole('radio', { name: /praise/i })).not.toBeChecked();
        expect(within(dialog).getByRole('radio', { name: /other/i })).not.toBeChecked();
        expect(within(dialog).getByText('Jane Curator')).toBeInTheDocument();
        expect(within(dialog).getByText('jane@example.org')).toBeInTheDocument();
        expect(within(dialog).getByText(/emailed to all active ERNIE administrators/i)).toBeInTheDocument();
        expect(within(dialog).getByRole('link', { name: /data privacy protection/i })).toHaveAttribute(
            'href',
            'https://dataservices.gfz-potsdam.de/web/about-us/data-protection',
        );
        expect(mocks.snapshot).toHaveBeenCalledOnce();
        expect(mocks.snapshot).toHaveBeenCalledWith(17);
    });

    it('shows exactly the technical snapshot that will be submitted', async () => {
        renderDialog();
        const { user } = await openDialog();

        expect(screen.queryByText('/resource-inventory')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /technical details included/i }));

        expect(screen.getByText('/resources')).toBeInTheDocument();
        expect(screen.getByText('system (resolved: dark)')).toBeInTheDocument();
        expect(screen.getByText(/1440 × 900 CSS px · DPR 2/)).toBeInTheDocument();
        expect(screen.getByText('en-GB · Europe/Berlin')).toBeInTheDocument();
        expect(screen.getByText('GET /resource-inventory · HTTP 503')).toBeInTheDocument();
        expect(screen.getByText(/2 recent events/)).toBeInTheDocument();
    });

    it('validates both required inputs and focuses the first invalid control', async () => {
        renderDialog();
        const { user } = await openDialog();

        await user.click(screen.getByRole('button', { name: /send feedback/i }));

        expect(screen.getByText('Choose what you would like to share.')).toHaveAttribute('role', 'alert');
        expect(screen.getByText('Enter at least 10 characters.')).toHaveAttribute('role', 'alert');
        await waitFor(() => expect(screen.getByRole('radio', { name: /problem/i })).toHaveFocus());

        await user.click(screen.getByRole('radio', { name: /problem/i }));
        await user.click(screen.getByRole('button', { name: /send feedback/i }));
        await waitFor(() => expect(screen.getByRole('textbox', { name: /your feedback/i })).toHaveFocus());
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('updates the character count and rejects more than 5,000 characters', async () => {
        renderDialog();
        const { user } = await openDialog();
        await user.click(screen.getByRole('radio', { name: /problem/i }));
        const textbox = screen.getByRole('textbox', { name: /your feedback/i });

        fireEvent.change(textbox, { target: { value: 'x'.repeat(5001) } });
        await user.click(screen.getByRole('button', { name: /send feedback/i }));

        expect(screen.getByText('5,001 / 5,000')).toBeInTheDocument();
        expect(screen.getByText('Feedback must not exceed 5,000 characters.')).toBeInTheDocument();
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('retains the unsent in-memory draft when closed and refreshes only the technical snapshot', async () => {
        renderDialog();
        const { user } = await openDialog();
        await completeValidForm(user);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        await user.click(screen.getByRole('button', { name: /give feedback/i }));

        expect(screen.getByRole('radio', { name: /idea/i })).toBeChecked();
        expect(screen.getByRole('textbox', { name: /your feedback/i })).toHaveValue('Please keep the new resource filters after navigating back.');
        expect(mocks.snapshot).toHaveBeenCalledTimes(2);
        expect(window.sessionStorage.length).toBe(0);
    });

    it('submits the visible snapshot once, disables dismissal while pending, then clears the form', async () => {
        let resolveRequest: ((value: unknown) => void) | undefined;
        mocks.post.mockImplementationOnce(() => new Promise((resolve) => (resolveRequest = resolve)));
        renderDialog();
        const { user } = await openDialog();
        await completeValidForm(user);

        await user.click(screen.getByRole('button', { name: /send feedback/i }));
        expect(screen.getByRole('button', { name: /sending/i })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        await user.click(screen.getByRole('button', { name: /sending/i }));
        expect(mocks.post).toHaveBeenCalledOnce();
        expect(mocks.post).toHaveBeenCalledWith('/feedback', {
            category: 'idea',
            message: 'Please keep the new resource filters after navigating back.',
            ...technicalSnapshot,
        });

        await act(async () => resolveRequest?.({ data: { feedback_id: 'feedback-id' } }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(mocks.toastSuccess).toHaveBeenCalledWith('Thanks — your feedback has been submitted.');

        await user.click(screen.getByRole('button', { name: /give feedback/i }));
        expect(screen.getByRole('textbox', { name: /your feedback/i })).toHaveValue('');
        expect(screen.getByRole('radio', { name: /idea/i })).not.toBeChecked();
    });

    it('keeps the draft and focuses a server-invalid field', async () => {
        mocks.post.mockRejectedValueOnce({
            isAxiosError: true,
            response: {
                status: 422,
                data: { message: 'The given data was invalid.', errors: { message: ['The feedback message contains invalid content.'] } },
            },
        });
        renderDialog();
        const { user } = await openDialog();
        await completeValidForm(user);

        await user.click(screen.getByRole('button', { name: /send feedback/i }));

        expect(await screen.findByText('The feedback message contains invalid content.')).toHaveAttribute('role', 'alert');
        expect(screen.getByRole('textbox', { name: /your feedback/i })).toHaveValue('Please keep the new resource filters after navigating back.');
        await waitFor(() => expect(screen.getByRole('textbox', { name: /your feedback/i })).toHaveFocus());
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });

    it.each([
        [429, {}, 'You have submitted feedback several times. Please wait a few minutes and try again.'],
        [
            503,
            { message: 'Feedback cannot be submitted right now because no administrator is available.' },
            'Feedback cannot be submitted right now because no administrator is available.',
        ],
        [undefined, undefined, 'Feedback could not be submitted. Check your connection and try again.'],
    ])('keeps the form open after a %s submission failure', async (status, data, expected) => {
        mocks.post.mockRejectedValueOnce(status ? { isAxiosError: true, response: { status, data } } : new TypeError('Network error'));
        renderDialog();
        const { user } = await openDialog();
        await completeValidForm(user);

        await user.click(screen.getByRole('button', { name: /send feedback/i }));

        expect(await screen.findByRole('alert')).toHaveTextContent(expected);
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: /idea/i })).toBeChecked();
    });

    it('closes on Escape and restores focus to the sidebar trigger', async () => {
        renderDialog();
        const { trigger, user } = await openDialog();

        await user.keyboard('{Escape}');

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(trigger).toHaveFocus();
    });
});
