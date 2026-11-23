import '@testing-library/jest-dom/vitest';

import { router } from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import LegalNotice from '@/pages/legal-notice';

const mockUsePage = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', async () => {
    const { createInertiaMock } = await import('@tests/vitest/test-helpers/inertia-mocks');
    return createInertiaMock({ usePageFn: mockUsePage });
});
vi.mock('@/hooks/use-nprogress', () => ({ useNProgress: vi.fn() }));

describe('LegalNotice integration', () => {
    beforeEach(() => {
        document.title = '';
        mockUsePage.mockReturnValue({ url: '/legal-notice', props: { auth: { user: null } } });
    });
    afterEach(() => vi.restoreAllMocks());

    it('sets the document title', () => {
        render(<LegalNotice />);
        expect(document.title).toBe('Legal Notice');
    });

    it.each([
        ['a guest', null],
        ['a signed-in user', { id: 1 }],
    ])('returns %s to the previous history entry', async (_role, account) => {
        const user = userEvent.setup();
        mockUsePage.mockReturnValue({ url: '/legal-notice', props: { auth: { user: account } } });
        vi.spyOn(window.history, 'length', 'get').mockReturnValue(2);
        const back = vi.spyOn(window.history, 'back').mockImplementation(() => {});
        render(<LegalNotice />);

        expect(screen.queryByRole('link', { name: /^(Dashboard|Log in)$/ })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Back' }));
        expect(back).toHaveBeenCalledOnce();
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('opens the homepage when the tab has no previous page', async () => {
        const user = userEvent.setup();
        vi.spyOn(window.history, 'length', 'get').mockReturnValue(1);
        render(<LegalNotice />);

        await user.click(screen.getByRole('button', { name: 'Back' }));
        expect(router.visit).toHaveBeenCalledWith('/', { replace: true });
    });
});

