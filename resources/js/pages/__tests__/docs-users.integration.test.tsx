import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import DocsUsers from '../docs-users';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: React.ReactNode }) => {
        if (title) document.title = title;
        return <>{children}</>;
    },
}));

describe('DocsUsers integration', () => {
    beforeEach(() => {
        document.title = '';
    });

    it('sets the document title', () => {
        render(<DocsUsers />);
        expect(document.title).toBe('User Documentation');
    });
});

