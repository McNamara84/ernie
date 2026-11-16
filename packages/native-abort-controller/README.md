# Native AbortController compatibility exports

`swagger-client@3.39.0` still depends on the deprecated `node-abort-controller`
polyfill. This private adapter preserves its two CommonJS named exports while
using the platform implementations already available in ERNIE's required Node
26.13.1 runtime and supported browsers. It contains no copied upstream code.

The direct file dependency and npm override apply to Swagger Client's transitive dependency. Remove this
adapter and its override when Swagger Client removes that dependency. Regression
tests check the actual Swagger ponyfill exports and native fetch cancellation.
