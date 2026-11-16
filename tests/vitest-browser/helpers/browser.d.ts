import type { Locator } from 'vitest/browser';

import type { ApiResponse } from './commands';

declare module 'vitest/browser' {
    interface BrowserCommands {
        mockApi: (responses: ApiResponse[]) => Promise<void>;
        assertApiHandled: () => Promise<void>;
        dragBy: (locator: Locator, deltaX: number, deltaY: number) => Promise<void>;
    }
}
