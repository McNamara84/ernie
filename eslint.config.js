import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import reactHooks from 'eslint-plugin-react-hooks';
import simpleImportSort from 'eslint-plugin-simple-import-sort';
import globals from 'globals';
import typescript from 'typescript-eslint';

/** @type {import('eslint').Linter.Config[]} */
export default [
    js.configs.recommended,
    ...typescript.configs.recommended,
    {
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
    },
    {
        plugins: {
            'react-hooks': reactHooks,
        },
        rules: {
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    {
        plugins: {
            'simple-import-sort': simpleImportSort,
        },
        rules: {
            'simple-import-sort/imports': 'error',
            'simple-import-sort/exports': 'error',
        },
    },
    {
        files: ['scripts/**/*.mjs'],
        languageOptions: {
            globals: {
                ...globals.node,
            },
        },
    },
    {
        ignores: [
            'vendor',
            'node_modules',
            'public',
            'resources/js/actions/**',
            'resources/js/routes/**',
            'bootstrap/ssr',
            'tailwind.config.js',
            'playwright-report',
            'playwright-report-stage',
            'playwright-report-stage-local',
            'test-results',
            'coverage',
        ],
    },
    prettier, // Turn off all rules that might conflict with Prettier
];
