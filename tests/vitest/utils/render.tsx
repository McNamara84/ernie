/**
 * Custom render utility that wraps components with required providers.
 *
 * After the shadcn/ui v4.1 upgrade, Tooltip no longer bundles its own
 * TooltipProvider – it is expected at the layout level. This wrapper
 * supplies that context so every test behaves like the real app.
 *
 * Usage: import { render } from '@tests/vitest/utils/render';
 *         (all other exports from @testing-library/react are re-exported)
 */

import { render as rtlRender, type RenderOptions } from '@testing-library/react';
import type { ReactElement } from 'react';

import { TooltipProvider } from '@/components/ui/tooltip';

function AllProviders({ children }: { children: React.ReactNode }) {
    return <TooltipProvider>{children}</TooltipProvider>;
}

function render(ui: ReactElement, options?: Omit<RenderOptions, 'wrapper'>) {
    return rtlRender(ui, { wrapper: AllProviders, ...options });
}

// Re-export everything from testing-library, overriding only render
export * from '@testing-library/react';
export { render };
