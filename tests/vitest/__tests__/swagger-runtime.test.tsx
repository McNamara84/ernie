import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';

import { fromJS, isCollection, isKeyed, List, Map } from 'immutable';
import SwaggerUI from 'swagger-ui-react';
import { describe, expect, it } from 'vitest';

describe('Swagger UI runtime dependencies', () => {
    it('loads with the patched Immutable.js API expected by Swagger UI', () => {
        expect(SwaggerUI).toBeTypeOf('function');
        expect(isCollection(List())).toBe(true);
        expect(isKeyed(Map())).toBe(true);
        expect(fromJS({ paths: {} }).get('paths')).toEqual(Map());
    });

    it('keeps Remarkable markdown rendering compatible with the safe Argparse override', () => {
        const require = createRequire(import.meta.url);
        const result = spawnSync(process.execPath, [require.resolve('remarkable/bin/remarkable.js')], {
            input: '# API documentation\n\nUse **JSON** responses.\n',
            encoding: 'utf8',
            timeout: 10_000,
        });

        expect(result.error).toBeUndefined();
        expect(result.status).toBe(0);
        expect(result.stdout).toContain('<h1>API documentation</h1>');
        expect(result.stdout).toContain('<strong>JSON</strong>');
    });
});
