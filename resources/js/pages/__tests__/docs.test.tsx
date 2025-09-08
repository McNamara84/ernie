import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import Docs from '../docs';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

describe('Docs page', () => {
    it('renders collapsible triggers', () => {
        render(<Docs />);
        expect(screen.getByText('For Users')).toBeInTheDocument();
        expect(screen.getByText('For Admins')).toBeInTheDocument();
    });

    it('toggles admin collapsible content', () => {
        const { container } = render(<Docs />);
        const trigger = screen.getByText('For Admins');
        const content = container.querySelectorAll('[data-slot="collapsible-content"]')[1] as HTMLElement;
        expect(content).toHaveAttribute('data-state', 'closed');
        fireEvent.click(trigger);
        expect(content).toHaveAttribute('data-state', 'open');
        expect(screen.getByText(/php artisan add-user/i)).toBeInTheDocument();
    });

    it('links to user documentation', () => {
        render(<Docs />);
        const link = screen.getByText('Go to the user documentation');
        expect(link).toHaveAttribute('href', '/docs/users');
    });
});

