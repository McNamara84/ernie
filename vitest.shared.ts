import { readFileSync } from 'node:fs';
import { availableParallelism } from 'node:os';
import { fileURLToPath } from 'node:url';

import react from '@vitejs/plugin-react';
import type { ViteUserConfig } from 'vitest/config';
import type { CoverageOptions } from 'vitest/node';

export function resolveVitestMaxWorkers(
    isCi = Boolean(process.env.CI),
    configuredWorkers = process.env.ERNIE_VITEST_WORKERS,
    availableCpus?: number,
): number | undefined {
    if (isCi) return undefined;
    const normalizedWorkers = configuredWorkers?.trim();
    if (!normalizedWorkers) {
        return Math.max(1, Math.min(8, Math.floor((availableCpus ?? availableParallelism()) / 2)));
    }
    const workers = Number(normalizedWorkers);
    if (!Number.isSafeInteger(workers) || workers < 1) {
        throw new Error('ERNIE_VITEST_WORKERS must be a positive integer.');
    }
    return workers;
}

export function resolveVitestRetry(isCi = Boolean(process.env.CI)): number {
    return isCi ? 1 : 0;
}

export const testPolicy = {
    clearMocks: true,
    retry: resolveVitestRetry(),
    testTimeout: 10_000,
    env: { VITE_APP_URL: '', APP_URL: '' },
};

// Keep generated output and backend dependency trees out of test watchers.
export const testWatchOptions = {
    ignored: [/(?:^|[/\\])(?:\.tmp|\.vitest|\.trivy-cache|vendor|storage|coverage|test-results|playwright-report|blob-report)(?:[/\\]|$)/],
};

export function testViteConfig(): ViteUserConfig {
    const releases: unknown = JSON.parse(readFileSync(new URL('./resources/data/changelog.json', import.meta.url), 'utf8'));
    if (!Array.isArray(releases) || typeof releases[0]?.version !== 'string' || !releases[0].version) {
        throw new Error('The changelog must start with a release version.');
    }
    return {
        // Test reruns remount components and do not need Fast Refresh state.
        plugins: [react({ refreshExclude: /./ })],
        server: { forwardConsole: true, watch: testWatchOptions },
        define: { global: 'globalThis', __ERNIE_VERSION__: JSON.stringify(releases[0].version) },
        resolve: {
            dedupe: ['react', 'react-dom'],
            alias: Object.fromEntries(
                Object.entries({
                    '@': './resources/js',
                    '@data': './resources/data',
                    '@tests': './tests',
                    '@test-helpers': './tests/vitest/test-helpers',
                    '@assistants': './modules/assistants',
                }).map(([alias, path]) => [alias, fileURLToPath(new URL(path, import.meta.url))]),
            ),
        },
    };
}

// Preserve the existing measured source set. Both runtime projects contribute
// to this single report, including files only exercised in the browser.
export const ignoreGeneratedCoverage: NonNullable<CoverageOptions['ignoreNode']> = (node, type) => {
    if (type !== 'statement' || node.type !== 'VariableDeclarator' || node.id.type !== 'Identifier') return false;
    // Vite's generated CJS import bindings correspond to imports, just like the
    // SSR import bindings already filtered by Vitest's built-in ignore rules.
    if (node.init?.type === 'Identifier' && /^__vite__cjsImport\d+_/.test(node.init.name)) return true;
    if (node.init?.type === 'MemberExpression' && node.init.object.type === 'Identifier' && /^__vite__cjsImport\d+_/.test(node.init.object.name))
        return true;
    if (
        node.init?.type === 'ConditionalExpression' &&
        node.init.test.type === 'MemberExpression' &&
        node.init.test.object.type === 'Identifier' &&
        /^__vite__cjsImport\d+_/.test(node.init.test.object.name) &&
        node.init.test.property.type === 'Identifier' &&
        node.init.test.property.name === '__esModule'
    )
        return true;
    return (
        node.id.name === '_jsxFileName' && node.init?.type === 'Literal' && typeof node.init.value === 'string' && /\.[jt]sx$/.test(node.init.value)
    );
};

export const testCoverage = {
    provider: 'v8' as const,
    // Only generated import bindings and the JSX filename, never application logic.
    ignoreNode: ignoreGeneratedCoverage,
    reporter: ['text', 'json-summary', 'lcov'] as ('text' | 'json-summary' | 'lcov')[],
    reportsDirectory: 'coverage',
    include: ['resources/js/**/*.{js,ts,jsx,tsx}'],
    exclude: [
        'tests/vitest/**',
        'resources/js/**/__mocks__/**',
        'resources/js/actions/**',
        'resources/js/routes/**',
        'resources/js/routes.ts',
        'resources/js/app.tsx',
        'resources/js/ssr.tsx',
        'resources/js/swagger.tsx',
        'resources/js/components/ui/**',
        'resources/js/**/*.d.ts',
        'resources/js/types/affiliations.ts',
        'resources/js/types/docs.ts',
        'resources/js/types/portal.ts',
        'resources/js/types/resources.ts',
    ],
};
