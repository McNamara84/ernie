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

vi.mock('framer-motion', () => ({
    AnimatePresence: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    motion: {
        div: ({ children, ...props }: React.HTMLAttributes<HTMLDivElement>) => <div {...props}>{children}</div>,
        button: ({ children, ...props }: React.HTMLAttributes<HTMLButtonElement>) => (
            <button {...props}>{children}</button>
        ),
    },
}));

describe('Changelog integration', () => {
    beforeEach(() => {
        document.title = '';
        global.fetch = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve([]) }) as unknown as typeof fetch;
        // @ts-expect-error jsdom stub
        window.scrollTo = vi.fn();
    });

    it('sets the document title', () => {
        render(<Changelog />);
        expect(document.title).toBe('Changelog');
    });
});
