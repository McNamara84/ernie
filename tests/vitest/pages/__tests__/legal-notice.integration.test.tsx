import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import LegalNotice from '@/pages/legal-notice';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/layouts/public-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: React.ReactNode }) => {
        if (title) document.title = title;
        return <>{children}</>;
    },
}));

describe('LegalNotice integration', () => {
    beforeEach(() => {
        document.title = '';
    });

    it('sets the document title', () => {
        render(<LegalNotice />);
        expect(document.title).toBe('Legal Notice');
    });
});

