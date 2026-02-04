import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { FormError } from '@/components/input-error';

describe('FormError', () => {
    it('renders the message when provided', () => {
        render(<FormError message="Invalid" />);
        const message = screen.getByText('Invalid');
        expect(message).toBeInTheDocument();
        // Uses shadcn/ui FormMessage styling (matches text-[0.8rem] from @/components/ui/form)
        expect(message).toHaveClass('text-[0.8rem]', 'font-medium', 'text-destructive');
    });

    it('returns null when no message', () => {
        const { container } = render(<FormError />);
        expect(container).toBeEmptyDOMElement();
    });
});

// Legacy InputError alias test (deprecated)
describe('InputError (deprecated)', () => {
    it('renders same output as FormError', async () => {
        const { default: InputError } = await import('@/components/input-error');
        const { container } = render(<InputError message="Test error" />);
        const message = container.querySelector('p');
        expect(message).toBeInTheDocument();
        expect(message).toHaveClass('text-[0.8rem]', 'font-medium', 'text-destructive');
    });
});
