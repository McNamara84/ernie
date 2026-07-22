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
});
