# Accessibility regression tests

`homepage-a11y.spec.ts` covers the public homepage with axe scans in light/dark mode on desktop, tablet and mobile, open navigation, keyboard access and focus, text enlargement, text spacing and Windows high contrast.

Run against the local Docker development stack with the repository wrapper:

```powershell
npm run test:e2e:devstack -- tests/playwright/accessibility/homepage-a11y.spec.ts --reporter=list,html
```

The existing Playwright CI workflow discovers this directory automatically. The homepage's additional functional and responsive tests live in `../critical/homepage.spec.ts`; changelog tests live in `../critical/changelog.spec.ts`.

The axe reports, including `incomplete` results that need manual assessment, are attached to the Playwright report. Passing these tests does not establish legal compliance or replace screenreader and manual accessibility testing.

Windows WebKit skips four Tab-navigation cases because its native Tab cycle omits links even on plain HTML; they remain enabled in Linux CI and the other browsers. The forced-colors case runs in Chromium only. Skips are reported explicitly.

See [Homepage accessibility](../../../docs/homepage-accessibility.md) for the scope, applicable standards, known gaps and remaining manual work.
