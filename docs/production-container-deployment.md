# Production deployment from stable Stage artifacts

Production does not build ERNIE images on its Portainer host. A published
stable GitHub release promotes the exact application and Nginx image digests
that were previously deployed to Stage and creates a machine-managed
`deploy/prod` commit. Portainer follows that branch and therefore deploys only
immutable, release-approved images.

## Deployment flow

1. A normal pull request is merged into `main`.
2. The Security, Pest, Vitest, lint/PHPStan, and Playwright workflows validate
   the `main` commit.
3. `Publish Stage Images` builds and scans the application and Nginx images,
   pins their exact digests in `deploy/stage`, and Stage deploys them.
4. A GitHub release such as `v1.0.9` is published for that commit.
5. The read-only `Production Release Signal` workflow records the release
   event. It has no package or repository write permission.
6. `Promote Production Release`, loaded from the default branch through
   `workflow_run`, independently verifies the release and CI results in a
   read-only `validate` job.
7. Its read-only `inspect` job finds the historical `deploy/stage` commit for the release
   source, verifies that the source commit is a parent, resolves the exact
   image digests from its Compose file, confirms that the manifests still
   exist, and scans them again for HIGH and CRITICAL vulnerabilities.
8. Only after those checks succeed does the `promote` job receive repository
   write access. If the release is still GitHub's latest stable release, it
   writes those same digests into `docker-compose.prod.yml` and advances
   `deploy/prod` with a compare-and-swap push.
9. Portainer's outbound Git polling detects the changed deployment commit and
   recreates Production from the promoted images.

No developer works on or merges into `deploy/prod`. Its commits record the
release tag and source SHA, retain the validated source commit as a parent,
and form a linear deployment history. The generated Compose file references
both images as `ghcr.io/...@sha256:<digest>`; it never deploys from a mutable
`latest`, `production`, or version tag.

## Public homepage routing

The public `/` route on `dataservices.gfz.de` is served by ERNIE. The production
Compose configuration removes this exact path from the higher-priority legacy
router; confirmed legacy segments such as `/web/`, `/portal/`, and `/igsn-new/`
continue to redirect to `dataservices.gfz-potsdam.de`. `/elmo` and `/elmo-msl`
remain owned by their separate stacks.

When releasing the homepage, deploy both the application and the updated
`webserver` service definition so Traefik receives the new labels. Updating only
the application image leaves the old root redirect active. Verify `/` returns
the ERNIE homepage with HTTP 200, local topic images load, `/doi-search` and
`/igsn-search` remain available, and legacy URLs still redirect correctly.

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
- all five deployment-blocking push workflows succeeded for that exact
  commit;
- `deploy/stage` contains a deployment commit for that exact source SHA;
- both Stage-pinned image manifests still exist and pass the new digest scan;
- the release remains latest immediately before `deploy/prod` is updated.

Publishing a prerelease, an older release, or a release for an unvalidated or
not-yet-staged commit cannot change `deploy/prod`. The workflow compares
numeric semantic versions rather than trusting GitHub's mutable latest label
and repeats the deployed-version guard before creating the compare-and-swap
update. Deleting or reclassifying a release also does not automatically roll
Production back; rollback remains an explicit operational action.

The release event is intentionally separated from the privileged promotion.
A release workflow runs in the context of its tag, whereas the
`workflow_run` promotion is loaded from the default branch. The promotion
does not trust the signal workflow's code or artifacts: its read-only jobs
query the latest release, resolve the tag, check `main` and all CI results,
inspect the trusted Stage deployment history, and rescan both images before a
separate job receives write access.

Neither workflow offers `workflow_dispatch`. This prevents a branch copy of a
write-capable workflow from being dispatched manually.

## One-time rollout

Perform these steps in order. Until the first eligible release has been
promoted, `main` contains deliberately unpublished `deployment-template`
markers and `deploy/prod` does not yet exist.

### 1. Pause the current Production auto-update

Before merging this implementation, temporarily disable Git polling or
auto-fetch for the existing Production stack. Do not delete the stack,
containers, named volumes, or database volume. Record or export the existing
stack environment variables before editing its Git settings.

Production needs outbound HTTPS access to the Git repository, `ghcr.io`, and
the GHCR authentication endpoint when the packages are private. No inbound
connection from GitHub to the Production Portainer server is required.

### 2. Merge and stage the intended release commit

Merge the implementation through the normal pull-request process. Keep
Production polling paused. Wait until all five validation workflows and
`Publish Stage Images` have succeeded for the exact commit that will receive
the release tag. Confirm that Stage is healthy with that deployment.

The release tag must point to a commit that already contains these Production
workflows. An older tag such as `v1.0.8` does not bootstrap the new process;
the first new published stable release does.

### 3. Publish the stable GitHub release

Create or publish the release using a strict stable tag such as `v1.0.9`:

- target the already validated and staged `main` commit;
- leave **Set as a pre-release** disabled;
- ensure GitHub marks it as the latest release;
- publish the release only after the tag, title, notes, and target commit are
  correct.

Wait for:

1. `Production Release Signal` to finish successfully;
2. `Promote Production Release` to finish successfully with `validate`,
   `inspect`, and `promote` executed rather than skipped.

The following inspection and promotion steps must be green:

- `Resolve the exact Stage deployment artifacts`;
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
- `ERNIE_PROD_APP_IMAGE` and `ERNIE_PROD_NGINX_IMAGE`: leave both unset during
  normal operation; remove stale values because these variables override the
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

- `app`, `queue`, `assessment-queue`, and `scheduler`:
  `ghcr.io/mcnamara84/ernie-app@sha256:<digest>`;
- `webserver`: `ghcr.io/mcnamara84/ernie-nginx@sha256:<digest>`.

There must be no local ERNIE Docker build in the deployment log. Verify that:

- `app`, MySQL, Redis, and F-UJI become healthy;
- all queue and scheduler containers remain running;
- `https://dataservices.gfz.de/health` succeeds;
- the expected database migration ran once in the `app` container;
- the public application and background processing work normally;
- CPU, RAM, disk space, and container restart counts remain stable.

After this verification, re-enable the existing Portainer polling mechanism.
Further `main` commits continue to deploy to Stage only. Production changes
only when a new eligible latest stable GitHub release is published.

## Normal release operation

For each later Production release:

1. merge reviewed changes into `main`;
2. wait for all CI validation and the Stage image publication;
3. verify the exact candidate on Stage;
4. publish the next stable GitHub release for that commit;
5. watch both Production release workflows;
6. verify the automatic Portainer deployment and health checks.

If a newer stable release appears while an older promotion is running, the
concurrency guard cancels the older run. The final latest-release check and
compare-and-swap branch update prevent a superseded run from overwriting a
newer deployment. Every deployment commit remains internally consistent
because both image references are immutable digests from one Stage deployment.

## Manual retry

For a transient failure, use GitHub Actions' **Re-run all jobs** on the failed
`Promote Production Release` run. Re-running `Production Release Signal` also
causes a new promotion attempt after it completes. Every retry independently
revalidates the current latest stable release, CI results, Stage deployment,
image manifests, and vulnerability scan.

Do not add a manual dispatch trigger to the privileged workflow and do not
create another release merely to retry an infrastructure failure.

## Rollback

Every `deploy/prod` commit records a release tag and immutable digest pair. To
restore a previous pair, read both references from the corresponding
deployment commit and set these stack variables in Portainer:

- `ERNIE_PROD_APP_IMAGE=ghcr.io/mcnamara84/ernie-app@sha256:<good-digest>`
- `ERNIE_PROD_NGINX_IMAGE=ghcr.io/mcnamara84/ernie-nginx@sha256:<good-digest>`

Use both digests from the same Production deployment commit, then manually
pull/redeploy. The override persists across automatic Git updates; remove both
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
all five push workflows succeeded for the tagged commit on `main`.

### No Stage deployment commit exists for the release source

The release was published before the exact tagged commit completed Stage
publication, or the tag points to another commit. Do not retag an existing
release. Let Stage publish and validate the intended commit, then correct the
release through the normal release process.

### Image inspection or the digest scan fails

Confirm that the two GHCR packages still contain the digests referenced by the
matching `deploy/stage` commit. Check package access for the repository and the
current vulnerability report. Promotion deliberately stops on a missing image
or a HIGH/CRITICAL finding.

### Portainer reports unauthorized or manifest unknown

For private packages, verify the existing Portainer custom registry for
`ghcr.io`, the GitHub username, a classic token with `read:packages`, package
access, and registry selection on the Production stack.

### Portainer sees the commit but keeps the old image

Confirm that the stack follows `refs/heads/deploy/prod` and uses
`docker-compose.prod.yml`. Remove stale rollback overrides if Production should
return to automatic promotion, then manually pull/redeploy once to inspect the
registry response.
