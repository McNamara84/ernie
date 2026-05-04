import '@testing-library/jest-dom/vitest';

import { fireEvent } from '@testing-library/react';
import { render, screen } from '@tests/vitest/utils/render';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { AssessmentPageProps } from '@/types/assessment';

const { mockRouterReload } = vi.hoisted(() => ({
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
    router: { reload: mockRouterReload },
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

function makeProps(overrides: Partial<AssessmentPageProps> = {}): AssessmentPageProps {
    return {
        fujiConfigured: true,
        resourcesNeedingAttention: [
            {
                id: 1,
                doi: '10.5880/test.001',
                mainTitle: 'Lowest resource score',
                score: 11.25,
                assessedAt: '2026-05-04T09:00:00+00:00',
            },
        ],
        igsnsNeedingAttention: [
            {
                id: 2,
                doi: '10.5880/test.igsn.001',
                mainTitle: 'Lowest IGSN score',
                score: 18.5,
                assessedAt: '2026-05-04T10:00:00+00:00',
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
        expect(screen.getByText('10.5880/test.001')).toBeInTheDocument();
        expect(screen.getByText('Lowest resource score')).toBeInTheDocument();
        expect(screen.getByText('11.25%')).toBeInTheDocument();
        expect(screen.getByText('10.5880/test.igsn.001')).toBeInTheDocument();
        expect(screen.getByText('Lowest IGSN score')).toBeInTheDocument();
        expect(screen.getByText('18.50%')).toBeInTheDocument();
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

    it('stops polling cleanly and shows the backend message when the job is no longer found', async () => {
        mockAxiosPost.mockResolvedValueOnce({ data: { jobId: '11111111-1111-4111-8111-111111111111' } });
        mockAxiosGet.mockRejectedValueOnce(Object.assign(new Error('Not found'), {
            isAxiosError: true,
            response: {
                status: 404,
                data: {
                    status: 'unknown',
                    progress: 'Job not found.',
                },
            },
        }));

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

    it('disables all check buttons when F-UJI is not configured', () => {
        render(<AssessmentPage {...makeProps({ fujiConfigured: false })} />);

        expect(screen.getByRole('button', { name: 'Check all' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Check Resources' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Check IGSNs' })).toBeDisabled();
        expect(screen.getByText('F-UJI is not configured for this environment.')).toBeInTheDocument();
    });
});