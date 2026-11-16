import { vi } from 'vitest';

import { readStatus } from './fixtures/status';
import { commands, expect, test } from './helpers/test';

vi.mock('./fixtures/status', () => ({ readStatus: () => 'mocked' }));

test('keeps native module mocking working while intercepting an application request', async () => {
    await commands.mockApi([{ path: '/api/browser-test/status', body: { status: 'ready' } }]);
    expect(await (await fetch('/api/browser-test/status')).json()).toEqual({ status: 'ready' });
    expect(readStatus()).toBe('mocked');
});

test('resets network responses for the next test without losing the module mock', async () => {
    expect(await (await fetch('/api/v1/ror-affiliations')).json()).toEqual([]);
    expect(readStatus()).toBe('mocked');
});
