# Production deployment from validated main artifacts

Production does not build ERNIE images on its Portainer host. A published
stable GitHub release promotes the exact application, Nginx, and F-UJI image digests
recorded for the tested `main` commit and creates a machine-managed
`deploy/prod` commit. Portainer follows that branch and therefore deploys only
immutable, release-approved images.

## Deployment flow

1. A normal pull request is merged into `main`.
2. The Security, Pest, MySQL, Vitest, lint/PHPStan, and Playwright workflows validate
   the `main` commit.
3. `Publish Validated Images` builds and scans the application, Nginx, and F-UJI
   images, then records their exact digests and application version in
   `<source-sha>.json` on `artifacts/main`. Repeated publication reuses that record.
   Stage independently follows `dev` through `deploy/stage`.
4. Test these exact images in the [isolated release stack](release-candidate-testing.md),
   record the candidate SHA and digests, and obtain the release Go/No-Go decision.
5. Publish a stable GitHub release for that fixed commit to authorize Production
   deployment. The read-only `Production Release Signal` workflow records the event.
6. `Promote Production Release`, loaded from the default branch through
   `workflow_run`, verifies the release and exact `main` CI results in `validate`.
7. Its read-only `inspect` job reads the immutable `artifacts/main` record,
   requires its version to match the release tag, verifies all three image
   manifests, and scans them again for HIGH and CRITICAL vulnerabilities.
8. After those checks pass, `promote` receives repository write access, verifies
   that the release is still the latest and highest stable release, writes those
   same digests into `docker-compose.prod.yml`, and advances `deploy/prod` with a
   compare-and-swap push.
9. Portainer detects the changed deployment commit and recreates Production
   from the tested images. No additional approval in Actions is required.

No developer works on or merges into `deploy/prod`. Its commits record the
release tag and source SHA, retain the validated source commit as a parent,
and form a linear deployment history. The generated Compose file references
the application, Nginx, and F-UJI images as `ghcr.io/...@sha256:<digest>`; it never deploys from a mutable
`latest`, `production`, or version tag.

## Laravel process users and log permissions

The PHP entrypoint performs filesystem/FPM setup as root, then runs all startup
Artisan commands and the queue/scheduler commands as `www-data`. The PHP-FPM
master retains root; its request workers run as `www-data`. The storage link is
created during filesystem setup, so `public/` does not need to become writable
by the web user.
Setup rejects a symbolic link at `public/` before creating or replacing the
storage link. Docker build contexts exclude `.env-key.*` files left by interrupted
development key generation.

Stage and Production require one non-empty `APP_KEY` in the Portainer stack
environment, shared by app, all queue services and scheduler. Compose rejects
missing or empty values, and the PHP entrypoint independently refuses startup
before changing files or running Artisan. The image's `.env` is a template;
containers do not generate their own fallback keys. Retain the existing key
when redeploying so encrypted data remains readable.

For a new installation only, generate one key on a workstation with Node.js:

```bash
node -e "console.log('base64:' + require('node:crypto').randomBytes(32).toString('base64'))"
```

Store that value as `APP_KEY` in the stack environment before deployment. Stage
and Production each use their own key. CI validates Compose templates with a
non-secret placeholder scoped to the validation command; it is never written
to the deployment file.

Startup recursively repairs only `storage/framework`, `storage/logs` and
`bootstrap/cache`. It prepares the `storage`, `storage/app`, `storage/app/private`
and `storage/app/public` directories without traversing uploaded data. Any
required permission migration for imported data is a separate, one-time
administrative task, rather than work repeated by every container restart.
Startup rejects symbolic links at the managed runtime directories and their
parents before changing files. Recursive permission repair does not follow
links nested inside the runtime directories.
`storage/logs` has group `www-data` and mode `2775`; Laravel's single/daily log
files use `0664`. New daily files therefore remain writable by web workers even
if a root CLI invocation creates the file first. Recreating app, all queue services and scheduler with
the corrected image applies this to the shared storage volume. Merely
restarting the old image or changing the service's Compose `user` is insufficient.

Run manual commands through the application identity on the Docker host:

```bash
docker exec --user www-data --env XDG_CONFIG_HOME=/var/www/html/storage/framework/.config --workdir /var/www/html dockerized-laravel-app php artisan list
```

For Portainer consoles, select `www-data`, or run `su -s /bin/sh www-data` from
the root console. For Tinker, set
`export XDG_CONFIG_HOME=/var/www/html/storage/framework/.config` in that shell.
After deployment, check `stat -c '%U:%G %a %n' storage/logs`
in the app container: expect `www-data:www-data 2775`. Check the actual worker
processes with `docker top dockerized-laravel-queue -eo user,pid,args` and
`docker top dockerized-laravel-scheduler -eo user,pid,args` on the Docker host;
their Laravel processes must run as `www-data`. A plain `docker exec ... id`
without `--user` still reports the image's setup identity and does not inspect
the running worker. Verify that the next UTC day's log is writable by
`www-data` and that both web and background activity reach it.

## Public page routing and feature switches

ERNIE handles the public `/`, `/find`, `/data-centres`, and
`/data-centres/description` routes on `dataservices.gfz.de`. Each page has its own
environment switch applied when the app container is deployed:

| Portainer stack variable | ERNIE route | TYPO3 target when `false` |
| --- | --- | --- |
| `PUBLIC_HOME_ENABLED` | `/` | `https://dataservices.gfz-potsdam.de/` |
| `PUBLIC_FIND_ENABLED` | `/find` | `https://dataservices.gfz-potsdam.de/web/find` |
| `PUBLIC_DATA_CENTRES_ENABLED` | `/data-centres` | `https://dataservices.gfz-potsdam.de/web/find/data-centres` |
| `PUBLIC_DATA_CENTRE_DESCRIPTION_ENABLED` | `/data-centres/description` | `https://dataservices.gfz-potsdam.de/web/find/data-centres/data-centre-description` |

Set each variable to the literal `true` or `false`. `true` serves the ERNIE page
with HTTP 200; `false` returns a temporary HTTP 302 redirect to the corresponding
TYPO3 page. Inertia navigation uses an HTTP 409 location response to perform the
same full browser navigation. The switches apply to guests and signed-in users
equally and are independent: disabling the overview does not disable the
description page, or vice versa. Other pages and both search portals remain
available.

Production Compose defaults all four switches to `false`. Stage and development
Compose default them to `true`; the image's `.env.production` template keeps
them disabled unless the runtime environment enables them. Configure overrides
in **Stacks → your stack → Edit stack settings → Environment variables**, save,
and redeploy the stack. A restart of the existing container does not apply
changed stack variables. After the feature is deployed once, switching values
requires no image rebuild. The container entrypoint rebuilds Laravel's
configuration and route caches during startup.

Production Traefik labels permanently redirect GET requests for `/portal` and `/igsn-new`
to `https://dataservices.gfz.de/doi-search` and
`https://dataservices.gfz.de/igsn-search`, respectively, with HTTP 301. Both
redirects include the trailing-slash variants and all subpaths, discarding the
old subpath and every query parameter to open the corresponding search start
page. Similar names such as `/portals` and `/igsn-newer` are not captured.
These search segments are excluded from the legacy router; other confirmed
legacy segments such as `/web/` continue to redirect temporarily to
`dataservices.gfz-potsdam.de`. `/elmo` and `/elmo-msl` remain owned by their
separate stacks.

When releasing the homepage, deploy both the application and the updated
`webserver` service definition so Traefik receives the new labels. Updating only
the application image leaves the old root redirect active. Verify `/` returns
the expected response for `PUBLIC_HOME_ENABLED`: HTTP 200 with local topic
images when `true`, or HTTP 302 to the TYPO3 homepage when `false`. Also verify
that `/find` and each data-centre route follow their individual switches,
`/doi-search` and `/igsn-search` remain available, and legacy URLs still redirect
correctly.

Verify the search redirects with GET requests, including both base paths,
trailing slashes, nested paths and query parameters. For example:

```bash
curl --silent --show-error --dump-header - --output /dev/null 'https://dataservices.gfz.de/portal/index.php?q=granite'
curl --silent --show-error --dump-header - --output /dev/null 'https://dataservices.gfz.de/igsn-new/portal/results?page=2'
```

Expect HTTP 301 and the exact absolute search URL in `Location`, without a
trailing slash, subpath or query. Each redirect must reach its search page in
one step, and both search pages must return HTTP 200. Use GET rather than
`curl -I`: Traefik's permanent redirect middleware returns HTTP 308 for HEAD
and other non-GET methods. Deploy the updated `webserver` definition to apply
the labels; changing only the application image does not apply routing changes.

The initial news item lives in `resources/js/data/homepage.ts`. Administration
through `/manage-news` is a separate future feature. Homepage topic definitions
live in `App\Enums\ScienceTopic`; GCMD topics resolve against the current local
Science Keywords vocabulary, preserving alternative matching branches. A missing
or disabled vocabulary produces no matches rather than an unfiltered result set.

## Release eligibility

### Promotion requirements

Automatic Production promotion requires all of the following:

- the GitHub release was published and is neither a draft nor a prerelease;
- GitHub reports it as the latest release;
- its tag has the strict stable format `vMAJOR.MINOR.PATCH` without leading
  zeroes;
- its numeric version is the highest published stable semantic version, even
  if GitHub's mutable latest designation points to an older release;
- its version is not lower than the release recorded by the current
  `deploy/prod` head, including when that deployed release was later deleted
  from GitHub;
- the tag resolves to a commit contained in `main`;
- all six deployment-blocking push workflows succeeded for that exact
  commit;
- `artifacts/main` contains a validated record for that exact source SHA;
- the recorded application version equals the stable release version;
- all three recorded image manifests still exist and pass the new digest scan;
- the release remains latest immediately before `deploy/prod` is updated.

Publishing a prerelease, an older release, or a release for an unvalidated or
unrecorded candidate commit cannot change `deploy/prod`. The workflow compares
numeric semantic versions rather than trusting GitHub's mutable latest label
and repeats the deployed-version guard before creating the compare-and-swap
update. Deleting or reclassifying a release also does not automatically roll
Production back; rollback remains an explicit operational action.

The release event is intentionally separated from the privileged promotion.
A release workflow runs in the context of its tag, whereas the
`workflow_run` promotion is loaded from the default branch. The promotion
does not trust the signal workflow's code or artifacts: its read-only jobs
query the latest release, resolve the tag, check `main` and all CI results,
inspect the immutable main artifact record, and rescan all three images before a
separate job receives write access.

Neither workflow offers `workflow_dispatch`. This prevents a branch copy of a
write-capable workflow from being dispatched manually.

## One-time rollout

For an existing `deploy/prod` installation, keep its Portainer settings and
volumes unchanged. Merge the workflow changes through the normal reviewed PR
process; no `production` environment setup is required. An existing environment
is not referenced by this workflow and can remain in place. The current
Production deployment stays active during the transition. Old releases without
`artifacts/main` records cannot be promoted through the new workflow. Do not
rebuild or retag an old release to bypass candidate testing.

For a first deployment, perform the following steps in order. `main` contains
unpublished `deployment-template` markers and is never a direct deployment target.

### 1. Pause the current Production auto-update

Before merging this implementation, temporarily disable Git polling or
auto-fetch for the existing Production stack. Do not delete the stack,
containers, named volumes, or database volume. Record or export the existing
stack environment variables before editing its Git settings.

Production needs outbound HTTPS access to the Git repository, `ghcr.io`, and
the GHCR authentication endpoint when the packages are private. No inbound
connection from GitHub to the Production Portainer server is required.

### 2. Test the intended release commit

Merge the implementation through the normal pull-request process. Publishing
the tested stable GitHub release is the Production authorization; there is no
separate environment approval. See
[Branching and Releases](branching-and-releases.md#3-one-time-preparation) for the
full rollout setup.

Wait until all six validation workflows and `Publish Validated Images` succeed
for the exact release commit. Finalize the first changelog entry to the stable
version before building. Test that candidate using
[Release Candidate Testing](release-candidate-testing.md). Stage may show a
newer minor version and is not evidence that the `main` candidate was tested.

The release tag must point to a commit that already contains these Production
workflows. An older tag such as `v1.0.8` does not bootstrap the new process;
the first new published stable release does.

### 3. Publish the stable GitHub release

Create or publish the release using a strict stable tag such as `v1.0.9`:

- target the exact tested `main` commit;
- leave **Set as a pre-release** disabled;
- ensure GitHub marks it as the latest release;
- publish the release only after the tag, title, notes, and target commit are
  correct.

Wait for:

1. `Production Release Signal` to finish successfully;
2. `Promote Production Release` to finish successfully with `validate`,
   `inspect`, and `promote` executed rather than skipped.

The following inspection and promotion steps must be green:

- `Resolve the exact main image artifacts`;
- `Scan the exact promoted image digests`;
- `Confirm that the release is still latest`;
- `Create the digest-pinned Production deployment commit`;
- `Advance the digest-pinned Production deployment branch`.

Afterwards, `deploy/prod` must exist and its newest commit message must look
like `Deploy Production v1.0.9 from <source-sha>`.

### 4. Change the existing Portainer Production stack

Edit the existing stack; do not delete or recreate it:

- repository: unchanged;
- repository reference: `refs/heads/deploy/prod`;
- Compose path: `docker-compose.prod.yml`;
- existing stack environment variables: retain them unchanged;
- `ERNIE_PROD_APP_IMAGE`, `ERNIE_PROD_FUJI_IMAGE`, and
  `ERNIE_PROD_NGINX_IMAGE`: leave all three unset during normal operation;
  remove stale values because these variables override the
  release-managed digests and are reserved for an explicit rollback;
- registry: reuse the GHCR registry already configured for Stage when the
  packages are private;
- automatic polling: leave it disabled for the first manual deployment;
- re-pull images: enable it when the installed Portainer version exposes the
  option.

The Compose file also sets `pull_policy: always`. Its generated image
references are immutable digests, so every actual stack update requests the
exact promoted manifests.

The portal basemap uses the keyless OpenFreeMap public instance by default. No account, API key, or additional Production variable is required. To use a compatible self-hosted instance instead, configure its style URL in the Production stack environment:

```dotenv
PORTAL_MAP_BASEMAP_STYLE_URL=https://maps.example.org/styles/liberty
```

The default is `https://tiles.openfreemap.org/styles/liberty`. OpenFreeMap does not provide an SLA; keep the default for ordinary use or self-host when operational guarantees are required.

### 5. Verify the first deployment

Save the changes and manually select **Pull and redeploy** for the stack.
Portainer must show:

- `app`, `queue`, `elmo-cache-refresh`, `assessment-queue`, and `scheduler`:
  `ghcr.io/mcnamara84/ernie-app@sha256:<digest>`;
- `webserver`: `ghcr.io/mcnamara84/ernie-nginx@sha256:<digest>`;
- `fuji`: `ghcr.io/mcnamara84/ernie-fuji@sha256:<digest>`.

Compare all three digests with the promoted references in the same
`deploy/prod` commit before declaring the deployment complete.

There must be no local ERNIE Docker build in the deployment log. Verify that:

- `app`, MySQL, Redis, and F-UJI become healthy;
- all queue and scheduler containers remain running;
- `https://dataservices.gfz.de/health` succeeds;
- the expected database migration ran once in the `app` container;
- the public application and background processing work normally;
- CPU, RAM, disk space, and container restart counts remain stable.

After this verification, re-enable the existing Portainer polling mechanism.
Further `dev` commits update Stage. A `main` build only records candidate images;
Production changes when an eligible latest stable release is published and its
automatic promotion checks pass.

## Normal release operation

For each later Production release:

1. merge reviewed changes into `main`;
2. wait for all CI validation and the immutable `artifacts/main` image record;
3. test the exact candidate in the isolated release stack and obtain Go/No-Go;
4. tag that fixed SHA and publish the next stable GitHub release;
5. watch the automatic validation, image inspection, and promotion;
6. verify the automatic Portainer deployment and health checks.

If a newer stable release appears while an older promotion is running, the
concurrency guard cancels the older run. The final latest-release check and
compare-and-swap branch update prevent a superseded run from overwriting a
newer deployment. Every deployment commit remains internally consistent
because all image references are immutable digests from one main candidate record.

## Manual retry

For a transient failure, use GitHub Actions' **Re-run all jobs** on the failed
`Promote Production Release` run. Re-running `Production Release Signal` also
causes a new promotion attempt after it completes. Every retry independently
revalidates the current latest stable release, CI results, main artifact record,
image manifests, and vulnerability scan.

Do not add a manual dispatch trigger to the privileged workflow and do not
create another release merely to retry an infrastructure failure.

## Rollback

Every `deploy/prod` commit records a release tag and immutable image digests. To
restore a previous deployment, read all references from the corresponding
deployment commit and set these stack variables in Portainer:

- `ERNIE_PROD_APP_IMAGE=ghcr.io/mcnamara84/ernie-app@sha256:<good-digest>`
- `ERNIE_PROD_FUJI_IMAGE=ghcr.io/mcnamara84/ernie-fuji@sha256:<good-digest>`
- `ERNIE_PROD_NGINX_IMAGE=ghcr.io/mcnamara84/ernie-nginx@sha256:<good-digest>`

Use all three digests from the same Production deployment commit, then manually
pull/redeploy. The overrides persist across automatic Git updates; remove all three
variables together to return to the release-managed `deploy/prod` branch.

Database migrations are not automatically reversible. Check schema and data
compatibility before rolling application images back across a migration.

## Troubleshooting

### Production Release Signal does not run

Confirm that the release was published after these workflows reached `main`
and that its tag points to a commit containing the signal workflow. Merely
creating a Git tag or saving a draft release is not sufficient.

### The promote job is skipped

Open the `validate` job summary. Confirm that the signal came from a release,
the release is GitHub's current latest stable `vMAJOR.MINOR.PATCH` release, and
all six push workflows succeeded for the tagged commit on `main`.

### No main image artifact record exists for the release source

Wait for successful publication of that exact SHA on `main`. Rerun one of its
successful deployment-blocking push workflows to trigger publication again,
even if `main` has since advanced. Then rerun promotion after testing and
verifying those recorded images. Do not move an existing tag. There is no
fallback to Stage images or mutable image tags.

### Image inspection or the digest scan fails

Confirm that all three GHCR packages (`ernie-app`, `ernie-nginx`, and
`ernie-fuji`) still contain the digests referenced by the matching
`artifacts/main` record. Check repository access to each package and inspect the
vulnerability report for each image, including F-UJI. Promotion deliberately
stops if any image is missing or has a fixable HIGH/CRITICAL finding.

If F-UJI fails the scan, update the affected dependencies through the normal
pull-request, candidate validation, and stable release process. Re-running the
promotion with the same vulnerable digest does not fix the finding.

### Portainer reports unauthorized or manifest unknown

For private packages, verify the existing Portainer custom registry for
`ghcr.io`, the GitHub username, a classic token with `read:packages`, package
access to all three packages (`ernie-app`, `ernie-nginx`, and `ernie-fuji`),
and registry selection on the Production stack. Confirm that each promoted
digest still exists; successful app and Nginx pulls do not verify F-UJI access.

### Portainer sees the commit but keeps the old image

Confirm that the stack follows `refs/heads/deploy/prod` and uses
`docker-compose.prod.yml`. Remove stale rollback overrides if Production should
return to automatic promotion, then manually pull/redeploy once to inspect the
registry response.


## ELMO cache refresh worker

The `elmo-cache-refresh` service runs from the same pinned application image as
`queue`, using the dedicated persistent `elmo` connection and
`elmo-cache-refresh` queue. The defaults are `https://dataservices.gfz.de/elmo` and
`https://dataservices.gfz.de/elmo-msl`; Stage also uses `APP_ENV=production`, so target URLs come from
the Compose configuration rather than the Laravel environment name.

Provide `ELMO_API_KEY` and `ELMO_MSL_API_KEY` through the stack environment, matching
the keys of these ELMO instances. `ELMO_BASE_URL` and `ELMO_MSL_BASE_URL` optionally
override the addresses. Deploy the matching authenticated ELMO admin endpoint
before explicitly setting `ELMO_CACHE_REFRESH_ENABLED=true`. Automatic refresh is
disabled by default, including when credentials have already been provisioned.
Keep `ELMO_MSL_CACHE_REFRESH_READY=false` until the MSL instance uses the correct
ERNIE ELMO-MSL source routes; only then set it to `true`. Both switches are required
for MSL refreshes and are rechecked for queued jobs. Turning either switch off
skips the affected dispatch/execution without blocking settings saves.

The inherited queue environment is shared with the web app and data-update
workers. After changing configuration, redeploy the app and queue services so
both dispatch and execution use current values. Keep the HTTP timeout (310s),
job/worker timeout (330s) and reservation window (390s) in increasing order, with
a container stop grace period exceeding the job timeout. The worker has three
attempts and remains separate from the general queue.

Inspect logs and Laravel failed jobs for unsuccessful refreshes. Check ELMO and
ELMO-MSL independently, including deactivations and completed vocabulary updates.
The ELMO source must use the appropriate ERNIE editor routes. See
[local-development.md](local-development.md#automatic-elmo-cache-refresh) for the
configuration variables, retry policy, upstream dependencies and recovery steps.
