import '@testing-library/jest-dom/vitest';

import { fireEvent } from '@testing-library/react';
import { render, screen } from '@tests/vitest/utils/render';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { AssessmentPageProps } from '@/types/assessment';

const { mockRouterGet, mockRouterReload } = vi.hoisted(() => ({
    mockRouterGet: vi.fn(),
    mockRouterReload: vi.fn(),
}));

const { mockAxiosGet, mockAxiosPost } = vi.hoisted(() => ({
    mockAxiosGet: vi.fn(),
    mockAxiosPost: vi.fn(),
}));

const { mockToast } = vi.hoisted(() => ({
    mockToast: {
        success: vi.fn(),
        error: vi.fn(),
        warning: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { get: mockRouterGet, reload: mockRouterReload },
}));

vi.mock('axios', () => ({
    default: {
        get: mockAxiosGet,
        post: mockAxiosPost,
        isAxiosError: (error: unknown) => error instanceof Error && 'isAxiosError' in error,
    },
}));

vi.mock('sonner', () => ({
    toast: mockToast,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

import AssessmentPage from '@/pages/assessment';

function createAxiosError(status: number, data: Record<string, unknown> = {}) {
    return Object.assign(new Error(`Request failed with status code ${status}`), {
        isAxiosError: true,
        response: {
            status,
            data,
        },
    });
}

const completeOpportunity = {
    status: 'complete',
    message: 'No FAIR improvement gap was found.',
} as const;

function makeProps(overrides: Partial<AssessmentPageProps> = {}): AssessmentPageProps {
    return {
        fujiConfigured: true,
        fujiHealthy: true,
        fujiStatusMessage: null,
        fujiStatusCode: null,
        canRunAssessments: true,
        showImprovementActorLabels: true,
        includeExternalResources: false,
        resourcesNeedingAttention: [
            {
                id: 1,
                doi: '10.5880/test.001',
                mainTitle: 'Lowest resource score',
                score: 11.25,
                assessedAt: '2026-05-04T09:00:00+00:00',
                improvementOpportunity: completeOpportunity,
            },
        ],
        igsnsNeedingAttention: [
            {
                id: 2,
                doi: '10.5880/test.igsn.001',
                mainTitle: 'Lowest IGSN score',
                score: 18.5,
                assessedAt: '2026-05-04T10:00:00+00:00',
                improvementOpportunity: completeOpportunity,
            },
        ],
        resourceAssessmentSummary: {
            total: 10,
            assessed: 6,
            failed: 1,
            skipped: 1,
            unassessed: 2,
        },
        igsnAssessmentSummary: {
            total: 4,
            assessed: 2,
            failed: 0,
            skipped: 1,
            unassessed: 1,
        },
        ...overrides,
    };
}

describe('Assessment page', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        mockAxiosGet.mockReset();
        mockAxiosPost.mockReset();
        mockRouterGet.mockReset();
        mockRouterReload.mockReset();
        mockToast.success.mockReset();
        mockToast.error.mockReset();
        mockToast.warning.mockReset();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('renders both assessment tables with DOI, main title, and score', () => {
        render(<AssessmentPage {...makeProps()} />);

        expect(screen.getByRole('heading', { name: 'Assessment' })).toBeInTheDocument();
        expect(screen.getAllByText('10.5880/test.001')).toHaveLength(2);
        expect(screen.getByText('Lowest resource score')).toBeInTheDocument();
        expect(screen.getByText('11.25%')).toBeInTheDocument();
        expect(screen.getAllByText('10.5880/test.igsn.001')).toHaveLength(2);
        expect(screen.getByText('Lowest IGSN score')).toBeInTheDocument();
        expect(screen.getByText('18.50%')).toBeInTheDocument();
    });

    it('always stacks the Resource card before the IGSN card', () => {
        render(<AssessmentPage {...makeProps()} />);

        const stack = screen.getByTestId('assessment-card-stack');
        const resourceHeading = screen.getByRole('heading', { name: 'Resources needing your attention' });
        const igsnHeading = screen.getByRole('heading', { name: 'IGSNs needing your attention' });

        expect(stack).toHaveClass('grid', 'gap-6');
        expect(stack.className).not.toMatch(/grid-cols-/);
        expect(resourceHeading.compareDocumentPosition(igsnHeading) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it.each([
        [
            {
                total: 0,
                assessed: 0,
                failed: 0,
                skipped: 0,
                unassessed: 0,
            },
            'No resources are available.',
        ],
        [
            {
                total: 5,
                assessed: 0,
                failed: 0,
                skipped: 0,
                unassessed: 5,
            },
            'No assessment results available yet. Run Check Resources to populate this list.',
        ],
        [
            {
                total: 5,
                assessed: 0,
                failed: 1,
                skipped: 1,
                unassessed: 3,
            },
            'No completed resource assessments are available yet.',
        ],
        [
            {
                total: 5,
                assessed: 1,
                failed: 0,
                skipped: 0,
                unassessed: 4,
            },
            'No resources currently require attention.',
        ],
    ])('renders the correct empty state message for resources: %s', (summary, expectedMessage) => {
        render(
            <AssessmentPage
                {...makeProps({
                    resourcesNeedingAttention: [],
                    resourceAssessmentSummary: summary,
                })}
            />,
        );

        expect(screen.getByText(expectedMessage)).toBeInTheDocument();
    });

    it('renders N/A when an assessment entry has no DOI', () => {
        render(
            <AssessmentPage
                {...makeProps({
                    resourcesNeedingAttention: [
                        {
                            id: 1,
                            doi: null,
                            mainTitle: 'Untitled assessment target',
                            score: 9.5,
                            assessedAt: '2026-05-04T09:00:00+00:00',
                            improvementOpportunity: completeOpportunity,
                        },
                    ],
                })}
            />,
        );

        expect(screen.getAllByText('N/A')).toHaveLength(2);
    });

    it('keeps external landing pages excluded by default and reloads only the resource ranking when enabled', () => {
        render(<AssessmentPage {...makeProps()} />);

        const filter = screen.getByRole('switch', { name: 'Include resources with external landing pages' });

        expect(filter).not.toBeChecked();

        fireEvent.click(filter);

        expect(mockRouterGet).toHaveBeenCalledWith(
            '/assessment',
            { include_external_resources: true },
            {
                only: ['includeExternalResources', 'resourcesNeedingAttention'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    });

    it('removes the external-resource query parameter when returning to the default filter', () => {
        render(<AssessmentPage {...makeProps({ includeExternalResources: true })} />);

        const filter = screen.getByRole('switch', { name: 'Include resources with external landing pages' });

        expect(filter).toBeChecked();

        fireEvent.click(filter);

        expect(mockRouterGet).toHaveBeenCalledWith(
            '/assessment',
            {},
            {
                only: ['includeExternalResources', 'resourcesNeedingAttention'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    });

    it('uses fixed table layouts and truncates long titles without discarding the full value', () => {
        const longTitle = 'A very long resource title that must not push the FAIR opportunity and score columns outside their card';

        render(
            <AssessmentPage
                {...makeProps({
                    resourcesNeedingAttention: [
                        {
                            id: 1,
                            doi: '10.5880/test.long-title',
                            mainTitle: longTitle,
                            score: 9.5,
                            assessedAt: '2026-05-04T09:00:00+00:00',
                            improvementOpportunity: completeOpportunity,
                        },
                    ],
                })}
            />,
        );

        expect(screen.getAllByRole('table').every((table) => table.classList.contains('table-fixed'))).toBe(true);

        const title = screen.getByText(longTitle);
        expect(title).toHaveClass('truncate');
        expect(title).toHaveAttribute('title', longTitle);
    });

    it('renders a read-only curator view with a role-appropriate empty state', () => {
        render(
            <AssessmentPage
                {...makeProps({
                    canRunAssessments: false,
                    resourcesNeedingAttention: [],
                    resourceAssessmentSummary: {
                        total: 5,
                        assessed: 0,
                        failed: 0,
                        skipped: 0,
                        unassessed: 5,
                    },
                })}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Check all' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Check Resources' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Check IGSNs' })).not.toBeInTheDocument();
        expect(screen.getByText('No assessment results are available yet. Ask an Admin or Group Leader to run Check Resources.')).toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Include resources with external landing pages' })).toBeInTheDocument();
    });

    it('starts a resource assessment and reloads the page after the polling job completes', async () => {
        mockAxiosPost.mockResolvedValueOnce({ data: { jobId: '11111111-1111-4111-8111-111111111111' } });
        mockAxiosGet.mockResolvedValueOnce({
            data: {
                status: 'completed',
                progress: 'Resources assessment completed.',
                assessedResources: 3,
            },
        });

        render(<AssessmentPage {...makeProps()} />);

        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'Check Resources' }));
        });

        expect(mockAxiosPost).toHaveBeenCalledWith('/assessment/check-resources');

        await act(async () => {
            await vi.runAllTimersAsync();
        });

        expect(mockAxiosGet).toHaveBeenCalledWith('/assessment/check/resource/11111111-1111-4111-8111-111111111111/status');

        expect(mockRouterReload).toHaveBeenCalledWith({
            only: ['resourcesNeedingAttention', 'igsnsNeedingAttention', 'resourceAssessmentSummary', 'igsnAssessmentSummary'],
        });
        expect(mockToast.success).toHaveBeenCalledWith('Resources assessment completed.');
    });

    it('starts an IGSN assessment and reloads the page after the polling job completes', async () => {
        mockAxiosPost.mockResolvedValueOnce({ data: { jobId: '22222222-2222-4222-8222-222222222222' } });
        mockAxiosGet.mockResolvedValueOnce({
            data: {
                status: 'completed',
                progress: 'IGSNs assessment completed.',
                assessedResources: 2,
            },
        });

        render(<AssessmentPage {...makeProps()} />);

        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'Check IGSNs' }));
        });

        expect(mockAxiosPost).toHaveBeenCalledWith('/assessment/check-igsns');

        await act(async () => {
            await vi.runAllTimersAsync();
        });

        expect(mockAxiosGet).toHaveBeenCalledWith('/assessment/check/igsn/22222222-2222-4222-8222-222222222222/status');
        expect(mockToast.success).toHaveBeenCalledWith('IGSNs assessment completed.');
    });

    it('keeps polling while the assessment is still running and uses LoadingButton state', async () => {
        mockAxiosPost.mockResolvedValueOnce({ data: { jobId: '11111111-1111-4111-8111-111111111111' } });
        mockAxiosGet
            .mockResolvedValueOnce({
                data: {
                    status: 'running',
                    progress: 'Assessing resources 1 of 2...',
                },
            })
            .mockResolvedValueOnce({
                data: {
                    status: 'completed',
                    progress: 'Resources assessment completed.',
                    assessedResources: 2,
                },
            });

        render(<AssessmentPage {...makeProps()} />);

        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'Check Resources' }));
        });

        const loadingButtons = screen.getAllByRole('button', { name: 'Checking...' });

        expect(loadingButtons.length).toBeGreaterThanOrEqual(2);
        expect(loadingButtons.every((button) => button.getAttribute('aria-busy') === 'true')).toBe(true);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(3000);
        });

        expect(screen.getByText('Assessing resources 1 of 2...')).toBeInTheDocument();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(3000);
        });

        expect(mockToast.success).toHaveBeenCalledWith('Resources assessment completed.');
    });

    it('clears active polling timers when the page unmounts', async () => {
        const clearTimeoutSpy = vi.spyOn(global, 'clearTimeout');

        mockAxiosPost.mockResolvedValueOnce({ data: { jobId: '11111111-1111-4111-8111-111111111111' } });

        const view = render(<AssessmentPage {...makeProps()} />);

        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'Check Resources' }));
        });

        act(() => {
            view.unmount();
        });

        expect(clearTimeoutSpy).toHaveBeenCalled();
    });

    it('shows the backend failure message when polling returns a failed status', async () => {
        mockAxiosPost.mockResolvedValueOnce({ data: { jobId: '11111111-1111-4111-8111-111111111111' } });
        mockAxiosGet.mockResolvedValueOnce({
            data: {
                status: 'failed',
                progress: 'Resources assessment failed.',
                error: 'F-UJI is not configured.',
            },
        });

        render(<AssessmentPage {...makeProps()} />);

        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'Check Resources' }));
        });

        await act(async () => {
            await vi.runAllTimersAsync();
        });

        expect(mockToast.error).toHaveBeenCalledWith('FAIR assessment service is not configured.');
        expect(mockRouterReload).not.toHaveBeenCalled();
    });

    it('stops polling cleanly and shows the backend message when the job is no longer found', async () => {
        mockAxiosPost.mockResolvedValueOnce({ data: { jobId: '11111111-1111-4111-8111-111111111111' } });
        mockAxiosGet.mockRejectedValueOnce(
            createAxiosError(404, {
                status: 'unknown',
                progress: 'Job not found.',
            }),
        );

        render(<AssessmentPage {...makeProps()} />);

        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'Check Resources' }));
        });

        await act(async () => {
            await vi.runAllTimersAsync();
        });

        expect(mockAxiosGet).toHaveBeenCalledWith('/assessment/check/resource/11111111-1111-4111-8111-111111111111/status');
        expect(mockToast.warning).toHaveBeenCalledWith('Job not found.');
        expect(mockToast.error).not.toHaveBeenCalled();
        expect(mockRouterReload).not.toHaveBeenCalled();
    });

    it('uses the server error payload for non-404 polling failures', async () => {
        mockAxiosPost.mockResolvedValueOnce({ data: { jobId: '11111111-1111-4111-8111-111111111111' } });
        mockAxiosGet.mockRejectedValueOnce(
            createAxiosError(500, {
                error: 'Status service unavailable.',
            }),
        );

        render(<AssessmentPage {...makeProps()} />);

        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'Check Resources' }));
        });

        await act(async () => {
            await vi.runAllTimersAsync();
        });

        expect(mockToast.error).toHaveBeenCalledWith('Status service unavailable.');
    });

    it('falls back to the generic polling error toast for unexpected errors', async () => {
        mockAxiosPost.mockResolvedValueOnce({ data: { jobId: '11111111-1111-4111-8111-111111111111' } });
        mockAxiosGet.mockRejectedValueOnce(new Error('Network down'));

        render(<AssessmentPage {...makeProps()} />);

        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'Check Resources' }));
        });

        await act(async () => {
            await vi.runAllTimersAsync();
        });

        expect(mockToast.error).toHaveBeenCalledWith('Failed to check Resources assessment status.');
    });

    it.each([
        [409, { error: 'Resource assessment is already running.' }, 'warning', 'Resource assessment is already running.'],
        [503, { error: 'F-UJI is not configured.' }, 'error', 'FAIR assessment service is not configured.'],
    ])('handles resource check start error status %i', async (status, data, toastMethod, expectedMessage) => {
        mockAxiosPost.mockRejectedValueOnce(createAxiosError(status, data));

        render(<AssessmentPage {...makeProps()} />);

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Check Resources' }));
        });

        expect(mockToast[toastMethod as 'warning' | 'error']).toHaveBeenCalledWith(expectedMessage);
    });

    it('falls back to the generic resource start error toast', async () => {
        mockAxiosPost.mockRejectedValueOnce(new Error('Connection refused'));

        render(<AssessmentPage {...makeProps()} />);

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Check Resources' }));
        });

        expect(mockToast.error).toHaveBeenCalledWith('Failed to start Resources assessment.');
    });

    it('uses the standardized unavailable message when a resource start 503 has no error payload', async () => {
        mockAxiosPost.mockRejectedValueOnce(createAxiosError(503, {}));

        render(<AssessmentPage {...makeProps()} />);

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Check Resources' }));
        });

        expect(mockToast.error).toHaveBeenCalledWith('The FAIR assessment service is currently unavailable. Please try again shortly.');
    });

    it('starts all assessments, warns for partial errors, and polls the started scope', async () => {
        mockAxiosPost.mockResolvedValueOnce({
            data: {
                resourceJobId: '11111111-1111-4111-8111-111111111111',
                igsnError: 'IGSN assessment is already running.',
            },
        });
        mockAxiosGet.mockResolvedValueOnce({
            data: {
                status: 'completed',
                progress: 'Resources assessment completed.',
                assessedResources: 3,
            },
        });

        render(<AssessmentPage {...makeProps()} />);

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Check all' }));
        });

        expect(mockAxiosPost).toHaveBeenCalledWith('/assessment/check-all');

        await act(async () => {
            await vi.runAllTimersAsync();
        });

        expect(mockToast.warning).toHaveBeenCalledWith('IGSN assessment is already running.');
        expect(mockToast.success).toHaveBeenCalledWith('Resources assessment completed.');
    });

    it.each([
        [409, { error: 'All assessment jobs are already running.' }, 'warning', 'All assessment jobs are already running.'],
        [503, { error: 'F-UJI is not configured.' }, 'error', 'FAIR assessment service is not configured.'],
    ])('handles check-all start error status %i', async (status, data, toastMethod, expectedMessage) => {
        mockAxiosPost.mockRejectedValueOnce(createAxiosError(status, data));

        render(<AssessmentPage {...makeProps()} />);

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Check all' }));
        });

        expect(mockToast[toastMethod as 'warning' | 'error']).toHaveBeenCalledWith(expectedMessage);
    });

    it('falls back to the generic check-all error toast', async () => {
        mockAxiosPost.mockRejectedValueOnce(new Error('Connection refused'));

        render(<AssessmentPage {...makeProps()} />);

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Check all' }));
        });

        expect(mockToast.error).toHaveBeenCalledWith('Failed to start the assessment jobs.');
    });

    it('uses the standardized unavailable message when a check-all 503 has no error payload', async () => {
        mockAxiosPost.mockRejectedValueOnce(createAxiosError(503, {}));

        render(<AssessmentPage {...makeProps()} />);

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Check all' }));
        });

        expect(mockToast.error).toHaveBeenCalledWith('The FAIR assessment service is currently unavailable. Please try again shortly.');
    });

    it('disables all check buttons without exposing the assessment provider when the service is not configured', () => {
        render(<AssessmentPage {...makeProps({ fujiConfigured: false, fujiHealthy: false, fujiStatusMessage: 'F-UJI is not configured.' })} />);

        expect(screen.getByRole('button', { name: 'Check all' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Check Resources' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Check IGSNs' })).toBeDisabled();
        expect(screen.getByText('The FAIR assessment service is not configured for this environment.')).toBeInTheDocument();
        expect(document.body).not.toHaveTextContent('F-UJI');
    });

    it('keeps all check buttons enabled and shows a provider-neutral health message when the service is unhealthy', () => {
        render(
            <AssessmentPage {...makeProps({ fujiHealthy: false, fujiStatusMessage: 'F-UJI is currently unavailable. Please try again shortly.' })} />,
        );

        expect(screen.getByRole('button', { name: 'Check all' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Check Resources' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Check IGSNs' })).toBeEnabled();
        expect(screen.getByText('FAIR assessment service is currently unavailable. Please try again shortly.')).toBeInTheDocument();
        expect(document.body).not.toHaveTextContent('F-UJI');
    });

    it('still allows starting a check when the service is unhealthy and sanitizes the server-side 503 response', async () => {
        mockAxiosPost.mockRejectedValueOnce(
            createAxiosError(503, {
                error: 'F-UJI is currently unavailable. Please try again shortly.',
            }),
        );

        render(
            <AssessmentPage
                {...makeProps({
                    fujiHealthy: false,
                    fujiStatusMessage: 'F-UJI is currently unavailable. Please try again shortly.',
                })}
            />,
        );

        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Check Resources' }));
        });

        expect(mockAxiosPost).toHaveBeenCalledWith('/assessment/check-resources');
        expect(mockToast.error).toHaveBeenCalledWith('FAIR assessment service is currently unavailable. Please try again shortly.');
    });
});
