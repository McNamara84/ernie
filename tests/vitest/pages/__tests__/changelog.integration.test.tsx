import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Changelog from '@/pages/changelog';

vi.mock('@/layouts/changelog-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: React.ReactNode }) => {
        if (title) document.title = title;
        return <>{children}</>;
    },
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
        // Accessing the values is enough to satisfy eslint while discarding motion-only props.
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
        div: ({ children, ...props }: MotionDivProps & { children?: React.ReactNode }) => {
            const rest = sanitizeDivMotionProps(props);
            return <div {...rest}>{children}</div>;
        },
        button: ({ children, ...props }: MotionButtonProps & { children?: React.ReactNode }) => {
            const rest = sanitizeButtonMotionProps(props);
            return <button {...rest}>{children}</button>;
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

describe('Changelog integration', () => {
    beforeEach(() => {
        document.title = '';
        global.fetch = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve([]) }) as unknown as typeof fetch;
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

    it('sets the document title', async () => {
        render(<Changelog />);

        await screen.findByRole('list', { name: /changelog timeline/i });

        expect(document.title).toBe('Changelog');
    });
});
