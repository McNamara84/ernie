import { apiEndpoints } from '@/lib/query-keys';

/** Identical offline responses for the Node MSW and browser network adapters. */
export const defaultApiResponses = [
    { path: '/related-items/vocabularies', method: 'GET', body: { resourceTypes: [], relationTypes: [], contributorTypes: [] } },
    { path: apiEndpoints.rorAffiliations, method: 'GET', body: [] },
    { path: apiEndpoints.rorResolve, method: 'POST', body: { results: [] } },
    { path: apiEndpoints.doiValidate, method: 'POST', body: { is_valid_format: true, exists: false } },
    { path: apiEndpoints.pid4instInstruments, method: 'GET', body: { data: [] } },
    { path: apiEndpoints.mslLaboratories, method: 'GET', body: { version: '1.1', lastUpdated: '2026-07-21T12:00:00+00:00', total: 0, data: [] } },
] as const;
