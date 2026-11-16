import { http, HttpResponse } from 'msw/http';
import { setupServer } from 'msw/node';

import { defaultHandlers } from './msw-handlers';

export const server = setupServer(...defaultHandlers);

export { http, HttpResponse };
export { defaultHandlers } from './msw-handlers';
