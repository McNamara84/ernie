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
            json: () =>
                Promise.resolve([
                    {
                        version: '0.1.0',
                        date: '2024-12-01',
                        features: [
                            {
                                title: 'Resource Information form group',
                                description: 'Add structured resource information section.',
                            },
                            {
                                title: 'License and Rights',
                                description: 'Include license and rights details for resources.',
                            },
                        ],
                    },
                    {
                        version: '1.0.0',
                        date: '2025-01-15',
                        features: [
                            {
                                title: 'Interaktive Timeline',
                                description:
                                    'Introduced interactive timeline for changelog entries.',
                            },
                        ],
                        fixes: [
                            {
                                title: 'Fixed accessibility issues',
                                description:
                                    'Resolved color contrast and focus styles.',
                            },
                        ],
                        improvements: [
                            {
                                title: 'Performance enhancements',
                                description:
                                    'Optimized rendering of changelog entries.',
                            },
                        ],
                    },
                ]),
        }) as unknown as typeof fetch;
        // framer-motion calls scrollTo in tests
        // @ts-expect-error missing on jsdom
        window.scrollTo = vi.fn();
    });

    it('renders releases on a timeline and toggles grouped details', async () => {
        const user = userEvent.setup();
        render(<Changelog />);
        const list = await screen.findByRole('list', { name: /changelog timeline/i });
        expect(list).toBeInTheDocument();
        const firstButton = await screen.findByRole('button', {
            name: /version 0.1.0/i,
        });
        const secondButton = await screen.findByRole('button', {
            name: /version 1.0.0/i,
        });
        expect(firstButton).toBeInTheDocument();
        expect(secondButton).toBeInTheDocument();
        await user.click(firstButton);
        expect(
            await screen.findByText(/Resource Information form group/i),
        ).toBeInTheDocument();
        expect(screen.getByText('License and Rights')).toBeInTheDocument();
        await user.click(secondButton);
        expect(await screen.findByText(/Interaktive Timeline/i)).toBeInTheDocument();
        expect(screen.getByText(/Fixed accessibility issues/i)).toBeInTheDocument();
        expect(screen.getByText(/Performance enhancements/i)).toBeInTheDocument();
    });
});
