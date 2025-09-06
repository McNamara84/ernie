import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import Heading from '../heading';

describe('Heading', () => {
    it('renders title and description', () => {
        render(<Heading title="Test Title" description="Test Description" />);
        expect(screen.getByText('Test Title')).toBeInTheDocument();
        expect(screen.getByText('Test Description')).toBeInTheDocument();
    });

    it('renders without description', () => {
        const { container } = render(<Heading title="Only Title" />);
        expect(screen.getByText('Only Title')).toBeInTheDocument();
        expect(container.querySelector('p')).toBeNull();
    });
});
