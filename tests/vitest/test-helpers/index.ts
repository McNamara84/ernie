/**
 * Test Helpers Index
 *
 * Re-exports all test utilities for convenient importing.
 *
 * @example
 * import { createMockUser, createMockPage, asMock } from '@test-helpers';
 * import { createInertiaMock, createRouterMock } from '@test-helpers/inertia-mocks';
 * import { createTagifyMock, MockTagify } from '@test-helpers/tagify-mock';
 * import { createRouteMocks, createRoute } from '@test-helpers/route-mocks';
 * import { createMockResource, createMockIgsn } from '@test-helpers/factories';
 */

// Type utilities & User factories
export {
    asDeepMock,
    asMock,
    createMockAdminUser,
    createMockFundingReference,
    createMockOrcidSearchResponse,
    createMockOrcidSearchResult,
    createMockPage,
    createMockUser,
    createPartialMockPage,
    type FundingReferenceMock,
    type OrcidSearchResult,
    type PartialDeep,
} from './types';

// Inertia mock factories
export {
    createInertiaMock,
    createRouterMock,
    createUseFormMock,
    createUsePageReturn,
    type InertiaMockOptions,
    resolveHref,
    type RouterMock,
    type UseFormMock,
    type UsePageProps,
} from './inertia-mocks';

// Tagify mock
export { createTagifyMock, MockTagify } from './tagify-mock';

// Route mock factories
export {
    COMMON_ADMIN_ROUTES,
    COMMON_AUTH_ROUTES,
    COMMON_PUBLIC_ROUTES,
    createRoute,
    createRouteMocks,
} from './route-mocks';

// Domain data factories
export {
    createMockIgsn,
    createMockIgsns,
    createMockIgsnWithError,
    createMockPaginatedResponse,
    createMockPagination,
    createMockPortalFilters,
    createMockPortalPageProps,
    createMockPortalPagination,
    createMockPortalResource,
    createMockPortalResourceWithLocation,
    createMockResource,
    createMockResourceFilterOptions,
    createMockResourceFilterState,
    createMockResources,
    createMockResourceSortState,
    createMockSortState,
    type MockIgsn,
    type MockResource,
    type PaginationMeta,
    resetFactoryCounters,
    type SortState,
} from './factories';
