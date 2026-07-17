# Pinned Citation Style Language assets

The landing-page citation formatter uses only the five independent CSL styles
listed below. They are stored in this directory so rendering is deterministic
and never requires a runtime request to Zotero, DataCite, DOI.org, or a CDN.

## Styles

- Upstream: <https://github.com/citation-style-language/styles>
- CSL branch: `v1.0.2`
- Pinned commit: `0d8aeb37768766684eb1a8e33b42e0ef62dfa8b6`
- Retrieved: 2026-07-17
- License: CC BY-SA 3.0; each unmodified style retains its original authors,
  contributors, links, update timestamp, and `<rights>` metadata.

| Application ID | File | Locale | Git blob | SHA-256 |
| --- | --- | --- | --- | --- |
| `apa-7` | `styles/apa.csl` | `en-US` | `edd25d724124cfd9abd4a017786d91098a4acc6f` | `17bc430cf931767d551a894129b3a705e1feee91090295c09674e370ccdef5d9` |
| `harvard` | `styles/harvard-cite-them-right.csl` | `en-GB` | `903c6d2dff0195f7acb7b929126379bb49e8e263` | `6053e3448b5e7da4a814f2a8610c1bf29cc5a243c24c9e2e5c3e7cd225230df7` |
| `copernicus` | `styles/copernicus-publications.csl` | `en-US` | `633b25f133b4984cdc8d321be13489ca9142e5ff` | `a0e16fd5f4af5c5043726cdd1b82984d1ac12d8118492c39004cc547352a3bdb` |
| `agu` | `styles/american-geophysical-union.csl` | `en-US` | `99e67015c6932ba0518c3f4c29f4cca12204c5a5` | `2c343e722c03bbda4722edbd234ca0ae21173a3f5088bf239145df433a9a59f7` |
| `gsa` | `styles/the-geological-society-of-america.csl` | `en-US` | `1f58384c966b2a8aa0efe47578efe9ec68e64d10` | `2e0aaf443ae73fd81edaea5a231357e9f231a8a7d4e2632083484434ea6cab6b` |

The Composer dependency also installs the complete CSL styles package because
`citeproc-php` declares it as a dependency. Application code deliberately does
not resolve styles by package name and does not use that package as a fallback;
it passes the absolute path of one of the five files above.

## Locales

Locales are loaded through the public `citeproc-php` API from the official
Composer package `citation-style-language/locales`:

- resolved version: `v0.0.95`
- source commit: `bc0a222b4ca526126faf892c35b2b7b1215c10eb`
- source: <https://github.com/citation-style-language/locales>
- license: CC BY-SA 3.0
- `locales-en-US.xml` SHA-256:
  `ac864c7c21166b4390d82c31792cdc509400727fa0060b43d8aa17e07f9cb079`
- `locales-en-GB.xml` SHA-256:
  `9d5f7e3861890abe4986cd9084d49c02341c5ad8ae6e6f007427048ea146b3bc`

The locale metadata credits Andrew Dunning, Sebastian Karcher and Rintze M.
Zelle for both English locales. The US locale additionally credits Denis Meier
and Brenton M. Wiernik. Their upstream URI metadata is preserved in the
installed locale files.

## PHP 8.5 compatibility gate

The pinned styles were rendered with `seboettg/citeproc-php` 2.7.1 on PHP
8.5.8 using both a complete dataset and a DOI-less physical-object fixture.
Every style produced a non-empty bibliography for both fixtures, and
`renderCssStyles()` completed for all five styles.

A cold Windows CLI run on the development machine took 1,734 ms in total:
APA 752 ms, Harvard 233 ms, Copernicus 233 ms, AGU 306 ms and GSA 209 ms.
These figures are a compatibility measurement, not a production benchmark.
Published landing pages amortize the work through the versioned render cache.

Installed footprint measured during the gate:

| Dependency/asset | Size |
| --- | ---: |
| `seboettg/citeproc-php` | 1.87 MiB |
| `seboettg/collection` | 0.26 MiB |
| complete transitive CSL styles package | 48.21 MiB |
| official CSL locales package | 1.58 MiB |
| five application styles plus notices | 0.16 MiB |

PHP 8.5 reports four upstream implicit-nullability deprecations while loading
the engine. Application integration masks only `E_DEPRECATED` for the narrow
engine call and restores the caller's exact error-reporting level in `finally`.
No application-wide deprecation suppression is installed.

## Controlled updates

To update these assets:

1. Select and record a reviewed commit on the CSL `v1.0.2` branch.
2. Replace only the allowlisted files and update every blob and SHA-256 value.
3. Review style metadata and license changes.
4. Update the locale/engine lock only through Composer.
5. Run the registry, parser, sanitizer, golden-output, PHP, frontend, SSR, and
   end-to-end citation tests before accepting changed output.

