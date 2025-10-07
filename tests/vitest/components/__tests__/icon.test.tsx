import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import type { LucideProps } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { Icon } from '@/components/icon';

const DummyIcon = (props: LucideProps) => <svg data-testid="dummy-icon" {...props} />;

describe('Icon', () => {
    it('renders the provided icon with base classes', () => {
        render(<Icon iconNode={DummyIcon} />);
        const icon = screen.getByTestId('dummy-icon');
        expect(icon).toHaveClass('h-4');
        expect(icon).toHaveClass('w-4');
    });

    it('merges additional class names', () => {
        render(<Icon iconNode={DummyIcon} className="text-blue-500" />);
        const icon = screen.getByTestId('dummy-icon');
        expect(icon).toHaveClass('text-blue-500');
    });
});

