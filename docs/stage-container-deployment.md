# Stage deployment from prebuilt container images

Stage does not build ERNIE images on RZ-VM182. GitHub Actions builds the
application and Nginx images, publishes them to GitHub Container Registry
(GHCR), and creates a machine-managed `deploy/stage` commit whose Compose file
pins both images by immutable digest.

Production is intentionally not part of this workflow. Its Compose file and
deployment process remain unchanged until the Stage rollout has been
validated.

## Deployment flow

1. Feature and fix branches are reviewed and merged into `main`.
2. The Security, Pest, Vitest, lint/PHPStan, and Playwright workflows validate
   the merged commit.
3. `Publish Stage Images` verifies that all five workflows succeeded for the
   exact current `main` commit, then builds and pushes the images under
   traceable `sha-<full-commit-sha>` tags and captures their immutable
   digests.
4. The workflow creates a deployment commit derived from that source commit.
   Its Stage Compose file pins the application and Nginx images as
   `ghcr.io/...@sha256:<digest>`.
5. If the source commit is still the head of `main`, the workflow advances
   `deploy/stage` to the digest-pinned deployment commit.
6. Portainer's existing outbound Git polling detects the new
   `deploy/stage` commit and recreates the Stage services from the GHCR
   images.

No developer works on or merges into `deploy/stage`. It is a deployment
pointer maintained by GitHub Actions. Its commits retain the validated source
commit as a parent and form a linear deployment history. A compare-and-swap
push prevents an older workflow from overwriting a newer deployment commit.

The published images are:

- `ghcr.io/mcnamara84/ernie-app:sha-<full-commit-sha>`
- `ghcr.io/mcnamara84/ernie-nginx:sha-<full-commit-sha>`

The generated deployment Compose file does not use mutable `stage` tags. It
references the exact digest produced by each image build. Therefore a manual
pull or later stack recreation cannot combine a deployment commit with images
from another commit.

The Compose file on `main` contains the deliberately unpublished
`deployment-template` marker. It is input for the publishing workflow, not a
direct Portainer deployment target. The workflow requires and replaces every
marker before advancing `deploy/stage`.

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

1. `Security Checks`, `Pest PHP Unit Tests`, `Vitest TS integration Tests`,
   `Linter Tests`, and `Playwright UI Tests` to succeed;
2. `Publish Stage Images` to publish the validated commit.

The second workflow creates the two GHCR packages and the digest-pinned
`deploy/stage` branch. It can also be started manually from the Actions page
to retry the current `main` commit.

The validation job has read-only access. Only after every deployment-blocking
workflow succeeded for the same commit does the publish job request
`contents: write` and `packages: write` for the built-in `GITHUB_TOKEN`; no
registry password is required in GitHub. If the branch push or package push
receives HTTP 403, verify the repository or organization policy under
**Settings > Actions > General > Workflow permissions**. It must permit the
requested write scopes.

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

The Compose file also sets `pull_policy: always`. Because its generated image
references include immutable digests, every actual stack update requests the
exact validated manifests.

Save and redeploy the stack, then re-enable automatic polling if changing the
Git reference did not already enable it.

### 6. Verify the first deployment

Portainer must show these image references:

- `app`, `queue`, `assessment-queue`, and `scheduler`:
  `ghcr.io/mcnamara84/ernie-app@sha256:<digest>`;
- `webserver`: `ghcr.io/mcnamara84/ernie-nginx@sha256:<digest>`.

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
4. wait for all CI validation and the Stage image workflow;
5. Portainer observes `deploy/stage` and deploys the images.

If another commit reaches `main` while images are building, the final head
guard normally skips the older deployment and the newer validated workflow
replaces it. Even if `main` changes at the final update boundary, every
deployment commit remains internally consistent because it pins both image
digests; a later pull can never silently substitute images from another
commit.

## Manual retry

Use **Actions > Publish Stage Images > Run workflow**. Manual execution always
uses the current head of `main`; it cannot deploy an arbitrary feature
branch. It still requires successful Security, Pest, Vitest, lint/PHPStan, and
Playwright push workflows for that exact commit.

## Rollback

Every deployment commit records immutable image digests. To restore a previous
pair, read both digest references from the corresponding `deploy/stage` commit
and set these two Stage stack variables in Portainer:

- `ERNIE_STAGE_APP_IMAGE=ghcr.io/mcnamara84/ernie-app@sha256:<good-digest>`
- `ERNIE_STAGE_NGINX_IMAGE=ghcr.io/mcnamara84/ernie-nginx@sha256:<good-digest>`

Use both digests from the same deployment commit and manually pull/redeploy
the stack. Remove the overrides to return to the automatically maintained,
digest-pinned deployment branch.

Database migrations are not automatically reversible. Check migration
compatibility before rolling the application image back across a schema
change.

## Troubleshooting

### The GHCR push fails with permission denied

Check the workflow write permissions and organization package policy. The
workflow uses the repository's built-in `GITHUB_TOKEN`.

### Portainer reports manifest unknown or unauthorized

Confirm that both pinned digests exist. For private packages, verify the
Portainer GHCR username, the token's `read:packages` scope, package access, and
that the registry is selected for the stack.

### The deploy branch does not move

Inspect the five deployment-blocking validation workflows and `Publish Stage
Images`. Any missing, running, or failed validation for the exact commit
prevents publication. A superseded run intentionally skips promotion when a
newer `main` commit exists.

### Portainer sees the commit but keeps the old image

Confirm that the stack uses the `deploy/stage` reference and the committed
Stage Compose file. Check that `pull_policy: always` is present and use
Portainer's manual pull/redeploy once to inspect the registry error directly.

## Credential cleanup

`.env.production` and `stack.env` are tracked in a public repository. Their
current credential fields are intentionally empty or use non-secret sentinels
such as `null`. `stack.env` is excluded from the Docker build context, and the
image workflows replace `.env.production` with `.env.example` in their
temporary checkout before building.

Previously committed values remain in Git history. Rotate any application key,
API key, cookie key, database password, Solr password, DataCite password,
F-UJI password, or mail password from those files that was ever live, then
update the corresponding Portainer stack variables. Known dummy and test
values do not require rotation. History rewriting is not a substitute for
credential rotation.

Plan the Laravel `APP_KEY` rotation separately. Changing it without a
transition can invalidate encrypted cookies and make application-encrypted
values unreadable. Audit encrypted data first and use Laravel's previous-key
support during the transition.
