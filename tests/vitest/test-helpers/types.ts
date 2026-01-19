/**
 * Test Helper Types and Utilities
 *
 * Provides type-safe utilities for creating mock data in Vitest tests.
 * This helps reduce TypeScript errors from partial mock objects.
 */

import type { Page, PageProps } from '@inertiajs/core';

import type { FontSize, User, UserRole } from '@/types';

// ============================================================================
// Deep Partial Utility Type
// ============================================================================

/**
 * Recursively makes all properties of T optional.
 * Useful for creating partial mock objects that satisfy TypeScript.
 */
export type PartialDeep<T> = T extends object
    ? T extends Array<infer U>
        ? Array<PartialDeep<U>>
        : { [K in keyof T]?: PartialDeep<T[K]> }
    : T;

// ============================================================================
// User Mock Factory
// ============================================================================

/**
 * Default values for User mock
 */
const DEFAULT_USER: User = {
    id: 1,
    name: 'Test User',
    email: 'test@example.com',
    font_size_preference: 'regular' as FontSize,
    role: 'curator' as UserRole,
    role_label: 'Curator',
    is_active: true,
    can_manage_users: false,
    can_register_production_doi: false,
    can_delete_logs: false,
    can_access_logs: true,
    can_access_old_datasets: true,
    can_access_statistics: true,
    can_access_users: false,
    can_access_editor_settings: true,
    can_manage_landing_pages: false,
    deactivated_at: null,
    deactivated_by: null,
    avatar: undefined,
    email_verified_at: '2026-01-01T00:00:00.000Z',
    created_at: '2026-01-01T00:00:00.000Z',
    updated_at: '2026-01-01T00:00:00.000Z',
};

/**
 * Creates a mock User with optional overrides.
 *
 * @example
 * const user = createMockUser({ name: 'Admin', role: 'admin' });
 */
export function createMockUser(overrides?: Partial<User>): User {
    return {
        ...DEFAULT_USER,
        ...overrides,
    };
}

/**
 * Creates a mock admin User.
 */
export function createMockAdminUser(overrides?: Partial<User>): User {
    return createMockUser({
        role: 'admin',
        role_label: 'Admin',
        can_manage_users: true,
        can_register_production_doi: true,
        can_delete_logs: true,
        can_access_logs: true,
        can_access_old_datasets: true,
        can_access_statistics: true,
        can_access_users: true,
        can_access_editor_settings: true,
        can_manage_landing_pages: true,
        ...overrides,
    });
}

// ============================================================================
// Inertia Page Mock Factory
// ============================================================================

/**
 * Default values for Inertia Page mock
 */
const DEFAULT_PAGE_PROPERTIES = {
    component: 'TestComponent',
    url: '/test',
    version: '1.0.0',
    clearHistory: false,
    encryptHistory: false,
    scrollRegions: [],
    rememberedState: {},
    deferredProps: {},
};

/**
 * Creates a mock Inertia Page object with proper typing.
 * This is essential for testing components that use usePage().
 *
 * @example
 * const page = createMockPage({
 *     props: {
 *         resource: mockResource,
 *         auth: { user: createMockUser() },
 *     },
 * });
 */
export function createMockPage<T extends PageProps>(propsOverrides: T): Page<T> {
    return {
        ...DEFAULT_PAGE_PROPERTIES,
        props: propsOverrides,
    } as unknown as Page<T>;
}

/**
 * Creates a partial mock Inertia Page for simpler test cases.
 * Uses type assertion to bypass strict type checking.
 *
 * @example
 * const page = createPartialMockPage({ resource: { id: 1, title: 'Test' } });
 */
export function createPartialMockPage<T extends Record<string, unknown>>(props: T): Page<PageProps & T> {
    return {
        ...DEFAULT_PAGE_PROPERTIES,
        props: props as PageProps & T,
    } as unknown as Page<PageProps & T>;
}

// ============================================================================
// ORCID Search Result Mock Factory
// ============================================================================

export interface OrcidSearchResult {
    orcid: string;
    firstName: string;
    lastName: string;
    creditName?: string;
    institutions: Array<{
        name: string;
        rorId?: string | null;
    }>;
}

/**
 * Creates a mock ORCID search result.
 *
 * @example
 * const result = createMockOrcidSearchResult({ orcid: '0000-0001-2345-6789' });
 */
export function createMockOrcidSearchResult(overrides?: Partial<OrcidSearchResult>): OrcidSearchResult {
    return {
        orcid: '0000-0001-2345-6789',
        firstName: 'John',
        lastName: 'Doe',
        creditName: undefined,
        institutions: [{ name: 'Test University', rorId: null }],
        ...overrides,
    };
}

/**
 * Creates a mock ORCID search response.
 */
export function createMockOrcidSearchResponse(
    results: OrcidSearchResult[] = [createMockOrcidSearchResult()],
    total?: number
): { results: OrcidSearchResult[]; total: number } {
    return {
        results,
        total: total ?? results.length,
    };
}

// ============================================================================
// Funding Reference Mock Factory
// ============================================================================

export interface FundingReferenceMock {
    id: string;
    funderName: string;
    funderIdentifier: string;
    funderIdentifierType: string;
    awardNumber: string;
    awardTitle: string;
    awardUri: string;
}

/**
 * Creates a mock funding reference entry.
 */
export function createMockFundingReference(overrides?: Partial<FundingReferenceMock>): FundingReferenceMock {
    return {
        id: 'funding-1',
        funderName: 'DFG',
        funderIdentifier: 'https://doi.org/10.13039/501100001659',
        funderIdentifierType: 'Crossref Funder ID',
        awardNumber: '123456',
        awardTitle: 'Test Project',
        awardUri: '',
        ...overrides,
    };
}

// ============================================================================
// Type Assertion Helpers
// ============================================================================

/**
 * Type assertion helper for creating incomplete mock objects.
 * Use sparingly - prefer complete mocks when possible.
 *
 * @example
 * const partialResource = asMock<Resource>({ id: 1, title: 'Test' });
 */
export function asMock<T>(partial: Partial<T>): T {
    return partial as T;
}

/**
 * Type assertion helper for deeply partial mock objects.
 * Use for complex nested objects where complete mocks are impractical.
 *
 * @example
 * const deepPartial = asDeepMock<ComplexType>({ nested: { value: 1 } });
 */
export function asDeepMock<T>(partial: PartialDeep<T>): T {
    return partial as unknown as T;
}
