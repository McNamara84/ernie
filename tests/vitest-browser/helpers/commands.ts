import type { SerializedLocator } from '@vitest/browser';
import type { BrowserContext } from 'playwright';
import type { BrowserCommandContext } from 'vitest/node';

export interface ApiResponse {
    path: string;
    method?: string;
    status?: number;
    body: unknown;
}

const networks = new WeakMap<BrowserContext, { responses: ApiResponse[]; unexpected: string[] }>();

// Application fetch/XHR only. Keep Vite's module loading and Vitest's own
// requests intact, including module factories when import-map mocks are off.
export async function mockApi({ context }: BrowserCommandContext, responses: ApiResponse[]) {
    let network = networks.get(context);
    if (!network) {
        network = { responses: [], unexpected: [] };
        networks.set(context, network);
        const state = network;
        await context.route('**/*', async (route) => {
            const request = route.request();
            const url = new URL(request.url());
            if (
                !['fetch', 'xhr'].includes(request.resourceType()) ||
                /^\/(?:@|__vitest|node_modules\/|resources\/(?:js|css|data)\/|tests\/)/.test(url.pathname)
            ) {
                return route.fallback();
            }
            const response = state.responses.find((item) => item.path === url.pathname && (item.method ?? 'GET') === request.method());
            if (!response) {
                state.unexpected.push(`${request.method()} ${url.href}`);
                return route.fulfill({ status: 500, json: { error: 'Unhandled browser-test request' } });
            }
            return route.fulfill({ status: response.status ?? 200, json: response.body });
        });
    }
    network.responses = responses;
    network.unexpected = [];
}

export function assertApiHandled({ context }: BrowserCommandContext) {
    const unexpected = networks.get(context)?.unexpected ?? [];
    if (unexpected.length) throw new Error(`Unhandled browser-test requests:\n${unexpected.join('\n')}`);
}

export async function dragBy({ page, iframe }: BrowserCommandContext, locator: SerializedLocator, deltaX: number, deltaY: number) {
    const bounds = await iframe.locator(locator.selector).boundingBox();
    if (!bounds) throw new Error(`Cannot drag invisible element: ${locator.locator}`);
    const x = bounds.x + bounds.width / 2;
    const y = bounds.y + bounds.height / 2;
    await page.mouse.move(x, y);
    await page.mouse.down();
    try {
        await page.mouse.move(x + deltaX, y + deltaY, { steps: 12 });
    } finally {
        await page.mouse.up();
    }
}
