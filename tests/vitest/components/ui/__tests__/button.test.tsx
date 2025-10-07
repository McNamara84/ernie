import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Button } from '@/components/ui/button';

const BUTTON_TEXT = 'Click me';

describe('Button', () => {
    it('renders with default styles', () => {
        render(<Button>{BUTTON_TEXT}</Button>);
        const btn = screen.getByRole('button', { name: BUTTON_TEXT });
        expect(btn).toHaveClass('bg-primary');
        expect(btn).toHaveClass('h-9');
    });

    it('applies variant and size classes', () => {
        render(
            <Button variant="destructive" size="sm">
                {BUTTON_TEXT}
            </Button>,
        );
        const btn = screen.getByRole('button', { name: BUTTON_TEXT });
        expect(btn).toHaveClass('bg-destructive');
        expect(btn).toHaveClass('h-8');
    });

    it('supports rendering as child element', () => {
        render(
            <Button asChild>
                <a href="#test">{BUTTON_TEXT}</a>
            </Button>,
        );
        const link = screen.getByRole('link', { name: BUTTON_TEXT });
        expect(link).toHaveAttribute('href', '#test');
        expect(link).toHaveClass('inline-flex');
    });
});

