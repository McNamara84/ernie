import '@testing-library/jest-dom/vitest';

import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Changelog from '@/pages/changelog';

vi.mock('@/layouts/public-layout', () => ({
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

vi.mock('framer-motion', () => ({
    AnimatePresence: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    motion: {
        div: ({ children, ...props }: React.HTMLAttributes<HTMLDivElement>) => <div {...props}>{children}</div>,
        button: ({ children, ...props }: React.HTMLAttributes<HTMLButtonElement>) => (
            <button {...props}>{children}</button>
        ),
        li: ({ children, ref, ...props }: React.HTMLAttributes<HTMLLIElement> & { ref?: React.Ref<HTMLLIElement> }) => (
            <li ref={ref} {...props}>{children}</li>
        ),
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

    it('sets the document title', () => {
        render(<Changelog />);
        expect(document.title).toBe('Changelog');
    });
});
