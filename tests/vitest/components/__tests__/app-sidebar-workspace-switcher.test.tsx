import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const useSidebarMock = vi.hoisted(() => vi.fn());

vi.mock('@/components/ui/sidebar', () => ({
    SidebarGroup: ({ children, className }: { children?: React.ReactNode; className?: string }) => (
        <div data-testid="sidebar-group" className={className}>{children}</div>
    ),
    useSidebar: () => useSidebarMock(),
}));

import { AppSidebarWorkspaceSwitcher } from '@/components/app-sidebar-workspace-switcher';

describe('AppSidebarWorkspaceSwitcher', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        useSidebarMock.mockReturnValue({ isMobile: false, state: 'expanded' });
    });

    it('renders full workspace labels when the desktop sidebar is expanded', () => {
        render(<AppSidebarWorkspaceSwitcher value="curation" onValueChange={vi.fn()} />);

        expect(screen.getByRole('tab', { name: /curation workspace/i })).toHaveTextContent('Curation');
        expect(screen.getByRole('tab', { name: /administration workspace/i })).toHaveTextContent('Administration');
    });

    it('calls onValueChange when the user switches workspaces', async () => {
        const handleValueChange = vi.fn();
        const user = userEvent.setup();

        render(<AppSidebarWorkspaceSwitcher value="curation" onValueChange={handleValueChange} />);

        await user.click(screen.getByRole('tab', { name: /administration workspace/i }));

        expect(handleValueChange).toHaveBeenCalledWith('administration');
    });

    it('renders compact labels when the desktop sidebar is collapsed', () => {
        useSidebarMock.mockReturnValue({ isMobile: false, state: 'collapsed' });

        render(<AppSidebarWorkspaceSwitcher value="administration" onValueChange={vi.fn()} />);

        expect(screen.getByRole('tab', { name: /curation workspace/i })).toHaveTextContent('C');
        expect(screen.getByRole('tab', { name: /administration workspace/i })).toHaveTextContent('A');
    });

    it('keeps full labels on mobile even when the sidebar state is collapsed', () => {
        useSidebarMock.mockReturnValue({ isMobile: true, state: 'collapsed' });

        render(<AppSidebarWorkspaceSwitcher value="administration" onValueChange={vi.fn()} />);

        expect(screen.getByRole('tab', { name: /curation workspace/i })).toHaveTextContent('Curation');
        expect(screen.getByRole('tab', { name: /administration workspace/i })).toHaveTextContent('Administration');
    });
});