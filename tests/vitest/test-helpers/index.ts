/**
 * Test Helpers Index
 *
 * Re-exports all test utilities for convenient importing.
 *
 * @example
 * import { createMockUser, createMockPage, asMock } from '@test-helpers';
 */

export {
    asDeepMock,
    // Type assertion helpers
    asMock,
    createMockAdminUser,
    createMockFundingReference,
    createMockOrcidSearchResponse,
    createMockOrcidSearchResult,
    // Inertia Page factories
    createMockPage,
    // User factories
    createMockUser,
    createPartialMockPage,
    // Funding Reference factories
    type FundingReferenceMock,
    // ORCID factories
    type OrcidSearchResult,
    // Type utilities
    type PartialDeep,
} from './types';
