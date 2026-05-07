import '@testing-library/jest-dom/vitest';

import { router } from '@inertiajs/react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { AssignGuidedToursDialog } from '@/components/assign-guided-tours-dialog';

vi.mock('@inertiajs/react', () => ({
    router: {
        post: vi.fn(),
    },
}));

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

const defaultUser = {
    id: 7,
    name: 'Curator User',
    role: 'curator',
    guided_tour_assignments: [
        {
            guided_tour_id: 1,
            status: 'completed',
            assignment_source: 'automatic',
            assigned_at: '2026-05-01T10:00:00Z',
            completed_at: '2026-05-01T10:05:00Z',
        },
        {
            guided_tour_id: 2,
            status: 'in_progress',
            assignment_source: 'manual',
            assigned_at: '2026-05-02T10:00:00Z',
            completed_at: null,
        },
    ],
};

const eligibleTours = [
    {
        id: 1,
        key: 'curator-review-tour',
        version: 1,
        name: 'Curator Review Tour',
        description: 'Explains review points for curator users.',
        start_route: 'dashboard',
        target_roles: ['curator'],
    },
    {
        id: 2,
        key: 'curator-quality-tour',
        version: 2,
        name: 'Curator Quality Tour',
        description: 'Focuses on curator quality checks.',
        start_route: 'dashboard',
        target_roles: ['curator'],
    },
    {
        id: 3,
        key: 'curator-help-tour',
        version: 1,
        name: 'Curator Help Tour',
        description: 'Adds additional onboarding help.',
        start_route: 'dashboard',
        target_roles: ['curator'],
    },
    {
        id: 99,
        key: 'beginner-tour',
        version: 1,
        name: 'Beginner Tour',
        description: 'Not available for curator users.',
        start_route: 'dashboard',
        target_roles: ['beginner'],
    },
];

describe('AssignGuidedToursDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders nothing when no tours are eligible for the selected user role', () => {
        render(
            <AssignGuidedToursDialog
                user={defaultUser}
                tours={[
                    {
                        id: 88,
                        key: 'beginner-tour',
                        version: 1,
                        name: 'Beginner Tour',
                        description: 'Only for beginner users.',
                        start_route: 'dashboard',
                        target_roles: ['beginner'],
                    },
                ]}
            />,
        );

        expect(screen.queryByRole('button', { name: /assign tours to curator user/i })).not.toBeInTheDocument();
    });

    it('renders formatted assignment statuses for eligible tours', async () => {
        const user = userEvent.setup();

        render(<AssignGuidedToursDialog user={defaultUser} tours={eligibleTours} />);

        await user.click(screen.getByRole('button', { name: /assign tours to curator user/i }));

        expect(screen.getByText('Completed')).toBeInTheDocument();
        expect(screen.getByText('In Progress')).toBeInTheDocument();
        expect(screen.getByText('Not Assigned')).toBeInTheDocument();
        expect(screen.queryByText('Beginner Tour')).not.toBeInTheDocument();
    });

    it('disables the trigger button when the dialog is disabled', () => {
        render(<AssignGuidedToursDialog user={defaultUser} tours={eligibleTours} disabled />);

        expect(screen.getByRole('button', { name: /assign tours to curator user/i })).toBeDisabled();
    });

    it('enables selection and resets it when the same checkbox is toggled off again', async () => {
        const user = userEvent.setup();

        render(<AssignGuidedToursDialog user={defaultUser} tours={eligibleTours} />);

        await user.click(screen.getByRole('button', { name: /assign tours to curator user/i }));

        const submitButton = screen.getByRole('button', { name: /assign selected tours/i });
        const checkbox = screen.getByRole('checkbox', { name: /curator help tour/i });

        expect(submitButton).toBeDisabled();

        await user.click(checkbox);
        expect(submitButton).toBeEnabled();

        await user.click(checkbox);
        expect(submitButton).toBeDisabled();
    });

    it('returns early when submit is triggered without any selected tours', async () => {
        const user = userEvent.setup();

        render(<AssignGuidedToursDialog user={defaultUser} tours={eligibleTours} />);

        await user.click(screen.getByRole('button', { name: /assign tours to curator user/i }));

        const submitButton = screen.getByRole('button', { name: /assign selected tours/i });
        submitButton.removeAttribute('disabled');

        fireEvent.click(submitButton);

        expect(router.post).not.toHaveBeenCalled();
    });

    it('resets the selection when the dialog is closed through the dialog close control', async () => {
        const user = userEvent.setup();

        render(<AssignGuidedToursDialog user={defaultUser} tours={eligibleTours} />);

        await user.click(screen.getByRole('button', { name: /assign tours to curator user/i }));
        await user.click(screen.getByRole('checkbox', { name: /curator help tour/i }));
        await user.click(screen.getByRole('button', { name: /close/i }));

        await waitFor(() => {
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });

        await user.click(screen.getByRole('button', { name: /assign tours to curator user/i }));
        expect(screen.getByRole('button', { name: /assign selected tours/i })).toBeDisabled();
    });

    it('closes the dialog when cancel is clicked', async () => {
        const user = userEvent.setup();

        render(<AssignGuidedToursDialog user={defaultUser} tours={eligibleTours} />);

        await user.click(screen.getByRole('button', { name: /assign tours to curator user/i }));
        await user.click(screen.getByRole('button', { name: /^cancel$/i }));

        await waitFor(() => {
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
    });

    it('submits selected tours, closes the dialog, and resets the selection on success', async () => {
        const user = userEvent.setup();

        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            const postOptions = options as Record<string, unknown> | undefined;
            const onSuccess = postOptions?.onSuccess as (() => void) | undefined;
            const onFinish = postOptions?.onFinish as (() => void) | undefined;

            onSuccess?.();
            onFinish?.();
        });

        render(<AssignGuidedToursDialog user={defaultUser} tours={eligibleTours} />);

        await user.click(screen.getByRole('button', { name: /assign tours to curator user/i }));
        await user.click(screen.getByRole('checkbox', { name: /curator help tour/i }));
        await user.click(screen.getByRole('button', { name: /assign selected tours/i }));

        await waitFor(() => {
            expect(router.post).toHaveBeenCalledWith(
                '/users/7/guided-tours',
                { tour_ids: [3] },
                expect.objectContaining({ preserveScroll: true }),
            );
            expect(toast.success).toHaveBeenCalledWith('Guided tours assigned successfully');
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });

        await user.click(screen.getByRole('button', { name: /assign tours to curator user/i }));
        expect(screen.getByRole('button', { name: /assign selected tours/i })).toBeDisabled();
    });

    it('shows the first server error message when the assignment request fails', async () => {
        const user = userEvent.setup();

        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            const postOptions = options as Record<string, unknown> | undefined;
            const onError = postOptions?.onError as ((errors: Record<string, string>) => void) | undefined;
            const onFinish = postOptions?.onFinish as (() => void) | undefined;

            onError?.({ tour_ids: 'The selected tour is not valid for this user.' });
            onFinish?.();
        });

        render(<AssignGuidedToursDialog user={defaultUser} tours={eligibleTours} />);

        await user.click(screen.getByRole('button', { name: /assign tours to curator user/i }));
        await user.click(screen.getByRole('checkbox', { name: /curator help tour/i }));
        await user.click(screen.getByRole('button', { name: /assign selected tours/i }));

        await waitFor(() => {
            expect(toast.error).toHaveBeenCalledWith('The selected tour is not valid for this user.');
        });
    });

    it('falls back to a generic error toast when the error payload is empty', async () => {
        const user = userEvent.setup();

        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            const postOptions = options as Record<string, unknown> | undefined;
            const onError = postOptions?.onError as ((errors: Record<string, string>) => void) | undefined;
            const onFinish = postOptions?.onFinish as (() => void) | undefined;

            onError?.({});
            onFinish?.();
        });

        render(<AssignGuidedToursDialog user={defaultUser} tours={eligibleTours} />);

        await user.click(screen.getByRole('button', { name: /assign tours to curator user/i }));
        await user.click(screen.getByRole('checkbox', { name: /curator help tour/i }));
        await user.click(screen.getByRole('button', { name: /assign selected tours/i }));

        await waitFor(() => {
            expect(toast.error).toHaveBeenCalledWith('Failed to assign guided tours');
        });
    });
});