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
    it('renders collapsible trigger', () => {
        render(<Docs />);
        expect(screen.getByText('For Admins')).toBeInTheDocument();
    });

    it('toggles collapsible content', () => {
        const { container } = render(<Docs />);
        const trigger = screen.getByText('For Admins');
        const content = container.querySelector('[data-slot="collapsible-content"]') as HTMLElement;
        expect(content).toHaveAttribute('data-state', 'closed');
        fireEvent.click(trigger);
        expect(content).toHaveAttribute('data-state', 'open');
        expect(screen.getByText(/php artisan add-user/i)).toBeInTheDocument();
    });
});

