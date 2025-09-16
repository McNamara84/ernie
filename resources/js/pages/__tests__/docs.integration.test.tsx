import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import Docs from '../docs';
import userEvent from '@testing-library/user-event';
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

describe('Docs integration', () => {
    beforeEach(() => {
        document.title = '';
    });

    it('sets the document title', () => {
        render(<Docs />);
        expect(document.title).toBe('Documentation');
    });

    it('allows readers to expand the admin guidance section', async () => {
        render(<Docs />);

        const adminTrigger = screen.getByRole('button', { name: /for admins/i });
        const adminContent = screen.getByTestId('admin-collapsible-content');

        expect(adminTrigger).toHaveAttribute('aria-expanded', 'false');
        expect(adminContent).toHaveAttribute('data-state', 'closed');

        await userEvent.click(adminTrigger);

        expect(adminTrigger).toHaveAttribute('aria-expanded', 'true');
        expect(adminContent).toHaveAttribute('data-state', 'open');
        expect(
            screen.getByText(/php artisan add-user <name> <email> <password>/i),
        ).toBeVisible();
    });
});

