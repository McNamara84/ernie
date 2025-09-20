import '@testing-library/jest-dom/vitest';
import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
    createServerMock: vi.fn(),
    createInertiaAppMock: vi.fn(),
    resolvePageComponentMock: vi.fn(() => 'resolved-component'),
    renderToStringMock: vi.fn(() => '<div />'),
}));

vi.mock('@inertiajs/react', () => ({
    createInertiaApp: (options: unknown) => mocks.createInertiaAppMock(options),
}));

vi.mock('@inertiajs/react/server', () => ({
    default: (callback: (page: unknown) => unknown) => mocks.createServerMock(callback),
}));

vi.mock('laravel-vite-plugin/inertia-helpers', () => ({
    resolvePageComponent: (path: string, pages: Record<string, unknown>) =>
        mocks.resolvePageComponentMock(path, pages),
}));

vi.mock('react-dom/server', () => ({
    default: { renderToString: (...args: unknown[]) => mocks.renderToStringMock(...args) },
    renderToString: (...args: unknown[]) => mocks.renderToStringMock(...args),
}));

describe('SSR bootstrap', () => {
    let page: { component: string };

    beforeEach(() => {
        vi.resetModules();
        page = { component: 'Docs/Overview' };
        mocks.createServerMock.mockClear();
        mocks.createInertiaAppMock.mockClear();
        mocks.resolvePageComponentMock.mockClear();
        mocks.renderToStringMock.mockClear();
        mocks.createServerMock.mockImplementation((callback: (p: typeof page) => unknown) => callback(page));
        mocks.resolvePageComponentMock.mockImplementation(() => 'resolved-component');
        mocks.renderToStringMock.mockImplementation(() => '<div />');
    });

    it('registers the server renderer with Inertia', async () => {
        await import('../ssr');

        expect(mocks.createServerMock).toHaveBeenCalledTimes(1);
        const serverCallback = mocks.createServerMock.mock.calls[0][0];
        expect(typeof serverCallback).toBe('function');

        expect(mocks.createInertiaAppMock).toHaveBeenCalledTimes(1);
        const options = mocks.createInertiaAppMock.mock.calls[0][0] as {
            page: typeof page;
            title: (title?: string) => string;
            resolve: (name: string) => unknown;
            setup: <P>(args: { App: React.ComponentType<P>; props: P }) => React.ReactElement<P>;
            render: (...args: unknown[]) => unknown;
        };

        expect(options.page).toBe(page);
        expect(typeof options.render).toBe('function');
        const elementToRender = <div>SSR payload</div>;
        options.render(elementToRender);
        expect(mocks.renderToStringMock).toHaveBeenCalledWith(elementToRender);
        const appName = options.title('');
        expect(appName).toBeTruthy();
        expect(options.title('Docs')).toBe(`Docs - ${appName}`);

        const resolved = options.resolve('Welcome');
        expect(mocks.resolvePageComponentMock).toHaveBeenCalledWith('./pages/Welcome.tsx', expect.any(Object));
        expect(resolved).toBe('resolved-component');

        const props = { greeting: 'Hello from SSR' };
        const AppComponent: React.ComponentType<typeof props> = (componentProps) => (
            <div {...componentProps} />
        );
        const rendered = options.setup({ App: AppComponent, props });
        expect(rendered.type).toBe(AppComponent);
        expect(rendered.props).toEqual(props);
    });
});
