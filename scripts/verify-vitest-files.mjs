import assert from 'node:assert/strict';

/** Prevent a successful merge from hiding an omitted project or duplicate shard. */
export function verifyVitestFiles(expected, actual) {
    const normalize = (path) => path.replaceAll('\\', '/');
    const expectedFiles = expected.map(normalize).sort();
    const actualFiles = actual.map(normalize).sort();
    assert.equal(new Set(actualFiles).size, actualFiles.length, 'Duplicate test files in Vitest shard results.');
    const missing = expectedFiles.filter((path) => !actualFiles.includes(path));
    const extra = actualFiles.filter((path) => !expectedFiles.includes(path));
    assert.deepEqual({ missing, extra }, { missing: [], extra: [] }, 'Vitest reports must contain the complete configured file inventory.');
    return {
        jsdom: actualFiles.filter((path) => path.startsWith('tests/vitest/')).length,
        browser: actualFiles.filter((path) => path.startsWith('tests/vitest-browser/')).length,
    };
}
