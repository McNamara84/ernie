import { expect, test } from '@playwright/test';

test('renders API 1.2 and distinguishes current and deprecated vocabulary operations in Swagger UI', async ({ page, request }) => {
    const response = await request.get('/api/v1/doc', { headers: { Accept: 'application/json' } });
    expect(response.ok()).toBe(true);
    const spec = await response.json();
    expect(spec.openapi).toBe('3.2.1');
    expect(spec.info.version).toBe('1.2.0');
    const deprecatedPaths = Object.keys(spec.paths).filter((path) => {
        const operation = spec.paths[path].get;
        return operation?.tags.includes('Vocabularies') && operation.deprecated;
    });
    expect(deprecatedPaths).toHaveLength(30);
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (message.type() === 'error') errors.push(message.text());
    });
    await page.goto('/api/v1/doc');
    await expect(page.locator('#swagger-ui .info .title')).toContainText('ERNIE');
    await expect(page.locator('#swagger-ui .info .version').first()).toContainText('1.2.0');
    await expect(page.locator('#swagger-ui .version-stamp')).toContainText('OAS 3.2');
    const deprecated = page.locator('#swagger-ui .opblock-deprecated [data-path]');
    await expect(deprecated).toHaveCount(deprecatedPaths.length);
    expect(await deprecated.evaluateAll((nodes) => nodes.map((node) => node.getAttribute('data-path')).sort())).toEqual(deprecatedPaths.sort());
    for (const path of ['/api/v1/elmo/vocabularies/visibility', '/api/v1/elmo-msl/vocabularies/visibility', '/api/v1/vocabularies/data/{vocab}']) {
        await expect(page.locator(`#swagger-ui .opblock:not(.opblock-deprecated) .opblock-summary-path[data-path="${path}"]`)).toBeVisible();
    }
    await expect(page.locator('#swagger-ui .errors-wrapper')).toHaveCount(0);
    await expect(page.locator('#swagger-ui .fallback')).toHaveCount(0);
    expect(errors).toEqual([]);
});
