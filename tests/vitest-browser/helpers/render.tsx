import { QueryClientProvider } from '@tanstack/react-query';
import { createTestQueryClient } from '@tests/vitest/helpers/query-client';
import type { ReactNode } from 'react';
import { render as browserRender } from 'vitest-browser-react/pure';

import { TooltipProvider } from '@/components/ui/tooltip';

const clients = new Set<ReturnType<typeof createTestQueryClient>>();

export function clearQueryClients() {
    for (const client of clients) client.clear();
    clients.clear();
}

export function render(ui: ReactNode) {
    const client = createTestQueryClient();
    clients.add(client);
    return browserRender(
        <QueryClientProvider client={client}>
            <TooltipProvider>{ui}</TooltipProvider>
        </QueryClientProvider>,
    );
}
