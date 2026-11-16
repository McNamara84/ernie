import { test as base } from 'vitest';
import { userEvent } from 'vitest/browser';

export { render } from './render';
export { expect, vi } from 'vitest';
export { commands, page } from 'vitest/browser';

export const test = base.extend<{ user: ReturnType<typeof userEvent.setup> }>({
    // Vitest reads fixture dependencies from this destructuring pattern.
    // oxlint-disable-next-line no-empty-pattern
    user: async ({}, provide) => {
        const user = userEvent.setup();
        try {
            await provide(user);
        } finally {
            await user.cleanup();
        }
    },
});
