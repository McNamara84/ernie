import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { SidebarProvider, SidebarTrigger, useSidebar } from '@/components/ui/sidebar';

vi.mock('@/hooks/use-mobile', () => ({
  useIsMobile: () => false,
}));

describe('Sidebar', () => {
  it('throws error when used outside provider', () => {
    const Component = () => {
      useSidebar();
      return null;
    };
    expect(() => render(<Component />)).toThrow('useSidebar must be used within a SidebarProvider.');
  });

  it('toggles state using SidebarTrigger', async () => {
    const user = userEvent.setup();
    function StateReader() {
      const { state } = useSidebar();
      return <span data-testid="state">{state}</span>;
    }
    render(
      <SidebarProvider>
        <SidebarTrigger />
        <StateReader />
      </SidebarProvider>
    );
    const button = screen.getByRole('button', { name: /toggle sidebar/i });
    const state = screen.getByTestId('state');
    expect(state).toHaveTextContent('expanded');
    await user.click(button);
    expect(state).toHaveTextContent('collapsed');
  });
});
