import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import InputError from '../input-error';
import { describe, expect, it } from 'vitest';

describe('InputError', () => {
    it('renders the message', () => {
        render(<InputError message="Something went wrong" />);
        expect(screen.getByText(/something went wrong/i)).toBeInTheDocument();
    });

    it('renders nothing when message is undefined', () => {
        const { container } = render(<InputError message={undefined} />);
        expect(container).toBeEmptyDOMElement();
    });
});

