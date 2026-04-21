import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';

import { apiEndpoints } from '@/lib/query-keys';

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
        HttpResponse.json({ url: 'https://vocab.example.test/msl' }),
    ),
];

/**
 * Shared MSW server instance.
 *
 * Registered globally in `vitest.setup.ts` so that every test suite gets a
 * consistent baseline for external HTTP requests.
 */
export const server = setupServer(...defaultHandlers);

export { http, HttpResponse };
