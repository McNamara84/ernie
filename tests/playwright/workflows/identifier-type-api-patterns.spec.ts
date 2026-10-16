import { readFileSync } from 'node:fs';
import path from 'node:path';

import { expect, test } from '@playwright/test';

interface IdentifierPatternCase {
    type: string;
    input: string;
    valid: boolean;
}

interface IdentifierTypeResponse {
    slug: string;
    pattern: string;
    patterns: {
        validation: { pattern: string; priority: number }[];
        detection: { pattern: string; priority: number }[];
    };
}

const cases: IdentifierPatternCase[] = JSON.parse(
    readFileSync(path.resolve('tests/pest/Fixtures/Identifiers/identifier-pattern-cases.json'), 'utf8'),
);

test('ELMO API patterns work after one JSON decode in JavaScript and native HTML validation', async ({ page, request }) => {
    const response = await request.get('/api/v1/identifier-types/elmo', {
        headers: { 'X-API-Key': process.env.PLAYWRIGHT_ERNIE_API_KEY ?? 'playwright-identifier-api-test-key' },
    });
    expect(response.status()).toBe(200);
    // Decode the actual HTTP body once, without unescaping regex strings again.
    const types: IdentifierTypeResponse[] = JSON.parse(await response.text());

    await page.setContent('<input id="identifier" type="text" required>');
    const failures = await page.evaluate(
        ({ types, cases }) => {
            const input = document.querySelector<HTMLInputElement>('#identifier')!;
            const failures: string[] = [];

            for (const example of cases) {
                const type = types.find((entry) => entry.slug === example.type)!;
                const sources = [type.pattern, ...type.patterns.validation.map((entry) => entry.pattern)];
                const matches = sources.map((source) => {
                    const regexResult = new RegExp(source, 'i').test(example.input);
                    // Compile with HTML's v flag explicitly as browsers ignore an
                    // invalid pattern attribute rather than rejecting the input.
                    new RegExp(source, 'v');
                    input.pattern = source;
                    input.value = example.input;
                    const nativeResult = input.checkValidity();

                    // Text inputs sanitize newlines themselves; JavaScript still
                    // tests the unsanitized HTTP input and must reject that value.
                    if (input.value === example.input && nativeResult !== regexResult) {
                        failures.push(`HTML/JS disagree for ${example.type}: ${JSON.stringify(example.input)}`);
                    }

                    return regexResult;
                });

                if (matches[0] !== example.valid || matches.slice(1).some(Boolean) !== example.valid) {
                    failures.push(`Unexpected ${example.type} validation: ${JSON.stringify(example.input)}`);
                }
            }

            return failures;
        },
        { types, cases },
    );

    expect(failures).toEqual([]);
});
