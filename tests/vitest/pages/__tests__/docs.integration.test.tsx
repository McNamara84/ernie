import '@testing-library/jest-dom/vitest';

import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import Docs from '@/pages/docs';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: React.ReactNode }) => {
        if (title) document.title = title;
        return <>{children}</>;
    },
}));

describe('Docs integration', () => {
    beforeEach(() => {
        document.title = '';
    });

    it('sets the document title', () => {
        render(<Docs userRole="curator" />);
        expect(document.title).toBe('Documentation');
    });
});

