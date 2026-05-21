import { useCallback, useEffect, useMemo, useState } from 'react';

import { type SidebarWorkspace } from '@/types';

export const SIDEBAR_WORKSPACE_STORAGE_KEY = 'ernie.sidebar.workspace';

interface SidebarWorkspacePaths {
    administration: string[];
    curation: string[];
}

interface UseSidebarWorkspaceOptions {
    currentPath: string;
    enabled: boolean;
    workspacePaths: SidebarWorkspacePaths;
    storageKey?: string;
}

export function normalizeSidebarPath(path: string | null | undefined): string {
    const trimmedPath = typeof path === 'string' ? path.trim() : '';

    if (trimmedPath.length === 0) {
        return '/';
    }

    const withoutHash = trimmedPath.split('#')[0] ?? trimmedPath;
    const withoutQuery = withoutHash.split('?')[0] ?? withoutHash;

    if (withoutQuery.length === 0) {
        return '/';
    }

    return withoutQuery !== '/' && withoutQuery.endsWith('/') ? withoutQuery.slice(0, -1) : withoutQuery;
}

export function isSidebarWorkspace(value: string | null): value is SidebarWorkspace {
    return value === 'curation' || value === 'administration';
}

export function pathMatchesSidebarItem(path: string, href: string): boolean {
    const normalizedPath = normalizeSidebarPath(path);
    const normalizedHref = normalizeSidebarPath(href);

    if (normalizedHref === '/') {
        return normalizedPath === '/';
    }

    return normalizedPath === normalizedHref || normalizedPath.startsWith(`${normalizedHref}/`);
}

export function resolveSidebarWorkspaceForPath(path: string, workspacePaths: SidebarWorkspacePaths): SidebarWorkspace | null {
    if (workspacePaths.administration.some((href) => pathMatchesSidebarItem(path, href))) {
        return 'administration';
    }

    if (workspacePaths.curation.some((href) => pathMatchesSidebarItem(path, href))) {
        return 'curation';
    }

    return null;
}

export function getStoredSidebarWorkspace(storageKey: string = SIDEBAR_WORKSPACE_STORAGE_KEY): SidebarWorkspace | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const storedWorkspace = window.localStorage.getItem(storageKey);

        return isSidebarWorkspace(storedWorkspace) ? storedWorkspace : null;
    } catch {
        return null;
    }
}

function persistSidebarWorkspace(workspace: SidebarWorkspace, storageKey: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(storageKey, workspace);
    } catch {
        // Ignore storage failures and keep the in-memory state.
    }
}

export function useSidebarWorkspace({ currentPath, enabled, workspacePaths, storageKey = SIDEBAR_WORKSPACE_STORAGE_KEY }: UseSidebarWorkspaceOptions) {
    const routeWorkspace = useMemo(
        () => (enabled ? resolveSidebarWorkspaceForPath(currentPath, workspacePaths) : null),
        [currentPath, enabled, workspacePaths],
    );

    const [workspace, setWorkspaceState] = useState<SidebarWorkspace>(() => {
        if (!enabled) {
            return 'curation';
        }

        return routeWorkspace ?? getStoredSidebarWorkspace(storageKey) ?? 'curation';
    });

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const nextWorkspace = routeWorkspace ?? getStoredSidebarWorkspace(storageKey) ?? 'curation';

        setWorkspaceState((currentWorkspace) => (currentWorkspace === nextWorkspace ? currentWorkspace : nextWorkspace));
        persistSidebarWorkspace(nextWorkspace, storageKey);
    }, [enabled, routeWorkspace, storageKey]);

    const setWorkspace = useCallback(
        (nextWorkspace: SidebarWorkspace) => {
            setWorkspaceState(nextWorkspace);
            persistSidebarWorkspace(nextWorkspace, storageKey);
        },
        [storageKey],
    );

    return {
        currentPageWorkspace: routeWorkspace,
        isCurrentPageOutsideWorkspace: routeWorkspace !== null && routeWorkspace !== workspace,
        setWorkspace,
        workspace,
    };
}