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
    it('exposes the jsdom storage implementations through the global object', () => {
        expect(globalThis.localStorage).toBe(window.localStorage);
        expect(globalThis.sessionStorage).toBe(window.sessionStorage);

        globalThis.localStorage.setItem('vitest-storage-test', 'local');
        globalThis.sessionStorage.setItem('vitest-storage-test', 'session');

        expect(window.localStorage.getItem('vitest-storage-test')).toBe('local');
        expect(window.sessionStorage.getItem('vitest-storage-test')).toBe('session');
    });

    it('suppresses expected navigation and CSS limitations but forwards other errors', () => {
        const stderrWrite = vi.spyOn(process.stderr, 'write').mockImplementation(() => true);

        try {
            virtualConsole.emit(
                'jsdomError',
                Object.assign(new Error('Not implemented: navigation to another Document'), { type: 'not-implemented' }),
            );
            virtualConsole.emit('jsdomError', Object.assign(new Error('Could not parse CSS stylesheet'), { type: 'css-parsing' }));
            virtualConsole.emit('jsdomError', Object.assign(new Error('Unexpected jsdom failure'), { type: 'resource-loading' }));

            const stderrOutput = stderrWrite.mock.calls.map(([chunk]) => String(chunk)).join('');

            expect(stderrOutput).not.toContain('Not implemented: navigation to another Document');
            expect(stderrOutput).not.toContain('Could not parse CSS stylesheet');
            expect(stderrOutput).toContain('Unexpected jsdom failure');
        } finally {
            stderrWrite.mockRestore();
        }
    });

    it('forwards the underlying cause of unhandled exceptions', () => {
        const stderrWrite = vi.spyOn(process.stderr, 'write').mockImplementation(() => true);
        const cause = new Error('Underlying asynchronous failure');

        try {
            virtualConsole.emit(
                'jsdomError',
                Object.assign(new Error('Synthetic jsdom wrapper error'), {
                    cause,
                    type: 'unhandled-exception',
                }),
            );

            const stderrOutput = stderrWrite.mock.calls.map(([chunk]) => String(chunk)).join('');

            expect(stderrOutput).toContain(cause.message);
        } finally {
            stderrWrite.mockRestore();
        }
    });
});
