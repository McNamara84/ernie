import assert from 'node:assert/strict';
import test from 'node:test';

import { verifyPhpDependencies } from '../../scripts/verify-php-dependencies.mjs';

const application = { name: 'laravel/framework', version: 'v13.34.0', dist: { reference: 'framework-revision' } };
const runner = { name: 'pestphp/pest', version: 'v5.3.0', source: { reference: 'runner-revision' } };
const lock = { packages: [application], 'packages-dev': [runner] };

test('current application and test-runner packages pass with locked versions and references', () => {
    assert.doesNotThrow(() => verifyPhpDependencies(lock, { packages: [runner, application] }));
});

test('stale frameworks, changed revisions, missing test runners, and extra packages fail', () => {
    for (const packages of [
        [{ ...application, version: 'v13.33.0' }, runner],
        [application, { ...runner, source: { reference: 'other-revision' } }],
        [application],
        [application, runner, { name: 'unexpected/package', version: '1.0.0' }],
    ]) {
        assert.throws(() => verifyPhpDependencies(lock, { packages }), /differ from composer.lock/u);
    }
});
