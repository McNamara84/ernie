# Native AbortController compatibility exports

`swagger-client@3.39.0` still depends on the deprecated `node-abort-controller`
polyfill. This private adapter preserves its two CommonJS named exports while
using the platform implementations already available in ERNIE's required Node
26.13.1 runtime and supported browsers. It contains no copied upstream code.

The adapter is a private npm workspace whose package name and compatibility
version match Swagger Client's dependency. The root dependency selects that
version, so npm links the workspace from the project root without interpreting
a relative `file:` override inside Swagger Client. `private: true` prevents publishing.
Remove this adapter, workspace entry and root dependency when Swagger Client removes
that dependency. Regression tests reject dangling nested links, verify resolution
to the workspace, and check the actual Swagger exports and native fetch cancellation.
