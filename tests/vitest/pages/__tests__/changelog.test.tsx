import '@testing-library/jest-dom/vitest';

import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, Mock, vi } from 'vitest';

import Changelog from '@/pages/changelog';

type TimelineNavProps = {
    releases: Array<{ version: string; date: string }>;
    activeIndex: number | null;
    onNavigate: (index: number) => void;
};

const pageMocks = vi.hoisted(() => ({
    timelineNavProps: null as TimelineNavProps | null,
    skipListItemRefAssignment: false,
}));

vi.mock('@/layouts/changelog-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/changelog-timeline-nav', () => ({
    ChangelogTimelineNav: (props: TimelineNavProps) => {
        pageMocks.timelineNavProps = props;
        return null;
    },
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

            if (pageMocks.skipListItemRefAssignment) {
                return <li {...rest}>{children}</li>;
            }

            return <li ref={ref} {...rest}>{children}</li>;
        },
    },
}));

vi.mock('lucide-react', () => ({
    Bug: () => <svg data-testid="bug-icon" />,
    Sparkles: () => <svg data-testid="sparkles-icon" />,
    TrendingUp: () => <svg data-testid="trending-up-icon" />,
}));

const setReducedMotion = (matches: boolean) => {
    Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: vi.fn().mockImplementation((query: string) => ({
            matches: query === '(prefers-reduced-motion: reduce)' ? matches : false,
            media: query,
            onchange: null,
            addListener: vi.fn(),
            removeListener: vi.fn(),
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            dispatchEvent: vi.fn(),
        })),
    });
};

describe('Changelog', () => {
    beforeEach(() => {
        pageMocks.timelineNavProps = null;
        pageMocks.skipListItemRefAssignment = false;

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
        setReducedMotion(false);

        // Reset hash between tests (handleNavigate uses pushState which persists)
        window.history.replaceState(null, '', window.location.pathname);
        
        // Mock IntersectionObserver without automatic callbacks so tests can
        // drive visibility changes explicitly through the test helper.
        global.IntersectionObserver = vi.fn().mockImplementation(function (this: IntersectionObserver, callback: IntersectionObserverCallback) {
            consumeMotionOnlyProps(callback);
            return {
                observe: vi.fn(),
                unobserve: vi.fn(),
                disconnect: vi.fn(),
                root: null,
                rootMargin: '',
                thresholds: [],
                takeRecords: vi.fn(() => []),
            };
        }) as unknown as typeof IntersectionObserver;
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

    it('shows an error message when the changelog request returns a non-ok response', async () => {
        (global.fetch as unknown as Mock).mockResolvedValueOnce({
            ok: false,
            json: vi.fn(),
        });

        const user = userEvent.setup();

        render(<Changelog />);

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent(/unable to load changelog/i);

        await user.click(screen.getByRole('button', { name: /reload page/i }));

        expect(screen.getByRole('alert')).toBeInTheDocument();
    });

    it('fetches releases from /api/changelog', async () => {
        const fetchSpy = global.fetch as unknown as Mock;

        render(<Changelog />);

        await screen.findByRole('list', { name: /changelog timeline/i });

        await vi.waitFor(() => {
            expect(fetchSpy).toHaveBeenCalledWith('/api/changelog');
        });
    });

    it('opens the hash-targeted release on initial load', async () => {
        window.history.replaceState(null, '', '/changelog#v0.1.1');

        render(<Changelog />);

        const firstButton = await screen.findByRole('button', { name: /version 0.1.0/i });
        const targetButton = screen.getByRole('button', { name: /version 0.1.1/i });

        expect(targetButton).toHaveAttribute('aria-expanded', 'true');
        expect(firstButton).toHaveAttribute('aria-expanded', 'false');
    });

    it('falls back to the first release for a missing version hash', async () => {
        window.history.replaceState(null, '', '/changelog#v9.9.9');

        render(<Changelog />);

        const firstButton = await screen.findByRole('button', { name: /version 0.1.0/i });
        const secondButton = screen.getByRole('button', { name: /version 0.1.1/i });

        expect(firstButton).toHaveAttribute('aria-expanded', 'true');
        expect(secondButton).toHaveAttribute('aria-expanded', 'false');
    });

    it('ignores non-version hashes and keeps the default release open', async () => {
        window.history.replaceState(null, '', '/changelog#notes');

        render(<Changelog />);

        const firstButton = await screen.findByRole('button', { name: /version 0.1.0/i });
        const secondButton = screen.getByRole('button', { name: /version 0.1.1/i });

        expect(firstButton).toHaveAttribute('aria-expanded', 'true');
        expect(secondButton).toHaveAttribute('aria-expanded', 'false');
    });

    it('opens the hash-targeted release when the hash changes', async () => {
        render(<Changelog />);

        const firstButton = await screen.findByRole('button', { name: /version 0.1.0/i });
        const targetButton = screen.getByRole('button', { name: /version 0.2.0/i });

        await act(async () => {
            window.history.pushState(null, '', '/changelog#v0.2.0');
            window.dispatchEvent(new HashChangeEvent('hashchange'));
        });

        expect(targetButton).toHaveAttribute('aria-expanded', 'true');
        expect(firstButton).toHaveAttribute('aria-expanded', 'false');
    });

    it('keeps the open release stable when scroll-based highlighting changes', async () => {
        Object.defineProperty(window, 'innerHeight', { value: 1000, writable: true });

        render(<Changelog />);

        const firstButton = await screen.findByRole('button', { name: /version 0.1.0/i });
        const secondButton = screen.getByRole('button', { name: /version 0.1.1/i });
        const items = screen.getAllByRole('listitem');
        const createRect = (top: number, bottom: number) => ({
            x: 0,
            y: top,
            width: 400,
            height: bottom - top,
            top,
            right: 400,
            bottom,
            left: 0,
            toJSON: () => ({}),
        });

        Object.defineProperty(items[0], 'getBoundingClientRect', {
            configurable: true,
            value: () => createRect(0, 220),
        });
        Object.defineProperty(items[1], 'getBoundingClientRect', {
            configurable: true,
            value: () => createRect(320, 720),
        });
        Object.defineProperty(items[2], 'getBoundingClientRect', {
            configurable: true,
            value: () => createRect(760, 960),
        });

        await act(async () => {
            window.__testHelper_updateActiveRelease?.();
        });

        expect(firstButton).toHaveAttribute('aria-expanded', 'true');
        expect(secondButton).toHaveAttribute('aria-expanded', 'false');
    });

    it('returns early when a pending scroll target has no attached element ref', async () => {
        pageMocks.skipListItemRefAssignment = true;
        window.history.replaceState(null, '', '/changelog#v0.1.1');

        render(<Changelog />);

        const targetButton = await screen.findByRole('button', { name: /version 0.1.1/i });

        expect(targetButton).toHaveAttribute('aria-expanded', 'true');
        expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();
    });

    it('ignores navigation requests for an out-of-range release index', async () => {
        render(<Changelog />);

        const firstButton = await screen.findByRole('button', { name: /version 0.1.0/i });

        await act(async () => {
            pageMocks.timelineNavProps?.onNavigate(99);
        });

        expect(firstButton).toHaveAttribute('aria-expanded', 'true');
    });

    it('uses auto scrolling and reduced-motion variants when reduced motion is enabled', async () => {
        setReducedMotion(true);
        window.history.replaceState(null, '', '/changelog#v0.1.1');

        render(<Changelog />);

        const targetButton = await screen.findByRole('button', { name: /version 0.1.1/i });

        expect(targetButton).toHaveAttribute('aria-expanded', 'true');
        expect(Element.prototype.scrollIntoView).toHaveBeenCalledWith({ behavior: 'auto', block: 'center' });
    });

    it('uses auto scrolling when opening a release by click in reduced-motion mode', async () => {
        setReducedMotion(true);

        const user = userEvent.setup();

        render(<Changelog />);

        const secondButton = await screen.findByRole('button', { name: /version 0.1.1/i });

        (Element.prototype.scrollIntoView as Mock).mockClear();

        await user.click(secondButton);

        expect(Element.prototype.scrollIntoView).toHaveBeenCalledWith({ behavior: 'auto', block: 'center' });
    });

    describe('keyboard navigation', () => {
        beforeEach(() => {
            vi.useFakeTimers({ shouldAdvanceTime: false });
        });
        afterEach(() => {
            vi.useRealTimers();
        });

        it('moves to next release with ArrowDown', async () => {
            render(<Changelog />);
            await vi.waitFor(() => {
                expect(screen.getByRole('button', { name: /version 0.1.0/i })).toHaveAttribute('aria-expanded', 'true');
            });

            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
            });

            expect(screen.getByRole('button', { name: /version 0.1.1/i })).toHaveAttribute('aria-expanded', 'true');
        });

        it('moves to previous release with ArrowUp', async () => {
            render(<Changelog />);
            await vi.waitFor(() => {
                expect(screen.getByRole('button', { name: /version 0.1.0/i })).toHaveAttribute('aria-expanded', 'true');
            });

            // Navigate to second release first
            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
            });
            expect(screen.getByRole('button', { name: /version 0.1.1/i })).toHaveAttribute('aria-expanded', 'true');

            // Navigate back up
            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
            });
            expect(screen.getByRole('button', { name: /version 0.1.0/i })).toHaveAttribute('aria-expanded', 'true');
        });

        it('moves down with j key', async () => {
            render(<Changelog />);
            await vi.waitFor(() => {
                expect(screen.getByRole('button', { name: /version 0.1.0/i })).toHaveAttribute('aria-expanded', 'true');
            });

            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'j', bubbles: true }));
            });

            expect(screen.getByRole('button', { name: /version 0.1.1/i })).toHaveAttribute('aria-expanded', 'true');
        });

        it('goes to first release with Home', async () => {
            render(<Changelog />);
            await vi.waitFor(() => {
                expect(screen.getByRole('button', { name: /version 0.1.0/i })).toBeInTheDocument();
            });

            // Navigate down twice to reach the last release
            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
            });
            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
            });

            // Then Home
            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
            });

            expect(screen.getByRole('button', { name: /version 0.1.0/i })).toHaveAttribute('aria-expanded', 'true');
        });

        it('goes to last release with End', async () => {
            render(<Changelog />);
            await vi.waitFor(() => {
                expect(screen.getByRole('button', { name: /version 0.1.0/i })).toBeInTheDocument();
            });

            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true }));
            });

            expect(screen.getByRole('button', { name: /version 0.2.0/i })).toHaveAttribute('aria-expanded', 'true');
        });

        it('does not move past the last release with ArrowDown', async () => {
            render(<Changelog />);

            await vi.waitFor(() => {
                expect(screen.getByRole('button', { name: /version 0.1.0/i })).toHaveAttribute('aria-expanded', 'true');
            });

            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
                await vi.runAllTimersAsync();
            });

            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
                await vi.runAllTimersAsync();
            });

            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
                await vi.runAllTimersAsync();
            });

            expect(screen.getByRole('button', { name: /version 0.2.0/i })).toHaveAttribute('aria-expanded', 'true');
        });

        it('does not move above the first release with ArrowUp', async () => {
            render(<Changelog />);
            await vi.waitFor(() => {
                expect(screen.getByRole('button', { name: /version 0.1.0/i })).toHaveAttribute('aria-expanded', 'true');
            });

            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
            });

            expect(screen.getByRole('button', { name: /version 0.1.0/i })).toHaveAttribute('aria-expanded', 'true');
        });

        it('toggles the current release with Enter and Space', async () => {
            render(<Changelog />);

            await vi.waitFor(() => {
                expect(screen.getByRole('button', { name: /version 0.1.0/i })).toHaveAttribute('aria-expanded', 'true');
            });

            const firstButton = screen.getByRole('button', { name: /version 0.1.0/i });
            expect(firstButton).toHaveAttribute('aria-expanded', 'true');

            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
                await vi.runAllTimersAsync();
            });

            expect(firstButton).toHaveAttribute('aria-expanded', 'false');

            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));
                await vi.runAllTimersAsync();
            });

            expect(firstButton).toHaveAttribute('aria-expanded', 'true');
        });

        it('ignores keyboard navigation when there are no releases', async () => {
            (global.fetch as unknown as Mock).mockResolvedValueOnce({
                ok: true,
                json: () => Promise.resolve([]),
            });

            render(<Changelog />);
            expect(screen.getByRole('heading', { name: /changelog/i })).toBeInTheDocument();

            await vi.waitFor(() => {
                expect(screen.queryByRole('button', { name: /version/i })).not.toBeInTheDocument();
            });

            await act(async () => {
                window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
                await vi.runAllTimersAsync();
            });

            expect(screen.queryByRole('button', { name: /version/i })).not.toBeInTheDocument();
        });
    });

    describe('isNewRelease badge', () => {
        it('shows New badge for releases within 14 days', async () => {
            const today = new Date().toISOString().split('T')[0];
            (global.fetch as unknown as Mock).mockResolvedValueOnce({
                ok: true,
                json: () =>
                    Promise.resolve([
                        {
                            version: '2.0.0',
                            date: today,
                            features: [{ title: 'Fresh Feature', description: 'Just added.' }],
                        },
                    ]),
            });

            render(<Changelog />);
            await screen.findByRole('button', { name: /version 2.0.0/i });

            expect(screen.getByText('New')).toBeInTheDocument();
        });

        it('does not show New badge for releases older than 14 days', async () => {
            (global.fetch as unknown as Mock).mockResolvedValueOnce({
                ok: true,
                json: () =>
                    Promise.resolve([
                        {
                            version: '1.0.0',
                            date: '2020-01-01',
                            features: [{ title: 'Old Feature', description: 'Long ago.' }],
                        },
                    ]),
            });

            render(<Changelog />);
            await screen.findByRole('button', { name: /version 1.0.0/i });

            expect(screen.queryByText('New')).not.toBeInTheDocument();
        });

        it('does not show New badge for releases at index > 2 even if recent', async () => {
            const today = new Date().toISOString().split('T')[0];
            (global.fetch as unknown as Mock).mockResolvedValueOnce({
                ok: true,
                json: () =>
                    Promise.resolve([
                        { version: '4.0.0', date: today, features: [{ title: 'F1', description: 'D1' }] },
                        { version: '3.0.0', date: today, features: [{ title: 'F2', description: 'D2' }] },
                        { version: '2.0.0', date: today, features: [{ title: 'F3', description: 'D3' }] },
                        { version: '1.0.0', date: today, features: [{ title: 'F4', description: 'D4' }] },
                    ]),
            });

            render(<Changelog />);
            await screen.findByRole('button', { name: /version 1.0.0/i });

            // First 3 releases (index 0,1,2) get the badge; 4th (index 3) does not
            const badges = screen.getAllByText('New');
            expect(badges).toHaveLength(3);
        });
    });

    describe('section icons', () => {
        it('renders sparkles icon for features section', async () => {
            const user = userEvent.setup();
            render(<Changelog />);
            // First release should be expanded by default; click to ensure it's open
            const firstButton = await screen.findByRole('button', { name: /version 0.1.0/i });
            if (firstButton.getAttribute('aria-expanded') !== 'true') {
                await user.click(firstButton);
            }

            expect(await screen.findByTestId('sparkles-icon')).toBeInTheDocument();
        });

        it('renders bug icon for fixes section', async () => {
            const user = userEvent.setup();
            render(<Changelog />);
            const fixesButton = await screen.findByRole('button', { name: /version 0.1.1/i });
            await user.click(fixesButton);

            expect(await screen.findByTestId('bug-icon')).toBeInTheDocument();
        });

        it('renders trending-up icon for improvements section', async () => {
            const user = userEvent.setup();
            render(<Changelog />);
            const improvementsButton = await screen.findByRole('button', { name: /version 0.2.0/i });
            // Ensure it's collapsed first, then open it
            if (improvementsButton.getAttribute('aria-expanded') === 'true') {
                await user.click(improvementsButton); // collapse
            }
            await user.click(improvementsButton); // expand

            expect(await screen.findByTestId('trending-up-icon')).toBeInTheDocument();
        });
    });
});
