import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { Checkbox } from '@/components/ui/checkbox';

describe('Checkbox', () => {
    it('renders indicator when checked and accepts className', () => {
        const { container } = render(<Checkbox defaultChecked className="extra" />);
        const root = screen.getByRole('checkbox');
        expect(root).toHaveClass('extra');
        expect(root).toHaveAttribute('data-slot', 'checkbox');
        expect(root).toHaveAttribute('data-state', 'checked');
        const indicator = container.querySelector('[data-slot="checkbox-indicator"]');
        expect(indicator).toBeInTheDocument();
    });

    it('toggles checked state on click', async () => {
        const user = userEvent.setup();
        render(<Checkbox />);
        const root = screen.getByRole('checkbox');
        expect(root).not.toHaveAttribute('data-state', 'checked');
        await user.click(root);
        expect(root).toHaveAttribute('data-state', 'checked');
    });
});
