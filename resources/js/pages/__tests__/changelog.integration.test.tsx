import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Changelog from '../changelog';

vi.mock('@/layouts/public-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: React.ReactNode }) => {
        if (title) document.title = title;
        return <>{children}</>;
    },
}));

describe('Changelog integration', () => {
    beforeEach(() => {
        document.title = '';
        global.fetch = vi.fn().mockResolvedValue({ json: () => Promise.resolve([]) }) as unknown as typeof fetch;
        // @ts-expect-error jsdom stub
        window.scrollTo = vi.fn();
    });

    it('sets the document title', () => {
        render(<Changelog />);
        expect(document.title).toBe('Changelog');
    });
});
