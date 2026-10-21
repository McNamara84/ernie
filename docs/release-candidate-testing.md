# Release Candidate Testing

Stage follows `dev`. A Production release is tested separately using the exact
images recorded for its `main` commit. This temporary Docker Compose stack can
run on a workstation or test host; it does not need a permanent additional server.

## 1. Select and prepare the candidate

Use the Node version in `.node-version`, Docker Compose, and a checkout with
GitHub as `origin`. For private GHCR packages, authenticate Docker with a token
that can read all three ERNIE packages. Never put registry credentials in Compose.

Finalize the stable version in the first changelog entry through a reviewed PR
into `main`. Wait for all six push checks and **Publish Validated Images** for
that exact commit. Record its full SHA; it may be an older ancestor of `main`.

```powershell
git fetch origin main artifacts/main
$releaseSha = '<full 40-character SHA from the reviewed release PR>'
npm run release:prepare -- $releaseSha
```

The helper reads `<SHA>.json` from `origin/artifacts/main` and the Production
Compose template from that exact source commit. It checks repository identity
and `main` ancestry, then writes these files under `.release-candidate/<SHA>/`:

- `artifact.json`: source SHA, version, all three immutable image references,
  and the artifact-record commit;
- `compose.json`: the isolated runtime configuration;
- `.env`: newly generated application, database, and F-UJI credentials.

This directory is excluded from Git, Docker build contexts, and the backend test
workspace. Repeating preparation preserves its credentials and test database.
The source record is immutable: a retry reuses its digests. Fixing an image
requires a new reviewed source commit and a new candidate.

If the record is missing, rerun a successful deployment-blocking **push** workflow
for the selected SHA on `main`. Its completion retries image publication, even
if `main` has advanced. Wait for the record, fetch again, and repeat preparation.
An old release from before the artifact workflow has no automatic Stage fallback.

### GitGuardian reports a candidate password placeholder

The `RELEASE_DB_PASSWORD` references in `scripts/prepare-release-candidate.mjs`
are Docker Compose interpolation expressions. The helper generates the actual
password with `randomBytes` and stores it in the ignored candidate `.env`.
The inline `ggignore` comments apply only to these literal variable references.

If GitGuardian has already reported one of these references in a PR, inspect the
finding and choose **Skip: false positive** in the GitGuardian check, if available,
or ignore the corresponding dashboard incident as a false positive. Rerun the
check if it still shows the old result. A follow-up commit alone does not clear
the finding: [GitGuardian scans every commit in the PR](https://docs.gitguardian.com/internal-monitoring/prevent/detect-secrets-in-real-time-in-github).
Keep this exception limited to the verified placeholder; actual credentials must
not be committed or covered by these comments.

## 2. Start the isolated stack

In the same PowerShell session:

```powershell
$candidateDirectory = Join-Path '.release-candidate' $releaseSha
$candidateProject = 'ernie-release-' + $releaseSha.Substring(0, 12)
$candidateCompose = @('compose', '-p', $candidateProject, '--env-file', "$candidateDirectory/.env", '-f', "$candidateDirectory/compose.json")
docker @candidateCompose up -d --wait
docker @candidateCompose ps
```

The default test URL is **http://localhost:8181**. The port binds only to loopback.
On a remote test host, run the same preparation there and use an SSH tunnel for
browser access, for example `ssh -L 8181:127.0.0.1:8181 user@test-host`. To change
the local port, set both `RELEASE_PORT` and `RELEASE_APP_URL` in the candidate's
`.env` before starting; adjust `RELEASE_SANCTUM_STATEFUL_DOMAINS` as well.

Each candidate has its own Compose project, network, database/storage volumes,
Redis, and all ten application/runtime services. The explicit `-p` argument
prevents a host's `COMPOSE_PROJECT_NAME` from selecting another stack. Containers
have no Production names, Traefik labels, external networks, or host bind mounts.
MySQL uses the pinned **9.7** image from the candidate's Production template,
matching the development/test server line. Never attach an older MySQL volume.

Settings are prefixed with `RELEASE_` to avoid inheriting live deployment
credentials. Database and F-UJI passwords are separate. Mail goes to logs;
DataCite is fixed to the test endpoint and test mode; legacy database access,
ELMO refresh, host metrics, and public traffic sampling are disabled. For tests
that register DOIs or access a DataCite test repository, configure
`RELEASE_DATACITE_TEST_USERNAME`, `RELEASE_DATACITE_TEST_PASSWORD`, and
`RELEASE_DATACITE_TEST_CLIENT_ID` in this `.env` using a dedicated test account.
The helper forwards them as `DATACITE_TEST_*` to the app and all its workers;
the client ID identifies the test repository used for repository operations.
Production DataCite credentials remain empty. The old
`RELEASE_DATACITE_USERNAME`/`RELEASE_DATACITE_PASSWORD` names are not used.
Document unavailable integrations as blocked or not applicable in the report.

Use synthetic test data and dedicated accounts. The application entrypoint runs
migrations. For a new empty candidate database, seed the lookup tables and create
the first test administrator in this stack:

```powershell
docker @candidateCompose exec app php artisan db:seed --force
docker @candidateCompose exec app php artisan add-user "Release Test Admin" admin@example.invalid "<unique test-only password>"
```

Replace the password placeholder before running. The first user becomes an
administrator; later users start as beginners. Never reuse Production passwords. For migration
regression, prepare a separate candidate database with an approved sanitized
fixture representing the current Production schema **before** starting the app
and its migrations. Record the fixture version and migration results. A fresh
database alone does not test upgrades from the currently deployed release.

## 3. Verify and test

```powershell
npm run release:verify -- $releaseSha
```

Verification requires all ten services to be running, every declared health
check to be healthy, all application/Nginx/F-UJI services to reference the
recorded digests, and `/health` to respond successfully. It checks the candidate
project and writes `.release-candidate/<SHA>/verification.json`. Confirm the
displayed version too. This runtime check does not replace functional testing.

Run the smoke test and full regression from
[Pre-Release Testing](pre-release-testing.md) against this candidate URL. In the
test report record the URL, SHA, version, three image references, verification
time, test results, and the release Go/No-Go decision. Stage can continue testing
the next minor release independently.

## 4. Publish the tested images

Before publishing, compare the candidate SHA and all three digests in its
immutable `artifacts/main` record with the test report. Follow
[Branching and Releases](branching-and-releases.md#7-publish-v111) to tag the tested
SHA and publish its stable GitHub release. Publishing is the Production release
authorization: **Promote Production Release** validates the candidate and promotes
those recorded images automatically, without rebuilding or an additional approval
in Actions. Its `inspect` summary records the selected SHA and image digests.

If code, images, version, or test results differ, do not publish. Prepare and test
a new candidate through the normal PR process. Do not move an existing release tag.

## 5. Stop the test stack

```powershell
docker @candidateCompose down
```

This preserves the candidate's test volumes and credentials for further checks.
Keep the report with the release evidence. Remove candidate data only when it is
no longer needed, after checking the exact Compose project and volume names.
