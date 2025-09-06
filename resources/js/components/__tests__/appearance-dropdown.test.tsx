import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AppearanceToggleDropdown from '../appearance-dropdown';

const updateAppearance = vi.fn();
const appearanceState = { appearance: 'system' as 'light' | 'dark' | 'system', updateAppearance };

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => appearanceState,
}));

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    DropdownMenuTrigger: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    DropdownMenuContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
    DropdownMenuItem: ({ children, onClick }: { children: React.ReactNode; onClick?: () => void }) => (
        <button onClick={onClick}>{children}</button>
    ),
}));

describe('AppearanceToggleDropdown', () => {
    beforeEach(() => {
        updateAppearance.mockClear();
    });

    it.each([
        ['light', 'lucide-sun'],
        ['dark', 'lucide-moon'],
        ['system', 'lucide-monitor'],
    ])('renders correct icon for %s appearance', (mode, expectedClass) => {
        appearanceState.appearance = mode as any;
        render(<AppearanceToggleDropdown />);
        const toggle = screen.getByRole('button', { name: /toggle theme/i });
        const icon = toggle.querySelector('svg');
        expect(icon).toHaveClass(expectedClass);
    });

    it('calls updateAppearance when selecting options', async () => {
        appearanceState.appearance = 'system';
        render(<AppearanceToggleDropdown />);
        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: /light/i }));
        await user.click(screen.getByRole('button', { name: /dark/i }));
        await user.click(screen.getByRole('button', { name: /system/i }));
        expect(updateAppearance).toHaveBeenNthCalledWith(1, 'light');
        expect(updateAppearance).toHaveBeenNthCalledWith(2, 'dark');
        expect(updateAppearance).toHaveBeenNthCalledWith(3, 'system');
    });
});
