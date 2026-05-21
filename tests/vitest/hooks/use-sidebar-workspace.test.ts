import '@testing-library/jest-dom/vitest';

import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import {
    getStoredSidebarWorkspace,
    normalizeSidebarPath,
    pathMatchesSidebarItem,
    resolveSidebarWorkspaceForPath,
    SIDEBAR_WORKSPACE_STORAGE_KEY,
    useSidebarWorkspace,
} from '@/hooks/use-sidebar-workspace';

const workspacePaths = {
    administration: ['/users', '/logs', '/settings', '/landing-pages', '/assistance', '/assessment', '/old-statistics', '/old-datasets'],
    curation: ['/dashboard', '/editor', '/resources', '/igsns', '/igsns-map', '/igsn-editor'],
};

describe('useSidebarWorkspace helpers', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    it('normalizes query strings, hashes, and trailing slashes', () => {
        expect(normalizeSidebarPath('/users/?page=2#logs')).toBe('/users');
        expect(normalizeSidebarPath('/dashboard/')).toBe('/dashboard');
        expect(normalizeSidebarPath('')).toBe('/');
    });

    it('matches nested paths only on segment boundaries', () => {
        expect(pathMatchesSidebarItem('/users/42/edit', '/users')).toBe(true);
        expect(pathMatchesSidebarItem('/users', '/users')).toBe(true);
        expect(pathMatchesSidebarItem('/users-archive', '/users')).toBe(false);
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

        expect(window.localStorage.getItem(SIDEBAR_WORKSPACE_STORAGE_KEY)).toBe('curation');
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
    });
});