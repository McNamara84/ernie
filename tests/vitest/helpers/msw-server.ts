import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';

import { apiEndpoints } from '@/lib/query-keys';

/**
 * External URL returned by the default handler for `mslVocabularyUrl`.
 *
 * Exposed so tests can reference it when overriding the handler (e.g. to
 * assert that `useMSLLaboratories` requests this URL exactly once).
 */
export const DEFAULT_MSL_VOCABULARY_URL = 'https://vocab.example.test/msl';

/**
 * Default MSW request handlers used by the Vitest setup.
 *
 * Individual tests can override these via `server.use(...)` to simulate
 * specific success/error scenarios without having to reset the whole suite.
 */
export const defaultHandlers = [
    http.get(apiEndpoints.rorAffiliations, () => HttpResponse.json([])),
    http.post(apiEndpoints.rorResolve, () => HttpResponse.json({ results: [] })),
    http.post(apiEndpoints.doiValidate, () =>
        HttpResponse.json({ is_valid_format: true, exists: false }),
    ),
    http.get(apiEndpoints.pid4instInstruments, () => HttpResponse.json({ data: [] })),
    http.get(apiEndpoints.mslVocabularyUrl, () =>
        HttpResponse.json({ url: DEFAULT_MSL_VOCABULARY_URL }),
    ),
    // The MSL vocabulary URL above points to an external host. Provide a
    // deterministic default so that `useMSLLaboratories` tests never accidentally
    // attempt real network requests when running with `onUnhandledRequest: 'bypass'`.
    http.get(DEFAULT_MSL_VOCABULARY_URL, () => HttpResponse.json([])),
];

/**
 * Shared MSW server instance.
 *
 * Registered globally in `vitest.setup.ts` so that every test suite gets a
 * consistent baseline for external HTTP requests.
 */
export const server = setupServer(...defaultHandlers);

export { http, HttpResponse };
