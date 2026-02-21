/**
 * Shared Inertia.js Mock Factories
 *
 * Provides reusable, type-safe mock factories for Inertia.js components.
 * Use these in `vi.mock('@inertiajs/react')` calls to avoid duplication.
 *
 * @example
 * // Simple usage with defaults:
 * vi.mock('@inertiajs/react', () => createInertiaMock());
 *
 * // With custom usePage props:
 * vi.mock('@inertiajs/react', () => createInertiaMock({
 *     usePageProps: { auth: { user: createMockAdminUser() } },
 * }));
 *
 * // With external usePageMock for per-test overrides:
 * const usePageMock = vi.fn();
 * vi.mock('@inertiajs/react', () => createInertiaMock({ usePageFn: usePageMock }));
 */

import React from 'react';
import { vi } from 'vitest';

import type { User } from '@/types';

import { createMockUser } from './types';

// ============================================================================
// Router Mock
// ============================================================================

export interface RouterMock {
    get: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
    put: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
    visit: ReturnType<typeof vi.fn>;
    reload: ReturnType<typeof vi.fn>;
    flushAll: ReturnType<typeof vi.fn>;
}

/**
 * Creates a mock Inertia router with all methods as `vi.fn()`.
 */
export function createRouterMock(overrides?: Partial<RouterMock>): RouterMock {
    return {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
        flushAll: vi.fn(),
        ...overrides,
    };
}

// ============================================================================
// usePage Mock
// ============================================================================

export interface UsePageProps {
    auth?: {
        user: User | null;
    };
    [key: string]: unknown;
}

/**
 * Creates a `usePage()` return value with the given props.
 */
export function createUsePageReturn(props?: Partial<UsePageProps>) {
    const defaultProps: UsePageProps = {
        auth: {
            user: createMockUser(),
        },
    };

    return {
        props: { ...defaultProps, ...props },
    };
}

// ============================================================================
// useForm Mock
// ============================================================================

export interface UseFormMock<T = Record<string, unknown>> {
    data: T;
    setData: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
    put: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
    processing: boolean;
    errors: Record<string, string>;
    reset: ReturnType<typeof vi.fn>;
    clearErrors: ReturnType<typeof vi.fn>;
    wasSuccessful: boolean;
    recentlySuccessful: boolean;
    transform: ReturnType<typeof vi.fn>;
}

/**
 * Creates a mock `useForm()` return value.
 */
export function createUseFormMock<T = Record<string, unknown>>(
    initialData?: T,
    overrides?: Partial<UseFormMock<T>>,
): UseFormMock<T> {
    return {
        data: initialData ?? ({} as T),
        setData: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
        clearErrors: vi.fn(),
        wasSuccessful: false,
        recentlySuccessful: false,
        transform: vi.fn(),
        ...overrides,
    };
}

// ============================================================================
// Link Component Mock
// ============================================================================

/**
 * Resolves an Inertia `href` prop to a string URL.
 * Handles both string hrefs and Wayfinder route objects (`{ url: string }`).
 */
export function resolveHref(href: unknown): string {
    if (typeof href === 'string') {
        return href;
    }
    if (href && typeof href === 'object' && 'url' in (href as Record<string, unknown>)) {
        return String((href as { url: string }).url);
    }
    return '';
}

function MockLink({
    href,
    children,
    onClick,
    ...props
}: {
    href: unknown;
    children?: React.ReactNode;
    onClick?: (e: React.MouseEvent) => void;
} & React.AnchorHTMLAttributes<HTMLAnchorElement>) {
    const resolvedHref = resolveHref(href);
    return React.createElement(
        'a',
        {
            href: resolvedHref,
            onClick: (e: React.MouseEvent) => {
                e.preventDefault();
                onClick?.(e);
            },
            ...props,
        },
        children,
    );
}

// ============================================================================
// Head Component Mock
// ============================================================================

function MockHead({ children, title }: { children?: React.ReactNode; title?: string }) {
    if (title) {
        document.title = title;
    }
    return React.createElement(React.Fragment, null, children);
}

// ============================================================================
// Form Component Mock
// ============================================================================

function MockForm({
    children,
    ...props
}: {
    children: React.ReactNode | ((args: { processing: boolean }) => React.ReactNode);
} & React.FormHTMLAttributes<HTMLFormElement>) {
    const content = typeof children === 'function' ? children({ processing: false }) : children;
    return React.createElement('form', props, content);
}

// ============================================================================
// Full Inertia Mock Factory
// ============================================================================

export interface InertiaMockOptions {
    /** Static props for usePage(). Ignored if `usePageFn` is provided. */
    usePageProps?: Partial<UsePageProps>;
    /** External vi.fn() for usePage — allows per-test overrides via mockReturnValue. */
    usePageFn?: ReturnType<typeof vi.fn>;
    /** Custom router mock. Defaults to `createRouterMock()`. */
    router?: Partial<RouterMock>;
    /** Initial data for useForm. If provided, useForm is included in the mock. */
    useFormData?: Record<string, unknown>;
    /** Overrides for useForm mock. */
    useFormOverrides?: Partial<UseFormMock>;
}

/**
 * Creates a complete `@inertiajs/react` module mock.
 *
 * @example
 * // Defaults (curator user, empty router):
 * vi.mock('@inertiajs/react', () => createInertiaMock());
 *
 * // Admin user:
 * vi.mock('@inertiajs/react', () => createInertiaMock({
 *     usePageProps: { auth: { user: createMockAdminUser() } },
 * }));
 *
 * // External usePageMock for per-test overrides:
 * const usePageMock = vi.fn(() => createUsePageReturn());
 * vi.mock('@inertiajs/react', () => createInertiaMock({ usePageFn: usePageMock }));
 */
export function createInertiaMock(options?: InertiaMockOptions) {
    const router = createRouterMock(options?.router);

    const usePage = options?.usePageFn ?? (() => createUsePageReturn(options?.usePageProps));

    const result: Record<string, unknown> = {
        Head: MockHead,
        Link: MockLink,
        router,
        usePage,
    };

    if (options?.useFormData !== undefined || options?.useFormOverrides !== undefined) {
        result.useForm = (initial: unknown) =>
            createUseFormMock(initial ?? options?.useFormData, options?.useFormOverrides);
    }

    // Also export Form component mock
    result.Form = MockForm;

    return result;
}
