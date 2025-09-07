import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import Heading from '../heading';
import { describe, expect, it } from 'vitest';

describe('Heading', () => {
    it('renders title and description', () => {
        render(<Heading title="Profile" description="Manage account" />);
        expect(screen.getByText('Profile')).toBeInTheDocument();
        expect(screen.getByText('Manage account')).toBeInTheDocument();
    });

    it('omits description when not provided', () => {
        const { container } = render(<Heading title="Profile" />);
        expect(screen.queryByText('Manage account')).toBeNull();
        expect(container).toContainHTML('Profile');
    });
});
