# Funding award URI enrichment

Implements [#774](https://github.com/McNamara84/ernie/issues/774), including source strategy [#787](https://github.com/McNamara84/ernie/issues/787), discovery [#788](https://github.com/McNamara84/ernie/issues/788) and review [#789](https://github.com/McNamara84/ernie/issues/789).

The Assistance page can propose missing award URIs from Crossref Grants and CORDIS. Administrators and Group Leaders review each proposal before it changes a funding reference. Existing nonblank values are always preserved, including invalid values. Discovery never changes resource metadata.

## Sources and matching rules

| Source | Query and authoritative identifier | Limitations |
| --- | --- | --- |
| Crossref Grants | `https://api.crossref.org/works`, filtered by `type:grant`, `award.number` and `award.funder`; canonical `https://doi.org/{grant DOI}` | Only registered grants qualify. Publication DOIs and funding acknowledgements are not grant identifiers. All pages up to 300 results must be checked. |
| CORDIS | Public EURIO SPARQL at `https://cordis.europa.eu/datalab/sparql`; exact project identifier; canonical `https://cordis.europa.eu/project/id/{number}` | Requires one project, one grant and programme evidence consistent with the typed funder. A URL constructed from the number alone is insufficient. |

Crossref documents its [grant metadata API](https://www.crossref.org/blog/come-and-get-your-grant-metadata/) and [REST API](https://api.crossref.org/swagger-ui/index.html). CORDIS documents its [SPARQL interface](https://cordis.europa.eu/about/sparql) and [reuse terms](https://cordis.europa.eu/about/legal/en). Neither adapter needs an API key. Set `CROSSREF_MAILTO` to a monitored contact address. No free-text web search, HTML scraping, GEPRIS, AI matching or arbitrary URL retrieval is used.

Eligibility requires:

- An empty URI, a usable award number and a typed funder identifier. Trimming and Unicode NFC are allowed; leading zeros, punctuation and case in award numbers are preserved. Placeholder values and compound numbers separated by spaces/commas are skipped.
- An exact Crossref Funder ID, or an active ROR funder with a unique FundRef mapping in the local `ror/ror-fundref-index.json`, retrieved within 180 days. Names alone never establish identity. Missing or ambiguous mappings are skipped.
- Exact award number and funder evidence. A nonblank local title must match a source title after whitespace/case normalization. A title cannot override conflicting identifiers.
- A single candidate across all applicable sources. Different URIs remain ambiguous unless a provider explicitly links the same award; in that case the grant DOI is preferred and both evidence records are retained.
- No duplicate local funding identity in the same resource. The identity consists of canonical funder, exact award number and normalized title, independent of transient database row IDs.

The CORDIS funder/programme allowlist is in `config/funding_award_uri.php`: European Commission (`10.13039/501100000780`), ERC (`10.13039/501100000781`) and Horizon 2020 (`10.13039/100010661`). Programme prefixes constrain ERC and Horizon 2020 to their respective programmes. Unknown funders or missing programme evidence yield no CORDIS proposal. The list is deliberately conservative; extend it only with verified funder/programme evidence and fixtures.

## Discovery and review

The `FundingAwardUriEnrichment` module uses the existing Assistance discovery queue and lock. Its report distinguishes checked references, new/updated suggestions, skipped reasons, removed stale suggestions, source failures and partial completion. A partial run resumes after its last completely processed resource; a partly processed resource is safely rechecked. Cached queries and suggestion identities make rechecks idempotent.

Requests use fixed HTTPS hosts, no redirects, 5-second connection and 20-second request timeouts, at most three attempts for transport failures/429/5xx, bounded backoff and `Retry-After`. A shared per-provider cache lock serializes requests with a one-second minimum interval. Long cooldowns become visible source failures rather than blocking the worker indefinitely. Defaults are configurable in `config/funding_award_uri.php`.

Successful evidence is cached for 24 hours, complete empty answers for one hour. Errors are never cached as empty answers. Evidence keeps its original retrieval time and expires after seven days. An incomplete source check blocks acceptance of an earlier proposal until a complete recheck. The scan stops starting new lookups after its five-minute budget; the current bounded lookup can finish before the resource is resumed.

The preview displays local funding fields, current/proposed URI, confidence, source records, evidence and retrieval times. Individual and resource-scoped batch actions submit the displayed review fingerprint. The server independently checks that fingerprint, current local fields, funder identity, uniqueness, source status, expiry, rules version and resource editability. Client-supplied reviewer IDs are ignored.

Acceptance locks the resource, suggestion and funding reference, then writes only `award_uri`, a permanent review and suggestion removal in one transaction. It updates the resource datestamp and uses existing resource/cache observers plus OAI-PMH invalidation. A concurrent editor value or a second review cannot overwrite metadata or create a second decision. Source lookups and DataCite synchronization run outside the write transaction. DataCite sync failures leave the accepted local value intact and expose the existing retry action; a batch syncs once per resource.

Editor saves invalidate pending award previews before replacing funding rows. Discovery also removes orphaned or changed previews. Declines are remembered by resource, stable funding identity and proposed URI, so recreating an equivalent funding row does not resurrect a declined proposal.

## Permanent evidence and data minimization

`funding_award_uri_reviews` stores accepted and declined decisions, old/new/proposed values, source evidence, confidence/ambiguity, rules and review fingerprints, local funding/resource snapshots, reviewer snapshot and review time. Live user/resource foreign keys become null on deletion; historical IDs and snapshots survive. The model disallows updating or deleting reviews through normal model operations.

Evidence contains only the identifiers, project titles, funder/programme identifiers, timestamps, canonical source links and reuse attribution needed for review. Full API bodies, abstracts, investigators, emails and unrelated person data are not archived. Crossref metadata reuse and CORDIS attribution links appear with the evidence. The archive is internal and is not added to public DataCite metadata.

## Deployment and rollback

1. Deploy the migration and code, run migrations, and rebuild assets using the normal deployment process.
2. Start with `FUNDING_AWARD_URI_ENABLED=false` (the default). The assistant reports that it is disabled and does not query sources or accept reviews.
3. Configure `CROSSREF_MAILTO`; refresh the existing ROR/FundRef index if ROR inputs are needed. Use a shared lock-capable cache such as Redis and the configured Assistance worker.
4. Enable `FUNDING_AWARD_URI_ENABLED=true` for the web application and Assistance worker, refresh configuration and restart long-lived workers. All three Compose deployments pass the feature flag to the application and workers. Set it in `.env.docker` for development; Stage and Production also forward `CROSSREF_MAILTO`.
5. Run Check and review representative Crossref and CORDIS proposals. Inspect partial/source error counts before widening use.

Operational rollback is disabling the flag and restarting workers. Keep the review tables and already accepted values. Do not roll back the migration to disable the feature: its `down()` method drops the archive and is only appropriate for disposable test databases.

## Verification

Use the exact `.node-version` and the repository wrappers:

```sh
npm run test:php -- tests/pest/Unit/Services/FundingAwardUri tests/pest/Feature/Services/FundingAwardUri
npm run test:php:mysql-sensitive:assistance
npm run test:run -- tests/vitest/components/funding-award-uri.test.tsx
npm run build
npm run test:php -- tests/pest/Browser/FundingAwardUriAssistanceTest.php
npm run check:backend
npm run check:frontend
```

Pest Browser needs Chromium and its runtime libraries installed in the PHP execution environment, as well as built assets. The CI Playwright Chromium job runs these browser scenarios explicitly. Source tests use recorded response shapes and HTTP fakes, never live providers. MySQL-specific tests use the pinned MySQL 9.7 stack and independent connections to verify competing reviews/editor writes. PHPStan and coverage include the new module.
