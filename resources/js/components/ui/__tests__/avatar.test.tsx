import '@testing-library/jest-dom/vitest';
import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Avatar, AvatarFallback } from '../avatar';

describe('Avatar', () => {
    it('applies custom class to root', () => {
        const { container } = render(
            <Avatar className="custom">
                <AvatarFallback>AB</AvatarFallback>
            </Avatar>,
        );
        const root = container.querySelector('[data-slot="avatar"]');
        expect(root).toHaveClass('custom');
    });

    it('renders fallback content', () => {
        const { container } = render(
            <Avatar>
                <AvatarFallback>AB</AvatarFallback>
            </Avatar>,
        );
        expect(container.textContent).toContain('AB');
    });
});

