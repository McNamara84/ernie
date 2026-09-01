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
        const cause = new Error('Unexpected asynchronous failure');

        try {
            virtualConsole.emit(
                'jsdomError',
                Object.assign(new Error('Uncaught [Error: Unexpected asynchronous failure]'), {
                    cause,
                    type: 'unhandled-exception',
                }),
            );

            const stderrOutput = stderrWrite.mock.calls.map(([chunk]) => String(chunk)).join('');
            const causeStack = cause.stack;

            expect(causeStack).toBeDefined();
            expect(stderrOutput).toContain(causeStack ?? cause.message);
        } finally {
            stderrWrite.mockRestore();
        }
    });
});
