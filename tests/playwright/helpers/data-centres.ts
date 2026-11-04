import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, type Page } from '@playwright/test';

import type { DataCentre } from '../../../resources/js/types/data-centres';
import { prepareHtmlInterception } from './navigation';

export const dataCentres: DataCentre[] = JSON.parse(readFileSync(resolve(process.cwd(), 'resources/data/data-centres.json'), 'utf8'));

/** Exercise the complete editorial catalogue without changing the development database.
 * Backend integration tests independently verify live facet selection and the public props.
 */
export async function useDataCentreCatalogue(page: Page, centres: DataCentre[] = dataCentres) {
    await prepareHtmlInterception(page);
    await page.route(/\/data-centres(?:\/description)?(?:\?[^#]*)?$/, async (route) => {
        const response = await route.fetch();
        const body = await response.text();
        const replaceProps = (json: string) => {
            const payload = JSON.parse(json);
            payload.props.dataCentres = centres;
            return JSON.stringify(payload).replaceAll('<', '\\u003c');
        };
        if (response.headers()['content-type']?.includes('application/json')) {
            await route.fulfill({ response, body: replaceProps(body) });
        } else {
            const script = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
            expect(body).toMatch(script);
            await route.fulfill({ response, body: body.replace(script, (_match, start, json, end) => start + replaceProps(json) + end) });
        }
    });
}

export async function openDataCentres(page: Page, path = '/data-centres') {
    const navigate = () => page.goto(path, { waitUntil: 'domcontentloaded' });
    const response = await navigate().catch((error: unknown) => {
        if (!(error instanceof Error) || !error.message.includes('SSL connect error')) throw error;
        return navigate();
    });
    await expect(
        page.getByRole('heading', { level: 1, name: path.includes('/description') ? 'Data Centre Descriptions' : 'Data Centres', exact: true }),
    ).toBeVisible();
    return response;
}
