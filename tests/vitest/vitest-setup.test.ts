import { describe, expect, it, vi } from 'vitest';

type JsdomVirtualConsole = {
    emit: (eventName: 'jsdomError', error: Error & { type?: string }) => void;
};

const virtualConsole = (
    globalThis as typeof globalThis & {
        jsdom: { virtualConsole: JsdomVirtualConsole };
    }
).jsdom.virtualConsole;

describe('Vitest jsdom error handling', () => {
    it('suppresses expected navigation and CSS limitations but forwards other errors', () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);

        try {
            virtualConsole.emit(
                'jsdomError',
                Object.assign(new Error('Not implemented: navigation to another Document'), { type: 'not-implemented' }),
            );
            virtualConsole.emit('jsdomError', Object.assign(new Error('Could not parse CSS stylesheet'), { type: 'css-parsing' }));
            virtualConsole.emit('jsdomError', Object.assign(new Error('Unexpected jsdom failure'), { type: 'resource-loading' }));

            expect(consoleError).toHaveBeenCalledOnce();
            expect(consoleError).toHaveBeenCalledWith('Unexpected jsdom failure');
        } finally {
            consoleError.mockRestore();
        }
    });
});
