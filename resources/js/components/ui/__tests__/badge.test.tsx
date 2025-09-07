import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { Badge } from '../badge';

describe('Badge', () => {
    it('renders default variant', () => {
        render(<Badge>Default</Badge>);
        const badge = screen.getByText('Default');
        expect(badge).toHaveAttribute('data-slot', 'badge');
        expect(badge).toHaveClass('bg-primary', { exact: false });
    });

    it('supports variant and asChild', () => {
        render(
            <Badge variant="secondary" asChild>
                <a href="#">Link</a>
            </Badge>,
        );
        const badgeLink = screen.getByRole('link');
        expect(badgeLink).toHaveAttribute('data-slot', 'badge');
        expect(badgeLink).toHaveClass('bg-secondary', { exact: false });
    });
});

