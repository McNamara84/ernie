# Third-party notices for landing-page citations

## citeproc-php

The server-side formatter uses `seboettg/citeproc-php` version 2.7.1, source
commit `a7200e4ac0ec4265442a41aa82fb574d22af21a2`.

- Source: <https://github.com/pkp/citeproc-php>
- Copyright: Sebastian Böttger and contributors
- License: MIT

The authoritative package version and dependency graph are recorded in
`composer.lock`.

## Citation Style Language styles and locales

The five files in `styles/` are unmodified works from the official Citation
Style Language style repository. English locale data is loaded from the
official `citation-style-language/locales` Composer package.

- CSL project: <https://citationstyles.org/>
- Styles: <https://github.com/citation-style-language/styles>
- Locales: <https://github.com/citation-style-language/locales>
- License: Creative Commons Attribution-ShareAlike 3.0 Unported

Individual style and locale files retain their own authorship, translator,
contributor, update, link, and `<rights>` metadata. Exact versions, source
commits and hashes are documented in `README.md`. The complete license text is
included in `LICENSE-CC-BY-SA-3.0.txt`.

The application preserves this attribution in the source distribution and
links users of the citation module to <https://citationstyles.org/>.
