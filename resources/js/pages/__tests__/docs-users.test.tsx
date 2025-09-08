import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import DocsUsers from '../docs-users';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

describe('User docs page', () => {
    it('renders curator instructions', () => {
        render(<DocsUsers />);
        expect(screen.getByText('Add new curators')).toBeInTheDocument();
        expect(
            screen.getByText(/ehrmann@gfz.de/)
        ).toBeInTheDocument();
    });
});
