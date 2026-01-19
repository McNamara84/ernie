/**
 * Test Helpers Index
 *
 * Re-exports all test utilities for convenient importing.
 *
 * @example
 * import { createMockUser, createMockPage, asMock } from '@test-helpers';
 */

export {
    // Type utilities
    type PartialDeep,
    // User factories
    createMockUser,
    createMockAdminUser,
    // Inertia Page factories
    createMockPage,
    createPartialMockPage,
    // ORCID factories
    type OrcidSearchResult,
    createMockOrcidSearchResult,
    createMockOrcidSearchResponse,
    // Funding Reference factories
    type FundingReferenceMock,
    createMockFundingReference,
    // Type assertion helpers
    asMock,
    asDeepMock,
} from './types';
