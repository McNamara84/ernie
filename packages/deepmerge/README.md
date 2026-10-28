# Temporary deepmerge security patch

This private package contains the published `deepmerge@4.3.1` CommonJS bundle
(`dist/cjs.js`, renamed to `index.cjs`), its type declarations and MIT license.
The direct file dependency and npm override make Swagger Client use this copy
in Node and Vite. Upstream files retain their original style and are excluded
from application lint rules; the regression tests remain linted.

Upstream has no patched release for
[CVE-2026-93753 / GHSA-gcqx-w48m-w2h4](https://github.com/advisories/GHSA-gcqx-w48m-w2h4)
as of 2026-10-28. The returned object's prototype can be poisoned through
`__proto__` keys in a merge source or target, including null-prototype objects
and nested null or primitive placeholders. Checking only `Object.prototype`
does not detect this bug.

The local change defines own data properties instead of invoking inherited
setters, and only merges with own target properties. It never reads inherited
target values, including `constructor` and `__proto__`. All enumerable own data
keys are preserved, including `__proto__`, `constructor` and `prototype`; these
names are valid in OpenAPI/JSON Schema property maps. Upstream protection of
nonenumerable own target properties remains intact, along with array options,
symbols and custom merges.

`tests/node/deepmerge-security.test.mjs` resolves the dependency through Swagger
Client and covers the attack, inherited accessors, normal merge options and
Swagger's reference and `allOf` resolution with these property names. The
original package fails the attack test. This is a source
fix; the security workflow continues auditing every registry dependency at the
existing severity threshold.

Remove this directory, its Dockerfile copy/cleanup, lint exclusion, direct
dependency and override when Swagger Client can use an upstream release that
passes the same security and compatibility tests. Keep the tests when removing
the override.
