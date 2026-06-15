import '@testing-library/jest-dom/vitest';

import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    getStoredSidebarWorkspace,
    isSidebarWorkspace,
    normalizeSidebarPath,
    pathMatchesSidebarItem,
    resolveSidebarWorkspaceForPath,
    SIDEBAR_WORKSPACE_STORAGE_KEY,
    useSidebarWorkspace,
} from '@/hooks/use-sidebar-workspace';

const workspacePaths = {
    administration: ['/users', '/logs', '/settings', '/landing-pages', '/assistance', '/assessment', '/statistics', '/old-statistics', '/old-datasets'],
    curation: ['/dashboard', '/editor', '/resources', '/igsns', '/igsns-map', '/igsn-editor'],
};

describe('useSidebarWorkspace helpers', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('normalizes query strings, hashes, and trailing slashes', () => {
        expect(normalizeSidebarPath('/users/?page=2#logs')).toBe('/users');
        expect(normalizeSidebarPath('/dashboard/')).toBe('/dashboard');
        expect(normalizeSidebarPath('')).toBe('/');
        expect(normalizeSidebarPath(undefined)).toBe('/');
        expect(normalizeSidebarPath(null)).toBe('/');
        expect(normalizeSidebarPath('/?tab=overview#section')).toBe('/');
    });

    it('matches nested paths only on segment boundaries', () => {
        expect(pathMatchesSidebarItem('/users/42/edit', '/users')).toBe(true);
        expect(pathMatchesSidebarItem('/users', '/users')).toBe(true);
        expect(pathMatchesSidebarItem('/users-archive', '/users')).toBe(false);
        expect(pathMatchesSidebarItem('/', '/')).toBe(true);
        expect(pathMatchesSidebarItem('/dashboard', '/')).toBe(false);
    });

    it('accepts only supported sidebar workspace identifiers', () => {
        expect(isSidebarWorkspace('curation')).toBe(true);
        expect(isSidebarWorkspace('administration')).toBe(true);
        expect(isSidebarWorkspace('docs')).toBe(false);
        expect(isSidebarWorkspace(null)).toBe(false);
    });

    it('resolves the sidebar workspace from configured paths', () => {
        expect(resolveSidebarWorkspaceForPath('/logs', workspacePaths)).toBe('administration');
        expect(resolveSidebarWorkspaceForPath('/igsns/123', workspacePaths)).toBe('curation');
        expect(resolveSidebarWorkspaceForPath('/docs', workspacePaths)).toBeNull();
    });

    it('returns only valid stored workspaces', () => {
        window.localStorage.setItem(SIDEBAR_WORKSPACE_STORAGE_KEY, 'administration');
        expect(getStoredSidebarWorkspace()).toBe('administration');

        window.localStorage.setItem(SIDEBAR_WORKSPACE_STORAGE_KEY, 'invalid');
        expect(getStoredSidebarWorkspace()).toBeNull();
    });

    it('returns null when storage is unavailable', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('Storage blocked');
        });

        expect(getStoredSidebarWorkspace()).toBeNull();

        vi.restoreAllMocks();
        vi.stubGlobal('window', undefined);

        expect(getStoredSidebarWorkspace()).toBeNull();
    });
});

describe('useSidebarWorkspace', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    it('defaults to curation for first-visit privileged users on curation routes', async () => {
        const { result } = renderHook(() =>
            useSidebarWorkspace({
                currentPath: '/dashboard',
                enabled: true,
                workspacePaths,
            }),
        );

        await waitFor(() => {
            expect(result.current.workspace).toBe('curation');
        });

        await waitFor(() => {
            expect(window.localStorage.getItem(SIDEBAR_WORKSPACE_STORAGE_KEY)).toBe('curation');
        }, { timeout: 5000 });
    });

    it('restores the stored workspace for global routes outside both workspaces', async () => {
        window.localStorage.setItem(SIDEBAR_WORKSPACE_STORAGE_KEY, 'administration');

        const { result } = renderHook(() =>
            useSidebarWorkspace({
                currentPath: '/docs',
                enabled: true,
                workspacePaths,
            }),
        );

        await waitFor(() => {
            expect(result.current.workspace).toBe('administration');
        });

        expect(result.current.currentPageWorkspace).toBeNull();
    });

    it('prefers the current route workspace over stored state on page load', async () => {
        window.localStorage.setItem(SIDEBAR_WORKSPACE_STORAGE_KEY, 'administration');

        const { result } = renderHook(() =>
            useSidebarWorkspace({
                currentPath: '/dashboard',
                enabled: true,
                workspacePaths,
            }),
        );

        await waitFor(() => {
            expect(result.current.workspace).toBe('curation');
        });

        expect(window.localStorage.getItem(SIDEBAR_WORKSPACE_STORAGE_KEY)).toBe('curation');
    });

    it('persists manual workspace changes and flags when the active page falls outside the selected workspace', async () => {
        const { result } = renderHook(() =>
            useSidebarWorkspace({
                currentPath: '/dashboard',
                enabled: true,
                workspacePaths,
            }),
        );

        await waitFor(() => {
            expect(result.current.workspace).toBe('curation');
        });

        act(() => {
            result.current.setWorkspace('administration');
        });

        expect(result.current.workspace).toBe('administration');
        expect(result.current.isCurrentPageOutsideWorkspace).toBe(true);
        expect(window.localStorage.getItem(SIDEBAR_WORKSPACE_STORAGE_KEY)).toBe('administration');
    });

    it('reconciles to the route workspace after navigation', async () => {
        const { result, rerender } = renderHook(
            ({ currentPath }) =>
                useSidebarWorkspace({
                    currentPath,
                    enabled: true,
                    workspacePaths,
                }),
            {
                initialProps: {
                    currentPath: '/dashboard',
                },
            },
        );

        await waitFor(() => {
            expect(result.current.workspace).toBe('curation');
        });

        act(() => {
            result.current.setWorkspace('administration');
        });

        rerender({ currentPath: '/users' });

        await waitFor(() => {
            expect(result.current.workspace).toBe('administration');
        });

        expect(result.current.currentPageWorkspace).toBe('administration');
        expect(result.current.isCurrentPageOutsideWorkspace).toBe(false);
    });

    it('falls back to curation when the workspace switcher is disabled', () => {
        window.localStorage.setItem(SIDEBAR_WORKSPACE_STORAGE_KEY, 'administration');

        const { result } = renderHook(() =>
            useSidebarWorkspace({
                currentPath: '/logs',
                enabled: false,
                workspacePaths,
            }),
        );

        expect(result.current.workspace).toBe('curation');
        expect(result.current.currentPageWorkspace).toBeNull();
        expect(result.current.isCurrentPageOutsideWorkspace).toBe(false);
        expect(window.localStorage.getItem(SIDEBAR_WORKSPACE_STORAGE_KEY)).toBe('administration');
    });

    it('keeps the in-memory workspace state when local storage persistence fails', async () => {
        const originalLocalStorage = window.localStorage;
        const blockedStorage = {
            clear: vi.fn(),
            getItem: vi.fn(() => null),
            key: vi.fn(() => null),
            length: 0,
            removeItem: vi.fn(),
            setItem: vi.fn(() => {
                throw new Error('Storage blocked');
            }),
        } as Storage;

        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            value: blockedStorage,
        });

        try {
            const { result } = renderHook(() =>
                useSidebarWorkspace({
                    currentPath: '/dashboard',
                    enabled: true,
                    workspacePaths,
                }),
            );

            await waitFor(() => {
                expect(result.current.workspace).toBe('curation');
            });

            act(() => {
                result.current.setWorkspace('administration');
            });

            expect(result.current.workspace).toBe('administration');
            expect(result.current.isCurrentPageOutsideWorkspace).toBe(true);
            expect(blockedStorage.setItem).toHaveBeenCalled();
        } finally {
            Object.defineProperty(window, 'localStorage', {
                configurable: true,
                value: originalLocalStorage,
            });
        }
    });
});
