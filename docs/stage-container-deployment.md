# Stage deployment from prebuilt container images

Stage does not build ERNIE images on RZ-VM182. GitHub Actions builds the
application and Nginx images, publishes them to GitHub Container Registry
(GHCR), and advances the machine-managed `deploy/stage` branch only after
both images are available.

Production is intentionally not part of this workflow. Its Compose file and
deployment process remain unchanged until the Stage rollout has been
validated.

## Deployment flow

1. Feature and fix branches are reviewed and merged into `main`.
2. The existing `Security Checks` workflow validates the merged commit.
3. `Publish Stage Images` builds and pushes immutable images tagged
   `sha-<full-commit-sha>`.
4. If the commit is still the head of `main`, the workflow updates the
   movable `stage` image tags.
5. Only then does the workflow fast-forward `deploy/stage` to that exact
   `main` commit.
6. Portainer's existing outbound Git polling detects the new
   `deploy/stage` commit and recreates the Stage services from the GHCR
   images.

No developer works on or merges into `deploy/stage`. It is a deployment
pointer maintained by GitHub Actions. A failed or superseded build never moves
the pointer.

The published images are:

- `ghcr.io/mcnamara84/ernie-app:stage`
- `ghcr.io/mcnamara84/ernie-nginx:stage`
- `ghcr.io/mcnamara84/ernie-app:sha-<full-commit-sha>`
- `ghcr.io/mcnamara84/ernie-nginx:sha-<full-commit-sha>`

The `app`, `queue`, `assessment-queue`, and `scheduler` services share
the same application image. `webserver` uses the Nginx image.

## One-time rollout

Perform these steps in order. The temporary pause prevents the existing
`main` polling configuration from trying to deploy the new Compose file
before its first images exist.

### 1. Check connectivity

RZ-VM182 needs outbound HTTPS access to:

- the Git repository used by Portainer;
- `ghcr.io`;
- the GHCR authentication endpoint when private packages are used.

No inbound connection from GitHub to RZ-VM182 is required.

### 2. Pause the current Stage auto-update

Before merging this change, temporarily disable Git polling/auto-fetch for the
existing Stage stack. Do not delete the stack or its volumes.

### 3. Merge through the normal pull-request process

Merge the implementation into `main`. Wait for:

1. `Security Checks` to succeed;
2. `Publish Stage Images` to succeed.

The second workflow creates the two GHCR packages, their `stage` tags, and
the `deploy/stage` branch. It can also be started manually from the Actions
page to retry the current `main` commit.

The workflow requests `contents: write` and `packages: write` for the
built-in `GITHUB_TOKEN`; no registry password is required in GitHub. If the
branch push or package push receives HTTP 403, verify the repository or
organization policy under **Settings > Actions > General > Workflow
permissions**. It must permit the requested write scopes.

### 4. Choose GHCR visibility

After their first publication, open both package settings on GitHub:

- `ernie-app`;
- `ernie-nginx`.

Either make both packages public or keep both private.

For private packages, add `ghcr.io` as a custom registry in Portainer. Use
the GitHub username and a classic personal access token with at least
`read:packages`. Grant the package access to the repository if GitHub does
not link it automatically. Select that registry for the Stage stack.

Never put the GHCR token into the Compose file or into a container environment
variable.

### 5. Change the existing Portainer Stage stack

Edit the Git settings of the existing stack:

- repository: unchanged;
- repository reference: `refs/heads/deploy/stage`;
- Compose path: `docker-compose.stage.yml`;
- existing stack environment variables: retain them unchanged;
- registry: select the GHCR registry when the packages are private;
- automatic update mechanism: keep the currently available polling/auto-fetch
  mechanism;
- re-pull images: enable it when the installed Portainer version exposes the
  option.

The Compose file also sets `pull_policy: always`, so the current `stage`
manifests are requested on every actual stack update.

Save and redeploy the stack, then re-enable automatic polling if changing the
Git reference did not already enable it.

### 6. Verify the first deployment

Portainer must show these image references:

- `app`, `queue`, `assessment-queue`, and `scheduler`:
  `ghcr.io/mcnamara84/ernie-app:stage`;
- `webserver`: `ghcr.io/mcnamara84/ernie-nginx:stage`.

There must be no local ERNIE Docker build in the Portainer deployment log.
Verify that:

- `app`, MySQL, Redis, and F-UJI become healthy;
- all queue and scheduler containers remain running;
- the public health endpoint succeeds;
- the expected migration ran once in the `app` container;
- CPU and RAM remain stable during the update.

## Normal operation

After the one-time rollout, the normal developer process remains unchanged:

1. create a feature or fix branch from `main`;
2. open and review a pull request;
3. merge the pull request into `main`;
4. wait for the Security and Stage image workflows;
5. Portainer observes `deploy/stage` and deploys the images.

If another commit reaches `main` while images are building, the older
workflow retains its immutable SHA images but does not update the `stage`
tags or `deploy/stage`.

## Manual retry

Use **Actions > Publish Stage Images > Run workflow**. Manual execution always
uses the current head of `main`; it cannot deploy an arbitrary feature
branch.

## Rollback

Every successful build keeps immutable SHA tags. To test a previous image,
set these two Stage stack variables in Portainer:

- `ERNIE_STAGE_APP_IMAGE=ghcr.io/mcnamara84/ernie-app:sha-<good-sha>`
- `ERNIE_STAGE_NGINX_IMAGE=ghcr.io/mcnamara84/ernie-nginx:sha-<good-sha>`

Use the same commit SHA for both images and manually pull/redeploy the stack.
Remove the overrides to return to the automatically maintained `stage`
channel.

Database migrations are not automatically reversible. Check migration
compatibility before rolling the application image back across a schema
change.

## Troubleshooting

### The GHCR push fails with permission denied

Check the workflow write permissions and organization package policy. The
workflow uses the repository's built-in `GITHUB_TOKEN`.

### Portainer reports manifest unknown or unauthorized

Confirm that both `stage` tags exist. For private packages, verify the
Portainer GHCR username, the token's `read:packages` scope, package access,
and that the registry is selected for the stack.

### The deploy branch does not move

Inspect `Security Checks` and `Publish Stage Images`. A failed security
workflow prevents publication. A superseded run intentionally skips
promotion when a newer `main` commit exists.

### Portainer sees the commit but keeps the old image

Confirm that the stack uses the `deploy/stage` reference and the committed
Stage Compose file. Check that `pull_policy: always` is present and use
Portainer's manual pull/redeploy once to inspect the registry error directly.

## Credential cleanup

`.env.production` and `stack.env` are tracked in a public repository. Their
current credential fields are intentionally empty. `stack.env` is excluded
from the Docker build context, and the image workflows replace
`.env.production` with `.env.example` in their temporary checkout before
building.

Previously committed values remain in Git history. Treat every formerly
non-empty application key, API key, cookie key, database password, Solr
password, DataCite password, F-UJI password, and mail password from those files
as exposed. Rotate all values that were ever live, then update the
corresponding Portainer stack variables. History rewriting is not a substitute
for credential rotation.

Plan the Laravel `APP_KEY` rotation separately. Changing it without a
transition can invalidate encrypted cookies and make application-encrypted
values unreadable. Audit encrypted data first and use Laravel's previous-key
support during the transition.
