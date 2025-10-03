import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AppearanceTabs from '@/components/appearance-tabs';

const updateAppearance = vi.fn();

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({ appearance: 'system', updateAppearance }),
}));

describe('AppearanceTabs', () => {
    it('renders tabs and updates appearance on click', () => {
        render(<AppearanceTabs />);
        const darkButton = screen.getByRole('button', { name: /dark/i });
        const lightButton = screen.getByRole('button', { name: /light/i });
        const systemButton = screen.getByRole('button', { name: /system/i });

        expect(lightButton).toBeInTheDocument();
        expect(darkButton).toBeInTheDocument();
        expect(systemButton).toHaveClass('bg-white');

        fireEvent.click(darkButton);
        expect(updateAppearance).toHaveBeenCalledWith('dark');
    });
});
