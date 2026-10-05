import { expect, test } from '@playwright/test';

test('renders API 1.1 with OpenAPI 3.2.1 and all ELMO-MSL operations in Swagger UI', async ({ page, request }) => {
    const response = await request.get('/api/v1/doc', { headers: { Accept: 'application/json' } });
    expect(response.ok()).toBe(true);
    const spec = await response.json();
    expect(spec.openapi).toBe('3.2.1');
    expect(spec.info.version).toBe('1.1.0');
    const paths = Object.keys(spec.paths).filter((path) => path.includes('elmo-msl'));
    expect(paths).toHaveLength(27);
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/api/v1/doc');
    await expect(page.locator('#swagger-ui .info .title')).toContainText('ERNIE');
    await expect(page.locator('#swagger-ui .info .version').first()).toContainText('1.1.0');
    await expect(page.locator('#swagger-ui .version-stamp')).toContainText('OAS 3.2');
    await expect(page.locator('#swagger-ui .opblock-summary-path').filter({ hasText: 'elmo-msl' })).toHaveCount(27);
    await expect(page.locator('#swagger-ui .errors-wrapper')).toHaveCount(0);
    expect(errors).toEqual([]);
});
