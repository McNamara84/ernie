import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import InputError from '../input-error';
import { describe, expect, it } from 'vitest';

describe('InputError', () => {
    it('renders the message when provided', () => {
        render(<InputError message="Invalid" />);
        const message = screen.getByText('Invalid');
        expect(message).toBeInTheDocument();
        expect(message).toHaveClass('text-sm');
    });

    it('returns null when no message', () => {
        const { container } = render(<InputError />);
        expect(container).toBeEmptyDOMElement();
    });
});
