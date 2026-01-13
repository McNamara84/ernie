import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ChangelogTimelineNav } from '@/components/changelog-timeline-nav';

const mocks = vi.hoisted(() => ({
    motionDiv: vi.fn(),
    motionButton: vi.fn(),
}));

// Mock framer-motion to avoid animation issues in tests
vi.mock('framer-motion', () => ({
    motion: {
        div: ({ children, ...props }: React.PropsWithChildren<object>) => {
            mocks.motionDiv(props);
            return <div {...props}>{children}</div>;
        },
        button: ({
            children,
            ...props
        }: React.PropsWithChildren<React.ButtonHTMLAttributes<HTMLButtonElement>>) => {
            mocks.motionButton(props);
            return <button {...props}>{children}</button>;
        },
        span: ({ children, ...props }: React.PropsWithChildren<object>) => <span {...props}>{children}</span>,
    },
}));

describe('ChangelogTimelineNav', () => {
    const mockReleases = [
        { version: '2.0.0', date: '2025-01-15' },
        { version: '1.1.0', date: '2025-01-01' },
        { version: '1.0.1', date: '2024-12-20' },
        { version: '1.0.0', date: '2024-12-01' },
    ];

    const mockOnNavigate = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        // Set desktop viewport
        Object.defineProperty(window, 'innerWidth', { value: 1024, writable: true });

        // Mock matchMedia for reduced motion
        Object.defineProperty(window, 'matchMedia', {
            writable: true,
            value: vi.fn().mockImplementation((query: string) => ({
                matches: false,
                media: query,
                onchange: null,
                addListener: vi.fn(),
                removeListener: vi.fn(),
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
                dispatchEvent: vi.fn(),
            })),
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('when releases array is empty', () => {
        it('renders nothing', () => {
            const { container } = render(
                <ChangelogTimelineNav releases={[]} activeIndex={null} onNavigate={mockOnNavigate} />,
            );

            expect(container.firstChild).toBeNull();
        });
    });

    describe('desktop view', () => {
        it('renders navigation buttons for each release', async () => {
            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            // Wait for component to determine it's desktop
            await waitFor(() => {
                // Should have 4 buttons (one for each release)
                const buttons = screen.getAllByRole('button');
                expect(buttons.length).toBe(4);
            });
        });

        it('renders with correct aria-label for each version button', async () => {
            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            await waitFor(() => {
                expect(screen.getByLabelText('Navigate to version 2.0.0')).toBeInTheDocument();
                expect(screen.getByLabelText('Navigate to version 1.1.0')).toBeInTheDocument();
                expect(screen.getByLabelText('Navigate to version 1.0.1')).toBeInTheDocument();
                expect(screen.getByLabelText('Navigate to version 1.0.0')).toBeInTheDocument();
            });
        });

        it('calls onNavigate when a version button is clicked', async () => {
            const user = userEvent.setup();

            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            await waitFor(async () => {
                const button = screen.getByLabelText('Navigate to version 1.1.0');
                await user.click(button);
            });

            expect(mockOnNavigate).toHaveBeenCalledWith(1);
        });

        it('marks the active version with aria-current', async () => {
            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={1} onNavigate={mockOnNavigate} />,
            );

            await waitFor(() => {
                const activeButton = screen.getByLabelText('Navigate to version 1.1.0');
                expect(activeButton).toHaveAttribute('aria-current', 'true');
            });
        });

        it('does not mark inactive versions with aria-current', async () => {
            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            await waitFor(() => {
                const inactiveButton = screen.getByLabelText('Navigate to version 1.1.0');
                expect(inactiveButton).not.toHaveAttribute('aria-current');
            });
        });
    });

    describe('mobile view', () => {
        beforeEach(() => {
            // Set mobile viewport
            Object.defineProperty(window, 'innerWidth', { value: 500, writable: true });
            // Trigger resize event
            window.dispatchEvent(new Event('resize'));
        });

        it('renders a toggle button with history icon', async () => {
            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            await waitFor(() => {
                const toggleButton = screen.getByLabelText('Toggle timeline navigation');
                expect(toggleButton).toBeInTheDocument();
            });
        });

        it('shows version list when toggle button is clicked', async () => {
            const user = userEvent.setup();

            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            await waitFor(async () => {
                const toggleButton = screen.getByLabelText('Toggle timeline navigation');
                await user.click(toggleButton);
            });

            // Version text should now be visible
            await waitFor(() => {
                expect(screen.getByText('v2.0.0')).toBeInTheDocument();
                expect(screen.getByText('v1.1.0')).toBeInTheDocument();
            });
        });

        it('calls onNavigate and closes dropdown when a version is selected', async () => {
            const user = userEvent.setup();

            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            // Open dropdown
            await waitFor(async () => {
                const toggleButton = screen.getByLabelText('Toggle timeline navigation');
                await user.click(toggleButton);
            });

            // Wait for dropdown to open
            await waitFor(() => {
                expect(screen.getByText('v1.0.1')).toBeInTheDocument();
            });

            // Click on a version
            const versionButton = screen.getByText('v1.0.1');
            await user.click(versionButton);

            expect(mockOnNavigate).toHaveBeenCalledWith(2);
        });
    });

    describe('version color coding', () => {
        it('assigns green color to the first release (latest)', async () => {
            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            await waitFor(() => {
                const buttons = screen.getAllByRole('button');
                // First button should have green background
                expect(buttons[0]).toHaveClass('bg-green-500');
            });
        });

        it('assigns green color to major version changes', async () => {
            // 2.0.0 -> 1.1.0 is a major version change when looking backward
            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            await waitFor(() => {
                const buttons = screen.getAllByRole('button');
                // Second button (1.1.0 compared to 2.0.0) - major change
                expect(buttons[1]).toHaveClass('bg-green-500');
            });
        });

        it('assigns blue color to minor version changes', async () => {
            // 1.1.0 -> 1.0.1 is a minor version change
            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            await waitFor(() => {
                const buttons = screen.getAllByRole('button');
                // Third button (1.0.1 compared to 1.1.0) - minor change
                expect(buttons[2]).toHaveClass('bg-blue-500');
            });
        });

        it('assigns red color to patch version changes', async () => {
            // 1.0.1 -> 1.0.0 is a patch version change
            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            await waitFor(() => {
                const buttons = screen.getAllByRole('button');
                // Fourth button (1.0.0 compared to 1.0.1) - patch change
                expect(buttons[3]).toHaveClass('bg-red-500');
            });
        });
    });

    describe('reduced motion preference', () => {
        it('respects prefers-reduced-motion setting', async () => {
            Object.defineProperty(window, 'matchMedia', {
                writable: true,
                value: vi.fn().mockImplementation((query: string) => ({
                    matches: query === '(prefers-reduced-motion: reduce)',
                    media: query,
                    onchange: null,
                    addListener: vi.fn(),
                    removeListener: vi.fn(),
                    addEventListener: vi.fn(),
                    removeEventListener: vi.fn(),
                    dispatchEvent: vi.fn(),
                })),
            });

            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            await waitFor(() => {
                // Component should render without errors with reduced motion
                expect(screen.getByLabelText('Navigate to version 2.0.0')).toBeInTheDocument();
            });
        });
    });

    describe('navigation structure', () => {
        it('renders a nav element with proper aria-label on desktop', async () => {
            render(
                <ChangelogTimelineNav releases={mockReleases} activeIndex={0} onNavigate={mockOnNavigate} />,
            );

            await waitFor(() => {
                const nav = screen.getByRole('navigation');
                expect(nav).toHaveAttribute('aria-label', 'Version timeline navigation');
            });
        });
    });
});
