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

const swhidCases: { input: string; normalized: string; valid: boolean }[] = JSON.parse(
    readFileSync(path.resolve('tests/fixtures/identifiers/swhid-cases.json'), 'utf8'),
).cases;

test('ELMO API patterns work after one JSON decode in JavaScript and native HTML validation', async ({ page, request }) => {
    const response = await request.get('/api/v1/identifier-types/elmo', {
        headers: { 'X-API-Key': process.env.PLAYWRIGHT_ERNIE_API_KEY ?? 'playwright-identifier-api-test-key' },
    });
    expect(response.status()).toBe(200);
    // Decode the actual HTTP body once, without unescaping regex strings again.
    const types: IdentifierTypeResponse[] = JSON.parse(await response.text());
    expect(types).toHaveLength(23);

    await page.setContent('<input id="identifier" type="text" required>');
    const failures = await page.evaluate(
        ({ types, cases }) => {
            const input = document.querySelector<HTMLInputElement>('#identifier')!;
            const failures: string[] = [];

            // Every returned scalar must compile, including types without
            // dedicated input fixtures. HTML silently ignores invalid patterns.
            for (const type of types) {
                for (const source of [type.pattern, ...type.patterns.validation.map((entry) => entry.pattern)]) {
                    try {
                        new RegExp(source, 'i');
                        new RegExp(source, 'v');
                    } catch (error) {
                        failures.push(`Invalid ${type.slug} validation pattern: ${String(error)}`);
                    }
                }
            }

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

test('SWHID API syntax patterns accept all valid fixtures in native HTML and expose semantic validation', async ({ page, request }) => {
    const headers = { 'X-API-Key': process.env.PLAYWRIGHT_ERNIE_API_KEY ?? 'playwright-identifier-api-test-key' };
    const response = await request.get('/api/v1/identifier-types/elmo', { headers });
    expect(response.status()).toBe(200);
    const type = (await response.json()).find((entry: IdentifierTypeResponse) => entry.slug === 'SWHID');
    expect(type.semanticValidation).toEqual({
        method: 'POST',
        endpoint: '/api/v1/identifiers/validate',
        profile: 'ernie-swhid-v1',
        specificationVersion: '1.2',
        requiresApiKey: true,
    });
    await page.setContent('<input id="identifier" type="text" required>');
    const failures = await page.evaluate(
        ({ pattern, inputs }) => {
            const input = document.querySelector<HTMLInputElement>('#identifier')!;
            input.pattern = pattern;
            const regex = new RegExp(pattern, 'v');
            return inputs.filter((value) => {
                input.value = value;
                return !input.checkValidity() || !regex.test(value);
            });
        },
        {
            pattern: type.pattern,
            inputs: swhidCases
                .filter((entry) => entry.valid)
                .flatMap((entry) => [entry.input.replace(/^[\s\u0085]+|[\s\u0085]+$/g, ''), entry.normalized]),
        },
    );
    expect(failures).toEqual([]);
    for (const identifier of [swhidCases[0].input, swhidCases[0].input + ';lines=15-9', swhidCases[0].input + ';path=/a;path=/b']) {
        const result = await request.post(type.semanticValidation.endpoint, { headers, data: { identifierType: 'SWHID', identifier } });
        expect(result.status()).toBe(200);
        expect((await result.json()).valid).toBe(identifier === swhidCases[0].input);
    }
});
