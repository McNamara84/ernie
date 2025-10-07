import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect,it } from 'vitest';

import HeadingSmall from '@/components/heading-small';

describe('HeadingSmall', () => {
    it('renders title and description', () => {
        render(<HeadingSmall title="Small Title" description="Small Description" />);
        expect(screen.getByText('Small Title')).toBeInTheDocument();
        expect(screen.getByText('Small Description')).toBeInTheDocument();
    });

    it('renders without description', () => {
        const { container } = render(<HeadingSmall title="Only Small Title" />);
        expect(screen.getByText('Only Small Title')).toBeInTheDocument();
        expect(container.querySelector('p')).toBeNull();
    });
});
