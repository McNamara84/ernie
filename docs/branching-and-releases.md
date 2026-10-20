# Branching and Releases

This guide describes parallel work on the current Production line, `1.1.x`, and
the next minor version, `1.2.0`. The two long-lived code branches are `main` and
`dev`; their names stay the same when a new minor version is released.

The repository implements this workflow. Before activating it, merge the pipeline
changes through review and complete the GitHub setup in section 3. Existing
Portainer stacks keep following `deploy/stage` and `deploy/prod`.

## 1. Your day-to-day workflow

1. **Bugfix:** Create a working branch from `main`, open a PR against `main`,
   review it, and merge it after CI passes.
2. **Synchronization:** An Action automatically creates or updates a PR from
   `automation/sync-main-to-dev` into `dev` and enables auto-merge. Once the required reviews and CI pass,
   the fixes are merged into v1.2.0 without a manual merge step.
3. **Feature:** Create a working branch from `dev` and open the feature PR
   against `dev`. After review, merge, and successful CI, Stage receives the update.
4. **Minor release:** When v1.2.0 is ready, merge `dev` into `main`, validate the
   resulting release commit, and publish it. Afterwards, `main` maintains v1.2.x
   and `dev` develops v1.3.0.

Automatic synchronization is a required part of the initial pipeline transition.
Routine synchronization requires no manual PR creation or merge clicks. Human
input is needed for reviews required by branch rules, conflicts, failed checks,
and failures that automatic retries cannot recover from.

## 2. Which branch should I use?

| Task | Create working branch from | PR target |
| --- | --- | --- |
| New feature for v1.2.0 | `dev` | `dev` |
| Fix affecting only a new v1.2.0 feature | `dev` | `dev` |
| Bugfix or improvement explicitly intended for v1.1.x | `main` | `main` |
| Bring existing v1.1.x changes into v1.2.0 | Action manages an integration branch containing `main` | `dev` |
| Finalize the version and changelog for v1.1.1 | `main` | `main` |
| Integrate the completed v1.2.0 feature release | Use the existing `dev` branch | `main` |

`main` maintains the current stable release line. Merging into it does not deploy
to Production: a validated stable GitHub release must be published first.

`dev` contains the next minor version and deploys automatically to Stage after
successful CI. During v1.2.0 development, Production continues to receive only
published stable v1.1.x releases.

`deploy/stage`, `deploy/prod`, `artifacts/main`, and `artifacts/dev` are maintained
exclusively by workflows. Do not develop on or open PRs against these branches.
Artifact branches retain one immutable `<source-sha>.json` image record per
validated commit. Retries reuse its three digests instead of replacing them.

This guide assumes **one actively supported Production line**. Once v1.2.0 is
released, `main` maintains v1.2.x; continuing v1.1.x support would require a
separate maintenance arrangement.

## 3. One-time preparation

### GitHub settings and credentials

Complete this setup as part of the rollout, before routine work on `dev`:

1. Keep **`main` as the default branch**. In **Settings > General > Pull Requests**,
   enable **Allow auto-merge** and **Allow merge commits**. Keep `main` and `dev`
   after merges; disable automatic head-branch deletion for these long-lived
   branches. Never squash or rebase synchronization or minor-release PRs.
2. Extend the existing `main` protection rules to **both `main` and `dev`**.
   A rule targeting only the default branch does not protect `dev`. Require the
   existing reviews (including code owners), required CI checks, and **Require
   branches to be up to date before merging**. Use the check/job names from the
   six existing workflows: Security, Pest, MySQL, Vitest, Lint/PHPStan, and
   Playwright. Disallow force pushes and deletion on both code branches. Do not
   require linear history because synchronization preserves merge commits.
3. Create a dedicated GitHub App, installed **only on this repository**, with
   repository permissions **Contents: read/write**, **Pull requests: read/write**,
   **Workflows: read/write**, **Checks: read**, and **Commit statuses: read**.
   Workflow permission is needed when a fix changes
   `.github/workflows/` and the integration branch carries it forward. Do not add
   this App to any branch/ruleset bypass list. It must not approve its own PRs.
4. Create the GitHub environment **`branch-sync`**. Restrict deployment branches
   to **`main` only** and do not require deployment approval for this environment;
   PR reviews provide the merge gate. Store the App private key as the environment
   secret **`SYNC_APP_PRIVATE_KEY`** and its client ID as the environment variable
   **`SYNC_APP_CLIENT_ID`**. Keep the private key out of repository secrets so a
   workflow on `dev` cannot retrieve it. The workflow creates short-lived,
   repository-scoped tokens automatically.
5. Publishing the tested stable GitHub release authorizes Production deployment.
   No **`production`** environment or additional approval in Actions is needed.
   Compare the candidate SHA and recorded image digests with the test report
   before publishing. Promotion runs automatically after its release, CI, and
   image checks pass. An existing `production` environment is not used by this
   workflow and can remain in place.
6. Allow Actions to request `contents: write` and `packages: write` for validated
   image publication. Keep `automation/sync-main-to-dev` available for the App's
   guarded branch updates. Deployment and artifact branches are machine-managed:
   do not apply PR-only code-branch rules to them. Do not rewrite artifact
   history or delete GHCR digests referenced by release records.

Merge the implementation into `main` through the normal reviewed PR process.
Publication now validates both branches, but **only `dev` updates Stage**. Until
`dev` exists and passes CI, Stage stays on its previous deployment. Production
continues serving its current deployment until an eligible release is published
and its automatic promotion checks pass.
GitLab mirroring continues to mirror `main` and stable release tags; drafts and
prereleases are skipped.

The simple release rule remains: Production follows the highest published stable
release. While v1.2.0 is in development, publish only v1.1.x as stable releases.
`dev` prereleases do not trigger Production promotion.

### Create `dev`

All Git commands below assume a clean working tree. First install the pipeline
changes on `main`, then branch from the shared starting point before adding
any v1.2.0-only features:

```powershell
git fetch origin
git switch main
git pull --ff-only origin main
git switch -c dev
git push -u origin dev
```

This starting point should include the changes already intended for v1.1.1.
Create `dev` from the appropriate current `main`, rather than automatically using
the old v1.1.0 tag. If v1.2.0-only changes already exist on `main`, first establish
a suitable stable starting point and separate those changes.

Through a working branch and PR against `dev`, add a new first changelog entry
for `1.2.0dev`. Keep the existing `1.1.1dev` entry below it. On `main`, the first
entry remains `1.1.1dev`.

Verify the rollout with a reviewed `main` change: one synchronization PR appears,
its CI runs, required review blocks merging until approval, and the resulting
`dev` push publishes images and updates Stage. A subsequent `main` change must
reuse an open synchronization PR. Check workflow failures in Actions; scheduled
reconciliation retries pending work without requiring another push.

## 4. Develop a new feature for v1.2.0

1. Create a working branch from the current `dev`:

   ```powershell
   git fetch origin
   git switch -c feature/my-feature origin/dev
   ```

2. Implement the feature, run appropriate tests, and update the `1.2.0dev`
   changelog entry. See [Local Development](local-development.md) and
   [Testing](testing.md) for the local validation procedures.
3. Push the working branch and open a PR with **base `dev`**. Check the target
   explicitly because GitHub normally suggests the default branch, `main`.
4. Wait for review and CI, then merge.
5. Wait for CI on the merged commit and `Publish Validated Images`.
6. After Portainer has actually updated Stage, verify the expected deployment
   using [Post-Merge Testing](post-merge-testing.md).

Production stays on the published v1.1.x release.

## 5. Develop a Production bugfix

1. Create a working branch from the current `main`:

   ```powershell
   git fetch origin
   git switch -c fix/my-bugfix origin/main
   ```

2. Fix the issue and update the `1.1.1dev` changelog entry. A Production fix must
   not depend on features that exist only on `dev`.
3. Run appropriate local checks. For PHP changes, use `npm run test:php:tia`
   during development and `npm run check:backend` for final validation. Use
   `npm run check:frontend` for frontend changes. These repository wrappers and
   their requirements are documented in [Local Development](local-development.md).
4. Push the branch and open a PR with **base `main`**.
5. Wait for review and CI, then merge.
6. The synchronization Action brings the change into `dev` as described in
   section 6. Complete any required review or resolve reported blockers. The
   separately tested `main` candidate reaches Production only through a release
   under section 7.

An urgent hotfix follows the same branch and release process, with higher priority.

## 6. Automatic synchronization from `main` into `dev`

Routine synchronization flows **from `main` into `dev`**. The reverse direction,
`dev` into `main`, is reserved for integrating a completed minor release because
it also brings in the new features.

The following steps are performed by the automation:

1. After each push to `main`, including release preparation and changelog updates,
   reconcile the changes that `dev` still needs. If all `main` commits are already
   included, finish without creating a PR.
2. Prepare `automation/sync-main-to-dev` from the current `dev` plus `main`, then
   create or update a single PR with **base `dev`** and that helper as its head.
   Only the helper branch is refreshed; `main` never receives development-only
   commits during synchronization. Both histories are preserved in the merge.
3. Run the required PR checks and enable auto-merge. If `main` or `dev` advances,
   reevaluate the current merge result and rerun the necessary checks before
   merging. Required human reviews still apply; the automation does not grant
   its own approval or bypass branch rules.
4. Merge automatically with a **merge commit** once all requirements are met.
   Keep both branches. Use merge commits for minor release PRs as well to preserve
   the shared history; do not squash or rebase these branch-to-branch PRs.
5. The resulting push to `dev` starts its CI and Stage image publication. After
   Portainer updates the stack, Stage contains the upcoming minor version together
   with the synchronized fixes.

The first changelog entry on `dev` must remain `1.2.0dev`, with updated v1.1.x
entries below it. Do not duplicate a fix as a new v1.2.0 changelog item. Ambiguous
changelog or code conflicts require resolution rather than choosing one branch's
version automatically.

If a conflict, failing check, or unrecoverable automation error blocks progress,
leave the PR open and report the blocker in the PR or workflow summary. After
the blocker is resolved, the automation reevaluates the PR and resumes auto-merge.
It runs on `main` pushes, completion of branch CI, and every 15 minutes (GitHub
may delay schedules). Unchanged source and target commits reuse the same helper
commit, so scheduled checks do not repeatedly invalidate reviews or restart CI.

To pause a PR, add **`sync:hold`**, mark it as draft, or disable its already-enabled
auto-merge. On its next run, synchronization disables any active auto-merge for a
held or draft PR. It checks these controls again before enabling auto-merge,
including whether a maintainer disabled it during reconciliation. Remove the
label/mark ready and re-enable auto-merge to resume. A manually closed
synchronization PR must be reopened before automation continues.
For a repository-wide pause, set the Actions variable **`SYNC_MAIN_TO_DEV_PAUSED`**
to `true`. The next run disables auto-merge on the managed PR and records that
the automation applied the pause. Remove the variable or set `false` to resume
automatically; a pre-existing or subsequently observed PR hold still requires
manual resumption. For immediate reconciliation, run **Synchronize main into
dev** with **Run workflow**, selecting `main`. A label or variable change alone
does not trigger this workflow. To stop a pending merge immediately, disable
auto-merge in the PR first and set the pause control; do not wait for the next
scheduled run. Such a manual disablement also requires manual resumption.
Do not edit or push directly to the automation-owned helper branch.

For a merge conflict, create a helper branch from `origin/dev` and merge
`origin/main` into that helper branch. Resolve the conflicts there and open a PR
into `dev`, enabling auto-merge with a merge commit after review and checks. Do
not update `main` with `dev` to resolve a synchronization conflict or satisfy an
up-to-date branch requirement. Once the helper PR is merged, the automation
reconciles the original synchronization PR and closes it if its work is included.

Until synchronization and its checks succeed, Stage does not receive those `main`
changes. Other successfully validated `dev` updates can still deploy. A successful
`main` build alone does not update Stage.

## 7. Publish v1.1.1

This section applies after the pipeline transition in section 3 and uses the
release test stack established there.

1. **Prepare the release.** Create a working branch from `origin/main`, such as
   `chore/release-1.1.1`. In [changelog.json](../resources/data/changelog.json),
   change `1.1.1dev` to `1.1.1` and finalize the date and release notes. Review and
   merge the PR into `main`. Do not add a v1.1.2 entry yet: the built application
   displays the version from the first changelog entry.
2. **Record the candidate.** Select the reviewed release commit. If it is still
   the current `main` head, capture it with:

   ```powershell
   git fetch origin
   $releaseSha = git rev-parse origin/main
   $releaseSha
   ```

   Verify that this is the intended release commit and record its full SHA in
   the test report. If `main` has already advanced, use the reviewed commit's SHA
   explicitly. From now on, `$releaseSha` identifies that fixed candidate; do not
   refresh it from the branch before tagging.
3. **Test the candidate.** Wait for successful CI and image checks for that SHA.
   Use [Release Candidate Testing](release-candidate-testing.md) to run
   `npm run release:prepare -- $releaseSha`, start the isolated stack, and run
   `npm run release:verify -- $releaseSha`. Verify the version, commit, and
   deployment completion. Perform the smoke test and release
   regression from [Pre-Release Testing](pre-release-testing.md), including
   relevant migrations from the current Production schema. Tests on v1.2.0 Stage
   do not replace testing this candidate.
4. **Confirm release readiness.** Follow the Go/No-Go procedure in the testing
   guide for this candidate and compare its recorded SHA and image digests with
   the test report before publishing. If findings require more changes, select
   and test a new candidate before proceeding.
5. **Tag the tested commit.** In the same PowerShell session, using the recorded
   SHA:

   ```powershell
   git tag -a v1.1.1 $releaseSha -m "ERNIE v1.1.1"
   git push origin v1.1.1
   ```

   In a new session, first set `$releaseSha` to the full SHA from the test report.
   Do not move or overwrite an existing release tag.
6. **Publish the GitHub release.** Under **Releases > Draft a new release**, select
   the existing `v1.1.1` tag, add the release notes, leave **Pre-release disabled**,
   and publish it as **Latest**. Select the existing tag rather than creating one
   automatically at the current branch head. A tag or saved draft alone does not
   trigger the existing Production promotion; publishing the release authorizes
   automatic deployment after all promotion checks pass. There is no additional
   approval in Actions.
7. **Verify Production.** Follow `Production Release Signal` and
   `Promote Production Release`. Its `validate`, `inspect`, and `promote` jobs
   check and deploy the published candidate automatically. Then verify the
   actual Portainer deployment, all three image digests, the displayed version,
   and `/health`. Stage remains on the v1.2.0 development line.
8. **Prepare further development.** Through a new PR against `main`, add
   `1.1.2dev` as the first changelog entry and preserve the final v1.1.1 entry below
   it. The synchronization Action carries these changes into `dev` under section 6;
   its first entry stays `1.2.0dev`.

If promotion fails because of an infrastructure problem, rerun the existing
workflow as described in [Production Deployment](production-container-deployment.md).
Do not change the tag or create another release just to retry deployment.

## 8. Release v1.2.0 and start the next cycle

1. **Finish the current patch work.** Complete any v1.1.x releases that must ship
   before v1.2.0. Wait for automatic synchronization to include the latest `main`
   in `dev` and resolve any reported blockers. Once `dev` is merged into `main`,
   that branch contains v1.2.0 and no longer serves as a v1.1.x release source.
2. **Finalize v1.2.0 on `dev`.** Through a working branch and PR against `dev`,
   change `1.2.0dev` to `1.2.0` and finalize the date and notes. Preserve released
   v1.1.x history. Remove unused old-line development placeholders and account for
   any still-unreleased fixes in the v1.2.0 notes. Review, test, and verify the
   candidate on Stage. Keep v1.3.0 work out of `dev` until v1.2.0 is published.
3. **Integrate the minor release.** Open a PR with **base `main`** and
   **compare `dev`**. If additional fixes reached `main` during preparation, wait
   for their automatic synchronization into `dev` and the updated checks before
   completing the PR. Review it and merge with **Create a merge commit**. This
   merge does not deploy to Production; it prepares the new release line on `main`.
4. **Validate and publish the resulting commit.** Follow steps 2 through 7 of
   section 7, using version `1.2.0` and tag `v1.2.0`. Record the resulting commit
   on `main`, build and test its images, and publish the stable release. The earlier
   Stage build belongs to the `dev` commit and must not be assumed to identify
   this new merge commit.
5. **Prepare both branches for further work.** After publication, add `1.2.1dev`
   as the first changelog entry through a PR against `main`. Add `1.3.0dev` as the
   first entry through a PR against `dev`. The synchronization Action carries
   `main` changes into `dev`, retaining the `1.3.0dev` entry first and the shared
   release history below it.
6. **Continue with the same branch names.** Production fixes now target `main`
   for v1.2.x; new features target `dev` for v1.3.0. Keep both branches.

Optional preview releases from `dev` can use tags such as `v1.2.0-rc.1` and must
be marked **Pre-release** on GitHub. Production promotion and GitLab mirroring
skip them. A stable `v1.2.0` release is the
Production release, not a development preview.

## 9. Automation and human responsibilities

| Task | Automation included in the initial transition | Human responsibility |
| --- | --- | --- |
| Synchronize fixes into `dev` | Create or update one PR after `main` changes; retry transient failures and reconcile pending work | Resolve conflicts and persistent failures |
| Merge the synchronization PR | Enable auto-merge with a merge commit after required reviews and checks | Provide any review required by branch rules; explicitly hold a PR if necessary |
| Publish images and deploy | Run CI and image publication after the merge; update Stage through Portainer | Verify the deployed behavior and investigate failures |
| Prepare and publish a release | Build and validate the selected candidate; promote it automatically after the stable release is published and all checks pass | Finalize release notes, perform release testing, and publish the stable release |

Use the dedicated GitHub App from the start so automated PRs and merges trigger
the required CI workflows. The
[GitHub documentation for GITHUB_TOKEN](https://docs.github.com/en/actions/concepts/security/github_token)
explains the event restrictions and the use of an App token. Repository
[auto-merge](https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/automatically-merging-a-pull-request)
waits for required reviews and checks. Optional later improvements concern release
preparation or changelog generation, not automatic synchronization.
