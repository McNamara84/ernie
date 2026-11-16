import { defaultApiResponses } from '@tests/test-data/api-responses';
import { http, HttpResponse } from 'msw/http';

export const defaultHandlers = defaultApiResponses.map(({ path, method, body }) =>
    (method === 'POST' ? http.post : http.get)(path, () => HttpResponse.json(body)),
);
