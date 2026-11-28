import '../../resources/css/app.css';

import { defaultApiResponses } from '@tests/test-data/api-responses';
import { afterEach, beforeEach, vi } from 'vitest';
import { commands } from 'vitest/browser';
import { cleanup } from 'vitest-browser-react/pure';

import { clearQueryClients } from './helpers/render';

beforeEach(async () => {
    localStorage.clear();
    sessionStorage.clear();
    await commands.mockApi([...defaultApiResponses]);
});

afterEach(async () => {
    try {
        await cleanup();
    } finally {
        clearQueryClients();
        if (vi.isFakeTimers()) vi.clearAllTimers();
        vi.useRealTimers();
        vi.unstubAllGlobals();
        await commands.assertApiHandled();
    }
});
