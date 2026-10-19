import userEvent from '@testing-library/user-event';
import { act, fireEvent, render, screen, waitFor } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ThesaurusReviewStatus } from '@/components/settings/thesaurus-review-status';
import type { ThesaurusReviewReminder } from '@/types/thesaurus-review';

const post = vi.hoisted(() => vi.fn());
vi.mock('axios', () => ({ default: { post }, isAxiosError: (error: unknown) => !!error && typeof error === 'object' && 'response' in error }));

const open: ThesaurusReviewReminder = { period: '2026-Q4', isDue: true, completedAt: null };
const completed: ThesaurusReviewReminder = { ...open, isDue: false, completedAt: '2026-10-19T10:00:00Z' };
const completeButton = () => screen.getByRole('button', { name: "Mark this quarter's review as complete" });

describe('ThesaurusReviewStatus', () => {
    beforeEach(() => {
        post.mockReset();
    });

    it('completes the displayed quarter without submitting the surrounding settings form', async () => {
        const submit = vi.fn((event: React.FormEvent) => event.preventDefault());
        post.mockResolvedValue({ data: { thesaurusReviewReminder: completed } });
        render(
            <form onSubmit={submit}>
                <input defaultValue="Unsaved setting" />
                <ThesaurusReviewStatus reminder={open} />
            </form>,
        );
        await userEvent.click(completeButton());
        expect(post).toHaveBeenCalledExactlyOnceWith('/thesauri/review-completion', { period: '2026-Q4' });
        expect(submit).not.toHaveBeenCalled();
        expect(screen.getByRole('textbox')).toHaveValue('Unsaved setting');
        expect(screen.getByRole('status')).toHaveTextContent('Review completed for this quarter.');
        expect(screen.getByRole('status').querySelector('time')).toHaveAttribute('datetime', completed.completedAt);
        expect(screen.getByRole('button', { name: 'Review completed' })).toBeDisabled();
    });

    it('prevents duplicate requests while waiting for the server', async () => {
        let resolve!: (value: unknown) => void;
        post.mockReturnValue(
            new Promise((done) => {
                resolve = done;
            }),
        );
        render(<ThesaurusReviewStatus reminder={open} />);
        const button = completeButton();
        fireEvent.click(button);
        fireEvent.click(button);
        expect(post).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('button', { name: 'Completing review…' })).toBeDisabled();
        await act(async () => resolve({ data: { thesaurusReviewReminder: completed } }));
        expect(screen.getByRole('button', { name: 'Review completed' })).toBeDisabled();
    });

    it('keeps failed requests open and supports retrying', async () => {
        post.mockRejectedValueOnce(new Error('Offline')).mockResolvedValueOnce({ data: { thesaurusReviewReminder: completed } });
        render(<ThesaurusReviewStatus reminder={open} />);
        await userEvent.click(completeButton());
        expect(screen.getByRole('alert')).toHaveTextContent('Could not complete the review.');
        expect(completeButton()).toBeEnabled();
        expect(screen.getByRole('status')).toBeEmptyDOMElement();
        await userEvent.click(completeButton());
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('Review completed');
    });

    it('requires another explicit confirmation after a quarter conflict', async () => {
        const next = { period: '2027-Q1', isDue: true, completedAt: null };
        post.mockRejectedValueOnce({ response: { status: 409, data: { thesaurusReviewReminder: next } } }).mockResolvedValueOnce({
            data: { thesaurusReviewReminder: { ...next, isDue: false } },
        });
        render(<ThesaurusReviewStatus reminder={open} />);
        await userEvent.click(completeButton());
        expect(screen.getByRole('alert')).toHaveTextContent('The quarter has changed');
        expect(screen.getByText(/Quarterly thesaurus review/)).toHaveTextContent('2027-Q1');
        expect(post).toHaveBeenCalledTimes(1);
        await userEvent.click(completeButton());
        expect(post).toHaveBeenLastCalledWith('/thesauri/review-completion', { period: '2027-Q1' });
    });

    it('accepts a conflict response for an already completed newer quarter', async () => {
        post.mockRejectedValue({ response: { status: 409, data: { thesaurusReviewReminder: { ...completed, period: '2027-Q1' } } } });
        render(<ThesaurusReviewStatus reminder={open} />);
        await userEvent.click(completeButton());
        expect(screen.getByRole('button', { name: 'Review completed' })).toBeDisabled();
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('preserves completion through partial prop reloads and accepts a newer server quarter', async () => {
        post.mockResolvedValue({ data: { thesaurusReviewReminder: completed } });
        const view = render(<ThesaurusReviewStatus reminder={open} />);
        await userEvent.click(completeButton());
        view.rerender(<ThesaurusReviewStatus reminder={{ ...open }} />);
        expect(screen.getByRole('button', { name: 'Review completed' })).toBeDisabled();
        view.rerender(<ThesaurusReviewStatus reminder={{ ...open, period: '2027-Q1' }} />);
        expect(completeButton()).toBeEnabled();
    });

    it('accepts completion by another user and handles missing optional timestamps', () => {
        const view = render(<ThesaurusReviewStatus reminder={open} />);
        view.rerender(<ThesaurusReviewStatus reminder={{ ...completed, completedAt: null }} />);
        expect(screen.getByRole('status')).toHaveTextContent('Review completed for this quarter.');
        expect(screen.getByRole('status').querySelector('time')).toBeNull();
        expect(screen.getByRole('button', { name: 'Review completed' })).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
    });

    it('shows a retryable error when a conflict response lacks the current status', async () => {
        post.mockRejectedValue({ response: { status: 409, data: {} } });
        render(<ThesaurusReviewStatus reminder={open} />);
        await userEvent.click(completeButton());
        await waitFor(() => expect(completeButton()).toBeEnabled());
        expect(screen.getByRole('alert')).toHaveTextContent('Could not complete the review.');
    });
});
