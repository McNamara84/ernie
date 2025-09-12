import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import Changelog from '../changelog';

vi.mock('@/layouts/public-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

describe('Changelog', () => {
    beforeEach(() => {
        global.fetch = vi.fn().mockResolvedValue({
            json: () => Promise.resolve([
                {
                    date: '2025-01-01',
                    type: 'feature',
                    title: 'Interaktive Timeline',
                    description: 'Introduced interactive timeline for changelog entries.',
                },
            ]),
        }) as unknown as typeof fetch;
        // framer-motion calls scrollTo in tests
        // @ts-expect-error missing on jsdom
        window.scrollTo = vi.fn();
    });

    it('loads entries and toggles details', async () => {
        const user = userEvent.setup();
        render(<Changelog />);
        expect(await screen.findByText(/Interaktive Timeline/i)).toBeInTheDocument();
        expect(screen.queryByText(/interactive timeline for changelog/i)).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /Interaktive Timeline/i }));
        expect(await screen.findByText(/interactive timeline for changelog/i)).toBeInTheDocument();
    });
});
