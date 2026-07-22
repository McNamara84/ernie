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
    http.post(apiEndpoints.doiValidate, () => HttpResponse.json({ is_valid_format: true, exists: false })),
    http.get(apiEndpoints.pid4instInstruments, () => HttpResponse.json({ data: [] })),
    http.get(apiEndpoints.mslLaboratories, () =>
        HttpResponse.json({
            version: '1.1',
            lastUpdated: '2026-07-21T12:00:00+00:00',
            total: 0,
            data: [],
        }),
    ),
];

/** Shared MSW server instance registered globally by the Vitest setup. */
export const server = setupServer(...defaultHandlers);

export { http, HttpResponse };
