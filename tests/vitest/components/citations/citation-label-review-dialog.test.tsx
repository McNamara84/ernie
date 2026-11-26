import userEvent from '@testing-library/user-event';
import { fireEvent, render, screen, waitFor } from '@tests/vitest/utils/render';
import axios from 'axios';
import { beforeEach, expect, it, vi } from 'vitest';

import { CitationLabelReviewDialog } from '@/components/citations/citation-label-review-dialog';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));
const post = vi.mocked(axios.post);
const skipped = { other_type: 0, changed_text: 0, insufficient_metadata: 0, lookup_failed: 0 };
function candidate(id: number) {
    return {
        id,
        resource_id: id,
        resource: 'Resource ' + id,
        doi: '10.1234/' + id,
        work_type: 'Journal article',
        old: 'Old label ' + id,
        text: 'Science ' + id,
        parts: [{ text: 'Science ' + id, italic: true }],
        token: 'token-' + id,
    };
}
beforeEach(() => post.mockReset());

it('pages the preview, explains skipped rows, and submits only the checked proposals', async () => {
    post.mockResolvedValueOnce({ data: { candidates: [candidate(1)], skipped: { ...skipped, changed_text: 1 }, next_cursor: 16 } })
        .mockResolvedValueOnce({ data: { candidates: [candidate(2)], skipped: { ...skipped, changed_text: 1 }, next_cursor: null } })
        .mockResolvedValueOnce({ data: { results: [{ id: 2, status: 'conflict' }] } });
    render(<CitationLabelReviewDialog ids={[1, 2]} onClose={vi.fn()} />);
    expect(await screen.findByText('Old label 1')).toBeVisible();
    expect(screen.getByText('Science 1').tagName).toBe('EM');
    expect(screen.getByText(/Stored text differs.*\(2\)/)).toBeVisible();
    expect(post.mock.calls[1][1]).toEqual({ ids: [1, 2], cursor: 16, review_id: expect.any(String) });
    expect(screen.getByRole('button', { name: /Apply selected/ })).toBeDisabled();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Select all' }));
    fireEvent.click(screen.getByRole('checkbox', { name: /Resource 1/ }));
    fireEvent.click(screen.getByRole('button', { name: /Apply selected \(1\)/ }));
    expect(await screen.findByText('Changed since preview. Reopen to review.')).toBeVisible();
    expect(post.mock.calls[2]).toEqual(['/resources/citation-labels/apply', { tokens: ['token-2'] }]);
    expect(screen.getByRole('checkbox', { name: /Resource 2/ })).toBeDisabled();
    expect(screen.getByRole('checkbox', { name: /Resource 1/ })).toBeEnabled();
});

it('can retry a failed preview and close before any change is applied', async () => {
    post.mockRejectedValueOnce(new Error()).mockResolvedValueOnce({ data: { candidates: [], skipped, next_cursor: null } });
    const close = vi.fn();
    render(<CitationLabelReviewDialog ids={[7]} onClose={close} />);
    await screen.findByRole('alert');
    fireEvent.click(screen.getByRole('button', { name: 'Retry preview' }));
    expect(await screen.findByText('No matching citation labels found.')).toBeVisible();
    fireEvent.click(screen.getAllByRole('button', { name: 'Close' })[0]);
    expect(close).toHaveBeenCalledOnce();
    expect(post.mock.calls.map(([url]) => url)).toEqual(['/resources/citation-labels/preview', '/resources/citation-labels/preview']);
});

it('supports keyboard selection and announces a partially selected review', async () => {
    const user = userEvent.setup();
    post.mockResolvedValueOnce({ data: { candidates: [candidate(1), candidate(2)], skipped, next_cursor: null } });
    render(<CitationLabelReviewDialog ids={[1, 2]} onClose={vi.fn()} />);
    const selectAll = await screen.findByRole('checkbox', { name: 'Select all' });
    const first = screen.getByRole('checkbox', { name: /Resource 1/ });
    const second = screen.getByRole('checkbox', { name: /Resource 2/ });
    selectAll.focus();
    await user.tab();
    expect(first).toHaveFocus();
    await user.keyboard('[Space]');
    expect(first).toBeChecked();
    expect(second).not.toBeChecked();
    expect(selectAll).toBePartiallyChecked();

    await user.tab({ shift: true });
    expect(selectAll).toHaveFocus();
    await user.keyboard('[Space]');
    expect(selectAll).toBeChecked();
    expect(second).toBeChecked();
    await user.click(screen.getByText('Select all'));
    expect(first).not.toBeChecked();
    expect(second).not.toBeChecked();
    expect(screen.getByRole('button', { name: /Apply selected/ })).toBeDisabled();
});

it('keeps pending selections for a safe retry after a failed confirmation', async () => {
    post.mockResolvedValueOnce({ data: { candidates: [candidate(3)], skipped, next_cursor: null } })
        .mockRejectedValueOnce(new Error())
        .mockResolvedValueOnce({ data: { results: [{ id: 3, status: 'already_applied' }] } });
    render(<CitationLabelReviewDialog ids={[3]} onClose={vi.fn()} />);
    fireEvent.click(await screen.findByRole('checkbox', { name: /Resource 3/ }));
    const apply = screen.getByRole('button', { name: /Apply selected/ });
    fireEvent.click(apply);
    await screen.findByRole('alert');
    fireEvent.click(apply);
    await waitFor(() => expect(screen.getByText('Already applied')).toBeVisible());
    expect(screen.getByRole('checkbox', { name: 'Select all' })).toBeDisabled();
    expect(post.mock.calls[2]).toEqual(post.mock.calls[1]);
});
