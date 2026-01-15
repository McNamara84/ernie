import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach,beforeEach, describe, expect, it, vi } from 'vitest';

import type { ThesaurusData } from '@/components/settings/thesaurus-card';
import { ThesaurusCard } from '@/components/settings/thesaurus-card';

// Mock Inertia's usePage hook
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: {
                user: {
                    id: 1,
                    name: 'Admin User',
                    role: 'admin',
                },
            },
        },
    }),
}));

const mockThesauri: ThesaurusData[] = [
    {
        type: 'science_keywords',
        displayName: 'Science Keywords',
        isActive: true,
        isElmoActive: true,
        exists: true,
        conceptCount: 2500,
        lastUpdated: '2024-01-15T10:30:00Z',
    },
    {
        type: 'platforms',
        displayName: 'Platforms',
        isActive: true,
        isElmoActive: false,
        exists: true,
        conceptCount: 800,
        lastUpdated: '2024-01-10T08:00:00Z',
    },
    {
        type: 'instruments',
        displayName: 'Instruments',
        isActive: false,
        isElmoActive: true,
        exists: false,
        conceptCount: 0,
        lastUpdated: null,
    },
];

describe('ThesaurusCard', () => {
    const mockOnActiveChange = vi.fn();
    const mockOnElmoActiveChange = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        // Mock fetch for API calls
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({}),
        });
        // Mock document.cookie for CSRF token
        Object.defineProperty(document, 'cookie', {
            writable: true,
            value: 'XSRF-TOKEN=test-token',
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('Rendering', () => {
        it('should render all thesaurus entries', () => {
            render(
                <ThesaurusCard
                    thesauri={mockThesauri}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            expect(screen.getByText('Science Keywords')).toBeInTheDocument();
            expect(screen.getByText('Platforms')).toBeInTheDocument();
            expect(screen.getByText('Instruments')).toBeInTheDocument();
        });

        it('should display concept count for existing thesauri', () => {
            render(
                <ThesaurusCard
                    thesauri={mockThesauri}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            // There should be 2 thesauri with concept counts (Science Keywords and Platforms)
            const conceptElements = screen.getAllByText(/concepts/);
            expect(conceptElements).toHaveLength(2);
        });

        it('should show "Not yet downloaded" badge for non-existing thesauri', () => {
            render(
                <ThesaurusCard
                    thesauri={mockThesauri}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            expect(screen.getByText('Not yet downloaded')).toBeInTheDocument();
        });

        it('should render ERNIE and ELMO checkboxes for each thesaurus', () => {
            render(
                <ThesaurusCard
                    thesauri={mockThesauri}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            // Should have 3 ERNIE labels and 3 ELMO labels (one per thesaurus)
            expect(screen.getAllByText('ERNIE')).toHaveLength(3);
            expect(screen.getAllByText('ELMO')).toHaveLength(3);
        });

        it('should render 6 checkboxes (2 per thesaurus)', () => {
            render(
                <ThesaurusCard
                    thesauri={mockThesauri}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            expect(screen.getAllByRole('checkbox')).toHaveLength(6);
        });

        it('should have correct test ids for each thesaurus row', () => {
            render(
                <ThesaurusCard
                    thesauri={mockThesauri}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            expect(screen.getByTestId('thesaurus-row-science_keywords')).toBeInTheDocument();
            expect(screen.getByTestId('thesaurus-row-platforms')).toBeInTheDocument();
            expect(screen.getByTestId('thesaurus-row-instruments')).toBeInTheDocument();
        });
    });

    describe('Interactions', () => {
        it('should call onActiveChange when ERNIE checkbox is toggled', async () => {
            const user = userEvent.setup();
            render(
                <ThesaurusCard
                    thesauri={mockThesauri}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            // Find and click the first ERNIE checkbox (Science Keywords)
            const ernieCheckboxes = screen.getAllByRole('checkbox');
            await user.click(ernieCheckboxes[0]); // First checkbox is ERNIE for science_keywords

            expect(mockOnActiveChange).toHaveBeenCalledWith('science_keywords', false);
        });

        it('should call onElmoActiveChange when ELMO checkbox is toggled', async () => {
            const user = userEvent.setup();
            render(
                <ThesaurusCard
                    thesauri={mockThesauri}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            // Find and click the second checkbox (ELMO for science_keywords)
            const checkboxes = screen.getAllByRole('checkbox');
            await user.click(checkboxes[1]); // Second checkbox is ELMO for science_keywords

            expect(mockOnElmoActiveChange).toHaveBeenCalledWith('science_keywords', false);
        });

        it('should render check for updates button for each thesaurus', () => {
            render(
                <ThesaurusCard
                    thesauri={mockThesauri}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            const checkButtons = screen.getAllByRole('button', { name: /check for updates/i });
            expect(checkButtons).toHaveLength(3);
        });
    });

    describe('Update check flow', () => {
        it('should show loading state when checking for updates', async () => {
            const user = userEvent.setup();

            // Mock a slow response
            global.fetch = vi.fn().mockImplementation(
                () =>
                    new Promise((resolve) =>
                        setTimeout(
                            () =>
                                resolve({
                                    ok: true,
                                    json: () =>
                                        Promise.resolve({
                                            localCount: 2500,
                                            remoteCount: 2550,
                                            updateAvailable: true,
                                            lastUpdated: '2024-01-15T10:30:00Z',
                                        }),
                                }),
                            100,
                        ),
                    ),
            );

            render(
                <ThesaurusCard
                    thesauri={mockThesauri}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            const checkButtons = screen.getAllByRole('button', { name: /check for updates/i });
            await user.click(checkButtons[0]);

            // Should show loading state
            expect(screen.getByText('Checking...')).toBeInTheDocument();
        });

        it('should show error message on check failure', async () => {
            const user = userEvent.setup();

            global.fetch = vi.fn().mockResolvedValue({
                ok: false,
                json: () => Promise.resolve({ error: 'Connection failed' }),
            });

            render(
                <ThesaurusCard
                    thesauri={mockThesauri}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            const checkButtons = screen.getAllByRole('button', { name: /check for updates/i });
            await user.click(checkButtons[0]);

            // Wait for the error
            expect(await screen.findByText(/Connection failed/i)).toBeInTheDocument();
        });
    });

    describe('Empty state', () => {
        it('should render empty card when no thesauri provided', () => {
            render(
                <ThesaurusCard
                    thesauri={[]}
                    onActiveChange={mockOnActiveChange}
                    onElmoActiveChange={mockOnElmoActiveChange}
                />,
            );

            expect(screen.getByTestId('thesaurus-card')).toBeInTheDocument();
            expect(screen.queryByTestId('thesaurus-row-science_keywords')).not.toBeInTheDocument();
        });
    });
});
