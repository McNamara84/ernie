import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type React from 'react';
import { beforeEach, describe, expect, it, Mock, vi } from 'vitest';

import Changelog from '@/pages/changelog';

vi.mock('@/layouts/public-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/changelog-timeline-nav', () => ({
    ChangelogTimelineNav: () => null,
}));

vi.mock('@/components/ui/badge', () => ({
    Badge: ({ children, ...props }: { children?: React.ReactNode }) => <span {...props}>{children}</span>,
}));

type MotionButtonProps = React.ButtonHTMLAttributes<HTMLButtonElement> & {
    whileHover?: unknown;
    whileTap?: unknown;
};

type MotionDivProps = React.HTMLAttributes<HTMLDivElement> & {
    initial?: unknown;
    animate?: unknown;
    exit?: unknown;
    transition?: unknown;
};

type MotionLiProps = React.HTMLAttributes<HTMLLIElement> & {
    initial?: unknown;
    animate?: unknown;
    transition?: unknown;
};

const consumeMotionOnlyProps = (...values: unknown[]) => {
    values.forEach(() => {
        // Accessing via forEach keeps eslint satisfied without mutating output.
    });
};

const sanitizeButtonMotionProps = ({ whileHover, whileTap, ...rest }: MotionButtonProps) => {
    consumeMotionOnlyProps(whileHover, whileTap);
    return rest;
};

const sanitizeDivMotionProps = ({ initial, animate, exit, transition, ...rest }: MotionDivProps) => {
    consumeMotionOnlyProps(initial, animate, exit, transition);
    return rest;
};

const sanitizeLiMotionProps = ({ initial, animate, transition, ...rest }: MotionLiProps) => {
    consumeMotionOnlyProps(initial, animate, transition);
    return rest;
};

vi.mock('framer-motion', () => ({
    AnimatePresence: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    motion: {
        button: ({ children, ...props }: MotionButtonProps & { children?: React.ReactNode }) => {
            const rest = sanitizeButtonMotionProps(props);
            return <button {...rest}>{children}</button>;
        },
        div: ({ children, ...props }: MotionDivProps & { children?: React.ReactNode }) => {
            const rest = sanitizeDivMotionProps(props);
            return <div {...rest}>{children}</div>;
        },
        li: ({ children, ref, ...props }: MotionLiProps & { children?: React.ReactNode; ref?: React.Ref<HTMLLIElement> }) => {
            const rest = sanitizeLiMotionProps(props);
            return <li ref={ref} {...rest}>{children}</li>;
        },
    },
}));

vi.mock('lucide-react', () => ({
    Bug: () => <svg data-testid="bug-icon" />,
    Sparkles: () => <svg data-testid="sparkles-icon" />,
    TrendingUp: () => <svg data-testid="trending-up-icon" />,
}));

describe('Changelog', () => {
    beforeEach(() => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve([
                    {
                        version: '0.1.0',
                        date: '2024-12-01',
                        features: [
                            {
                                title: 'Resources workspace',
                                description: 'Browse curated resources with metadata badges and quick actions.',
                            },
                            {
                                title: 'Dashboard overview',
                                description: 'Surface key statistics like the total resource count.',
                            },
                        ],
                    },
                    {
                        version: '0.1.1',
                        date: '2024-12-10',
                        fixes: [
                            {
                                title: 'Hotfix',
                                description: 'Quick patch release.',
                            },
                        ],
                    },
                    {
                        version: '0.2.0',
                        date: '2024-12-20',
                        improvements: [
                            {
                                title: 'Minor improvements',
                                description: 'UI enhancements.',
                            },
                        ],
                    },
                ]),
        }) as unknown as typeof fetch;
        
        // Mock scrollTo and scrollIntoView
        window.scrollTo = vi.fn();
        Element.prototype.scrollIntoView = vi.fn();
        
        // Mock IntersectionObserver
        global.IntersectionObserver = vi.fn().mockImplementation(() => ({
            observe: vi.fn(),
            unobserve: vi.fn(),
            disconnect: vi.fn(),
            root: null,
            rootMargin: '',
            thresholds: [],
            takeRecords: vi.fn(),
        })) as unknown as typeof IntersectionObserver;
    });

    it('renders releases on a timeline, expands the latest by default, and toggles grouped details', async () => {
        const user = userEvent.setup();
        render(<Changelog />);
        
        const list = await screen.findByRole('list', { name: /changelog timeline/i });
        expect(list).toBeInTheDocument();
        const firstButton = await screen.findByRole('button', { name: /version 0.1.0/i });
        const lastButton = await screen.findByRole('button', { name: /version 0.2.0/i });
        expect(firstButton).toBeInTheDocument();
        expect(lastButton).toBeInTheDocument();
        expect(firstButton).toHaveAttribute('aria-expanded', 'true');
        expect(await screen.findByText(/Resources workspace/i)).toBeInTheDocument();
        expect(screen.getByText(/Dashboard overview/i)).toBeInTheDocument();
        await user.click(lastButton);
        expect(lastButton).toHaveAttribute('aria-expanded', 'true');
        expect(firstButton).toHaveAttribute('aria-expanded', 'false');
        expect(await screen.findByText(/Minor improvements/i)).toBeInTheDocument();
        expect(screen.getByText(/UI enhancements/i)).toBeInTheDocument();
        await user.click(lastButton);
        await vi.waitFor(() => {
            expect(screen.queryByText(/Minor improvements/i)).not.toBeInTheDocument();
        });
    });

    it('colors anchors based on version changes', async () => {
        render(<Changelog />);
        const anchors = await screen.findAllByTestId('version-anchor');
        expect(anchors[0]).toHaveClass('ring-green-500');
        expect(anchors[1]).toHaveClass('ring-red-500');
        expect(anchors[2]).toHaveClass('ring-blue-500');
    });

    it('shows an error message when fetch fails', async () => {
        (global.fetch as unknown as Mock).mockRejectedValueOnce(new Error('fail'));
        render(<Changelog />);
        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent(/unable to load changelog/i);
    });

    it('fetches releases from /api/changelog', async () => {
        const fetchSpy = global.fetch as unknown as Mock;

        render(<Changelog />);

        await screen.findByRole('list', { name: /changelog timeline/i });

        await vi.waitFor(() => {
            expect(fetchSpy).toHaveBeenCalledWith('/api/changelog');
        });
    });
});
