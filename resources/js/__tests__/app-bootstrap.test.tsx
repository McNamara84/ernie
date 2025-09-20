import '@testing-library/jest-dom/vitest';
import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => {
    const renderMock = vi.fn();

    return {
        renderMock,
        createInertiaAppMock: vi.fn(),
        createRootMock: vi.fn(() => ({ render: renderMock })),
        initializeThemeMock: vi.fn(),
        resolvePageComponentMock: vi.fn(() => 'resolved-component'),
    };
});

vi.mock('@inertiajs/react', () => ({
    createInertiaApp: (options: unknown) => {
        mocks.createInertiaAppMock(options);
        return Promise.resolve();
    },
}));

vi.mock('laravel-vite-plugin/inertia-helpers', () => ({
    resolvePageComponent: (path: string, pages: Record<string, unknown>) =>
        mocks.resolvePageComponentMock(path, pages),
}));

vi.mock('react-dom/client', () => ({
    createRoot: (element: Element) => mocks.createRootMock(element),
}));

vi.mock('../hooks/use-appearance', () => ({
    initializeTheme: () => mocks.initializeThemeMock(),
}));

describe('client bootstrap', () => {
    beforeEach(() => {
        vi.resetModules();
        mocks.createInertiaAppMock.mockClear();
        mocks.createRootMock.mockClear();
        mocks.renderMock.mockClear();
        mocks.initializeThemeMock.mockClear();
        mocks.resolvePageComponentMock.mockClear();
        mocks.createRootMock.mockImplementation(() => ({ render: mocks.renderMock }));
        mocks.resolvePageComponentMock.mockImplementation(() => 'resolved-component');
    });

    it('configures Inertia with title, resolve and setup handlers', async () => {
        await import('../app');

        expect(mocks.createInertiaAppMock).toHaveBeenCalledTimes(1);
        const options = mocks.createInertiaAppMock.mock.calls[0][0] as {
            title: (title?: string) => string;
            resolve: (name: string) => unknown;
            setup: <P>(args: { el: Element; App: React.ComponentType<P>; props: P }) => void;
            progress: { color: string };
        };

        expect(options.progress.color).toBe('#4B5563');
        const appName = options.title('');
        expect(appName).toBeTruthy();
        expect(options.title('Dashboard')).toBe(`Dashboard - ${appName}`);

        const resolved = options.resolve('Dashboard');
        expect(mocks.resolvePageComponentMock).toHaveBeenCalledWith('./pages/Dashboard.tsx', expect.any(Object));
        expect(resolved).toBe('resolved-component');

        const el = document.createElement('div');
        const props = { initialPage: { component: 'Dashboard' } };
        const AppComponent: React.ComponentType<typeof props> = (componentProps) => (
            <div {...componentProps} />
        );

        options.setup({ el, App: AppComponent, props });

        expect(mocks.createRootMock).toHaveBeenCalledWith(el);
        expect(mocks.renderMock).toHaveBeenCalledTimes(1);
        const renderedElement = mocks.renderMock.mock.calls[0][0] as React.ReactElement;
        expect(renderedElement.type).toBe(AppComponent);
        expect(renderedElement.props).toEqual(props);
    });

    it('initializes the persisted theme once the app is booted', async () => {
        await import('../app');

        expect(mocks.initializeThemeMock).toHaveBeenCalledTimes(1);
        expect(mocks.createInertiaAppMock.mock.invocationCallOrder[0]).toBeLessThan(
            mocks.initializeThemeMock.mock.invocationCallOrder[0],
        );
    });
});
