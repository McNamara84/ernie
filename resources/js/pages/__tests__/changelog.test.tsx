import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach, afterEach, Mock } from 'vitest';
import Changelog from '../changelog';
import { __testing as basePathTesting } from '@/lib/base-path';

vi.mock('@/layouts/public-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('framer-motion', () => ({
    AnimatePresence: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
    motion: {
        button: ({ children, ...props }: React.HTMLAttributes<HTMLButtonElement>) => (
            <button {...props}>{children}</button>
        ),
        div: ({ children, ...props }: React.HTMLAttributes<HTMLDivElement>) => <div {...props}>{children}</div>,
    },
}));

describe('Changelog', () => {
    beforeEach(() => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
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
        // framer-motion calls scrollTo in tests
        // @ts-expect-error missing on jsdom
        window.scrollTo = vi.fn();
    });

    afterEach(() => {
        document.head.innerHTML = '';
        basePathTesting.resetBasePathCache();
    });

    it('renders releases on a timeline and toggles grouped details', async () => {
        const user = userEvent.setup();
        render(<Changelog />);
        const list = await screen.findByRole('list', { name: /changelog timeline/i });
        expect(list).toBeInTheDocument();
        const firstButton = await screen.findByRole('button', { name: /version 0.1.0/i });
        const lastButton = await screen.findByRole('button', { name: /version 0.2.0/i });
        expect(firstButton).toBeInTheDocument();
        expect(lastButton).toBeInTheDocument();
        await user.click(firstButton);
        expect(await screen.findByText(/Resource Information form group/i)).toBeInTheDocument();
        expect(screen.getByText('License and Rights')).toBeInTheDocument();
        await user.click(lastButton);
        expect(await screen.findByText(/Minor improvements/i)).toBeInTheDocument();
        expect(screen.getByText(/UI enhancements/i)).toBeInTheDocument();
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

    it('fetches releases using the configured base path', async () => {
        basePathTesting.setMetaBasePath('/ernie');
        const fetchSpy = global.fetch as unknown as Mock;

        render(<Changelog />);

        await vi.waitFor(() => {
            expect(fetchSpy).toHaveBeenCalledWith('/ernie/api/changelog');
        });
    });
});
