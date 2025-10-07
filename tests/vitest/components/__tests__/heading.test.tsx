import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Heading from '@/components/heading';

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
