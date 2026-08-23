# Pre-Release Testing of ERNIE

This guide contains the smoke test after a stage deployment and the full
release regression test before a production deployment. For targeted testing
of a merged pull request, use
[`post-merge-testing.md`](post-merge-testing.md) instead.

> **Important:** Before starting a test run, create a local copy of this file
> and fill in only that copy, for example
> `pre-release-testing-2026-07-17-release-1.2.0.md`. Completed test runs are not
> maintained in this repository.

## Process

1. Read the general information and prepare the test run.
2. After every stage deployment, run the smoke test in section 2.
3. Before a production deployment, also run the full release regression in
   section 3.
4. The tester makes a recommendation; the final go/no-go decision is made by
   Product Owner Tanja ([anti@gfz.de](mailto:anti@gfz.de)).

---

## 1. General Information About Manual Testing of ERNIE

### 1.1 Purpose and Scope

Manual testing complements the automated tests. It verifies from a user's
perspective that the version deployed to stage is usable, that critical
workflows function, and that no visible regressions have been introduced.

This guide covers the following perspectives:

- unauthenticated users
- curator
- administrator
- public landing pages and portal

The **Group Leader** and **Beginner** roles are not part of the regular manual
regression. Their role-specific permissions and restrictions are therefore not
fully covered by manual testing.

### 1.2 Test Types

| Test type               | Purpose                                                          | When                               | Target            |
| ----------------------- | ---------------------------------------------------------------- | ---------------------------------- | ----------------- |
| Smoke test              | Confirm critical basic functions and a successful deployment     | After every stage deployment       | 10–15 minutes     |
| Release regression test | Test all visible and enabled areas with representative workflows | Before every production deployment | Approx. 3–4 hours |

### 1.3 Responsibilities and Approval

- The tester performs the agreed test, documents deviations, and makes a
  release recommendation.

- The final go/no-go decision is made by Product Owner Tanja
  ([anti@gfz.de](mailto:anti@gfz.de)).

- Developers answer functional or technical questions and analyse reported
  defects.

### 1.4 Stage, Accounts, and Credentials

- Test only at <https://ernie.rz-vm182.gfz.de/>.

- Dedicated administrator and curator test accounts are used.

- Never copy credentials into this file, screenshots, backlog items, or chat
  messages.

- Verify the role and environment before testing. Stop immediately if
  unexpected real production data or the wrong domain is shown.

### 1.5 Test Data and Permitted Actions

Stage may be modified for complete testing. The following rules apply:

- Use synthetic data only. Do not upload personal, confidential, or production
  research data.

- Clearly label every created resource, user, and configuration with
  `MANUAL-TEST-<date>-<initials>`, for example
  `MANUAL-TEST-20260717-AB`.

- Delete locally created stage data after the test or restore the initial
  state. Record externally created test DOIs and test IGSNs in the test log,
  even if they cannot be removed.

- User administration, email delivery, imports, exports, database dumps,
  thesaurus updates, and other actions visible on stage may be performed using
  test data.

- **Logs must not be deleted, either individually or in bulk.** Developers
  require them for troubleshooting.

### 1.6 DataCite Test Mode

Stage is expected to use `DATACITE_TEST_MODE=true`. DOI and IGSN registrations
may therefore be tested completely, but they must be clearly identifiable as
test registrations.

Before every registration:

- [ ] The dialog displays a DataCite test mode notice.

- [ ] The DOI or IGSN and title use the current `MANUAL-TEST` identifier.

- [ ] If the test mode notice is missing or production registration is suspected:
      do not confirm, take a screenshot, and block the test.

### 1.7 Browser and Device Coverage

| Scope              | Browser and viewport                                                     |
| ------------------ | ------------------------------------------------------------------------ |
| Every smoke test   | Current Chrome, desktop                                                  |
| Release regression | Full run in current Chrome; short Firefox cross-check; mobile smoke test |

Disable browser extensions, translation features, and ad blockers where
possible. For browser-specific issues, also test in a private window without
extensions.

### 1.8 Status and Evidence

Mark a checkbox only after the step has been performed and the described
result has occurred.

For deviations, add the following directly below the affected step:

```text
Status: FAIL | BLOCKED | N/A
Backlog Item:
Actual result:
Evidence:
```

Status definitions:

- **PASS:** Executed; the expected result was achieved.
- **FAIL:** Executed; the expected result was not achieved.
- **BLOCKED:** Cannot be executed because a prerequisite is missing or another
  defect blocks the workflow.
- **N/A:** Intentionally not applicable to this deployment; a reason is
  required.

### 1.9 Reporting Defects

Create one separate backlog item for each problem according to the internal
cheat sheet **“Creating Backlog Items”**. The title should briefly identify the
affected module and observed problem.

Document at least:

1. Environment, deployment/version, date, and time
2. Role used and test data identifier
3. Browser, browser version, and operating system
4. Unambiguous steps to reproduce
5. Expected and actual result
6. Frequency: always, often, intermittent, or once
7. Screenshot or short video without credentials or confidential data
8. Visible error message; only reference logs or coordinate with developers,
   and never delete them

### 1.10 Severity and Stop Criteria

| Severity | Meaning                                                                                       | Response                                            |
| -------- | --------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| Blocker  | Login, a page, or a critical workflow is unusable; data loss or production impact is possible | Stop the run and notify development immediately     |
| Critical | A core function is incorrect, or registration or saving fails                                 | Stop the affected area; do not recommend release    |
| Major    | A function is defective, but a reasonable workaround exists                                   | Create a backlog item and document the release risk |
| Minor    | Visual, copy, or usability issue without data risk                                            | Create a backlog item and continue testing          |

Stop immediately if:

- the wrong domain or suspected production environment is shown; DataCite test
  mode is missing before registration; real or confidential data is accessed
  unintentionally; data outside the tester's own `MANUAL-TEST` data is lost; or
  repeated server errors make further results unreliable.

---

## 2. Smoke Test (Approx. 10–15 Minutes, After Every Deployment)

### 2.1 Test Run Header

| Field                    | Entry          |
| ------------------------ | -------------- |
| Reason                   |                |
| PR, commit, or version   |                |
| Deployment completed at  |                |
| Test date and start time |                |
| Tester                   |                |
| Browser and version      |                |
| Operating system         |                |
| Test data identifier     | `MANUAL-TEST-` |

### 2.2 Preparation

- [ ] The stage URL, deployment, and intended version have been confirmed by
      development.

- [ ] A current Chrome desktop window is open; old ERNIE tabs are closed.

- [ ] The curator test account and synthetic test data are available.

### 2.3 Public Availability

- [ ] <https://ernie.rz-vm182.gfz.de/> opens without certificate, gateway, server,
      or blank-page errors.

- [ ] The login page renders completely; logo, email, password, and `Log in` are
      visible.

- [ ] `/portal` opens publicly, shows results or a plausible empty state, and
      responds to a search.

### 2.4 Login and Navigation

- [ ] Logging in with the curator test account leads to the `Dashboard`; no
      unexpected error appears.

- [ ] Dashboard metrics and recent resources render without a persistent loading
      state.

- [ ] The navigation opens `Data Editor`, `Resources`, `IGSNs List`, `IGSNs Map`,
      `Documentation`, and `Changelog`.

- [ ] `IGSN Editor` remains visibly disabled while that function has not been
      released.

### 2.5 Critical Curator Workflow

- [ ] A valid synthetic XML file is accepted on the dashboard and a successful
      upload is confirmed.

- [ ] `Open in editor` opens the `Data Editor`; at least DOI/identifier, title,
      publication year, resource type, and creator are populated plausibly.

- [ ] The current `MANUAL-TEST` identifier was added to the title and `Save to
database` saves without a server or validation error.

- [ ] The saved resource appears in `Resources` and can be found using search or
      filters.

- [ ] The resource can be reopened in the editor, a small change can be saved, and
      the updated resource can then be found in `Resources`.

- [ ] The landing page configuration for the test resource opens and a preview
      shows at least title, identifier, creator, and licence.

### 2.6 Completion

- [ ] Logout returns to the login page; a protected page cannot be accessed
      afterwards without logging in again.

- [ ] The resource and landing page configuration created during the smoke test
      have been deleted or clearly recorded for the subsequent regression.

| Completion field | Entry                 |
| ---------------- | --------------------- |
| End time         |                       |
| Result           | PASS / FAIL / BLOCKED |
| Backlog items    |                       |
| Notes            |                       |

**Approval rule:** A smoke test succeeds only if the complete critical workflow
passes. For `FAIL` or `BLOCKED`, do not make any further approval recommendation
until the cause has been assessed.

---

## 3. Release Regression Test (Before Production Deployment)

Start the release regression test only after a successful smoke test. Test all
modules that are visible and enabled on stage. Mark unavailable or intentionally
disabled functions as `N/A` and provide a reason.

### 3.1 Test Run Header and Entry Criteria

| Field                  | Entry                   |
| ---------------------- | ----------------------- |
| Release/version        |                         |
| Commit or tag          |                         |
| Included pull requests |                         |
| Deployment time        |                         |
| Test date              |                         |
| Tester                 |                         |
| Chrome version         |                         |
| Firefox version        |                         |
| Operating system       |                         |
| Test data identifier   | `MANUAL-TEST-`          |
| Previous smoke test    | PASS / Link or filename |

- [ ] Development has confirmed that the complete release candidate is deployed
      to stage.

- [ ] Known limitations, changed features, and acceptance criteria have been
      provided.

- [ ] The administrator and curator test accounts work; Group Leader and Beginner
      are recorded as not manually covered.

- [ ] The test data identifier is unique and has been entered in the test run
      header.

### 3.2 Public Pages

- [ ] The home/login page, `About`, `Legal Notice`, and `Changelog` open directly
      by URL without errors.

- [ ] Header and footer links lead to the expected destinations; external links
      open safely without losing the ERNIE state.

- [ ] The `Changelog` shows the current version, meaningful categories, and
      expandable/collapsible entries.

- [ ] The changelog timeline/jump navigation works with mouse and keyboard.

- [ ] A known public landing page opens directly and does not request
      authentication.

### 3.3 Authentication, Session, and Access Control

Run with both test roles where applicable.

- [ ] An incorrect password displays an understandable error and does not log the
      user in.

- [ ] Valid login leads to the dashboard and persists across navigation and page
      reloads.

- [ ] Direct access to a protected URL without a session leads to the login page.

- [ ] The curator does not see administrator navigation and is denied access when
      opening administrator URLs directly.

- [ ] Logout ends the session; using the browser Back button does not expose
      protected content that can still be used.

### 3.4 Dashboard and Uploads

Run as curator.

- [ ] The dashboard shows plausible metrics, drafts, and recently edited resources
      without contradictory counts.

- [ ] A valid DataCite XML file uploads successfully and is transferred correctly
      to the editor.

- [ ] An invalid XML file produces an understandable error; the page remains
      usable and no resource is saved.

- [ ] A valid DataCite JSON file is imported; core fields match the file contents.

- [ ] A valid IGSN CSV file starts the intended IGSN workflow and reports success
      or row-specific errors clearly.

- [ ] Drag-and-drop and file selection behave consistently; invalid file types are
      rejected.

Suitable synthetic source files are available under
[`tests/pest/dataset-examples`](../tests/pest/dataset-examples) and
[`tests/playwright/fixtures`](../tests/playwright/fixtures). Always make
identifiers and titles unique before saving.

### 3.5 Data Editor – Basic Behaviour

Run as curator using a new test resource.

- [ ] The empty editor loads completely; accordions, status indicators, and
      buttons respond without visible JavaScript errors.

- [ ] Required fields are clearly identified; saving is prevented while required
      data is incomplete and the messages lead to the affected fields.

- [ ] A draft with incomplete data can be saved and reopened later when the UI
      offers that action.

- [ ] Complete required data enables `Save to database`; success is confirmed and
      exactly one resource is created.

- [ ] Double-clicking or confirming again while saving does not create a
      duplicate.

- [ ] Reloading or navigating back with unsaved changes warns appropriately or
      behaves according to the documented application behaviour.

### 3.6 Data Editor – Metadata Sections

Edit at least the following sections in a representative resource, save, and
verify them after reopening:

- [ ] Resource information: DOI/identifier, publication year, resource type,
      language, version, and main title.

- [ ] Titles: main title and one additional title type; order and language are
      preserved.

- [ ] Licences: select at least one SPDX licence; link and label are plausible.

- [ ] Creators: add, edit, remove, and reorder a person using drag-and-drop.

- [ ] Create an institutional creator and select a ROR affiliation.

- [ ] Enter an ORCID in valid format; invalid format is rejected. Where available,
      test ORCID search or auto-fill.

- [ ] Mark a creator as contact person and save contact details.

- [ ] Contributors: add at least two different roles, change the order, and remove
      one entry.

- [ ] Open the CSV import dialog for creators or contributors; the example file
      can be downloaded and a valid file imported.

- [ ] Descriptions: save an abstract and one additional description type;
      character count and validation respond plausibly.

- [ ] Add free keywords, test duplicate handling, and remove one keyword.

- [ ] Search controlled vocabularies and select and remove at least one visible
      term path.

- [ ] Search and select an MSL laboratory, instrument, or other enabled domain
      vocabulary; loading and error states are understandable.

- [ ] Enter spatial coverage using a point; valid latitude and longitude are
      accepted.

- [ ] Enter spatial coverage using a bounding box or polygon; invalid coordinates
      and incorrect min/max order are rejected.

- [ ] Enter temporal coverage and at least one date with a date type; single value
      and range render correctly.

- [ ] Add related work using a DOI and URL; save type and relation. Test DOI
      detection or citation lookup where available.

- [ ] Save a funding reference with funder, identifier, award number, and title;
      lookup suggestions are plausible.

- [ ] After reopening, values, order, special characters, and line breaks match
      the saved inputs.

### 3.7 Resource Management

- [ ] `Resources` loads the table or list, counts, and status indicators without a
      persistent spinner.

- [ ] Search finds the current `MANUAL-TEST` resource by title and identifier; a
      search with no match shows a meaningful empty state.

- [ ] Filters can be applied individually and in combination and can be reset
      completely.

- [ ] Sorting, pagination, or loading more works and does not unexpectedly lose
      active filters.

- [ ] Single and multiple selection show appropriate bulk actions; selection state
      and count agree.

- [ ] DataCite XML, DataCite JSON, and JSON-LD for a test resource export
      successfully; files are not empty and contain the expected identifier and
      title.

- [ ] Opening in the editor uses the selected resource and does not produce a
      browser-blocked-tab message.

- [ ] A non-registered resource created only for this test can be deleted after
      confirmation; cancelling preserves it.

### 3.8 Landing Page Configuration and Preview

- [ ] The landing page dialog for a test resource opens and shows appropriate
      template, domain, and download options.

- [ ] A valid download URL or enabled alternative is saved; an invalid URL
      produces an understandable message.

- [ ] `Create Preview` or `Update` saves the configuration without a duplicate and
      provides a preview.

- [ ] `Preview` opens a new page or tab and correctly shows title, DOI/identifier,
      creators, abstract, licence, and download area.

- [ ] Available optional metadata such as contributors, keywords, funding,
      spatial map, temporal coverage, and related work render correctly.

- [ ] ORCID, ROR, licence, DOI, and download links point to plausible destinations
      and open as intended.

- [ ] The contact form is visible when a contact person exists, validates required
      fields, and sends a test message to a test address.

- [ ] Rendering works in light and dark mode; long titles, many creators, and
      missing optional sections do not break the layout.

### 3.9 DOI Registration in Test Mode

- [ ] A resource without the required landing page cannot be registered
      accidentally and displays a helpful reason.

- [ ] The registration dialog clearly shows DataCite test mode, identifier, target
      status, and affected resource.

- [ ] Cancelling closes the dialog without changing status or data.

- [ ] Registration of a complete `MANUAL-TEST` resource succeeds; status and list
      update without a manual hard reload.

- [ ] Metadata for a DOI already registered in the test system can be updated
      after a change.

- [ ] Errors from the external service are displayed clearly and do not produce a
      false success status.

### 3.10 Portal and Public Discovery

- [ ] The portal opens without login and shows result count, result list, and map
      or an understandable empty state.

- [ ] Free-text search returns matching results and can be cleared.

- [ ] Visible filters for data centre, resource type, keyword, thesaurus, time,
      and geography work individually and in combination.

- [ ] The URL and browser navigation preserve or reconstruct filter state
      appropriately.

- [ ] List and map interactions refer to the same resource; clusters and markers
      respond plausibly.

- [ ] A search result opens the correct public landing page.

### 3.11 IGSN Lists, Map, and Registration

- [ ] `IGSNs List` loads count, status, table, and filters without errors.

- [ ] Search, filters, sorting, selection, and reset work as they do for data
      resources.

- [ ] A valid parent and child CSV can be imported in the intended order;
      hierarchy relationships render plausibly.

- [ ] Invalid or incomplete CSV rows report the row and cause without damaging
      existing valid data.

- [ ] Single import from DataCite and visible import progress work; cancelling
      stops an active test import cleanly.

- [ ] JSON and JSON-LD exports of a test IGSN contain the expected identifier and
      core data.

- [ ] The IGSN registration dialog shows test mode and registration updates the
      visible status.

- [ ] `IGSNs Map` shows test samples with coordinates; markers, clusters, and
      details agree with the list.

- [ ] `IGSN Editor` remains disabled unless it has explicitly been enabled for the
      release.

### 3.12 Personal Settings

Test with curator and administrator.

- [ ] The profile page shows the correct test name, email, and role.

- [ ] A harmless profile change can be saved and then restored to its original
      value.

- [ ] The password page validates an incorrect current password and mismatched new
      passwords. Change the actual test password only after coordination and then
      update it securely.

- [ ] Appearance supports light, dark, and system modes; the selection persists
      after reload.

- [ ] The font-size toggle works with mouse and keyboard, enlarges the display, and
      persists.

### 3.13 Administrator Navigation and Workspace

Run as administrator.

- [ ] Switching between the Curation and Administration workspaces shows the
      appropriate navigation groups.

- [ ] The administrator sees `Users`, `Editor Settings`, `Landing Pages`,
      `Assistance`, `Assessment`, `Database`, `Statistics`, `Statistics (old)`,
      `Logs`, and `Old Datasets` where those modules are enabled on stage.

- [ ] Switching between administration and curation pages does not unexpectedly
      lose the session or workspace state.

### 3.14 User Management

Modify only purpose-created `MANUAL-TEST` users.

- [ ] The user list loads; search and visible filters work.

- [ ] A temporary test user with a test address can be created; validation
      prevents invalid or duplicate data.

- [ ] The welcome or password email reaches the intended test address and contains
      a working link that is not documented publicly.

- [ ] The role of a temporary user can be changed within the permitted range;
      visible badge and permissions update.

- [ ] The temporary user can be deactivated and reactivated; login behaviour
      matches the status.

- [ ] Password reset and guided-tour assignment show confirmation and affect only
      the selected test user.

- [ ] Temporary users and sent test invitations are recorded for cleanup and are
      removed where the UI permits.

### 3.15 Editor Settings and External Vocabularies

- [ ] Editor Settings loads all visible cards and current values.

- [ ] A clearly labelled temporary setting, domain, or data centre can be created,
      selected in the editor, and then removed.

- [ ] Contributor roles and licence/resource-type mappings can be viewed, and a
      reversible test change persists after reload.

- [ ] Status checks for visible thesauri and PID services return an understandable
      result.

- [ ] An agreed test update shows progress, success, or failure and updates the
      version or timestamp plausibly.

- [ ] All reversible configuration changes have been restored to their original
      values.

### 3.16 Landing Page Templates

- [ ] The landing page template list loads previews, status, and actions.

- [ ] A `MANUAL-TEST` template can be created or cloned, edited, and saved.

- [ ] Logo upload accepts a valid image and clearly rejects an invalid file type.

- [ ] The test template can be selected in the landing page dialog and changes the
      preview as expected.

- [ ] Cancelling preserves the template; confirmed deletion removes only the
      `MANUAL-TEST` template.

### 3.17 Assistance and Assessment

If a module is not configured or intentionally disabled, record `N/A` and a
reason.

- [ ] `Assistance` loads pending tasks and counts consistently.

- [ ] Entering a bare DOI or the equivalent `https://doi.org/...` URL filters
      both Assistance views to exact, case-insensitive matches only; applying the
      filter resets result pagination and clearing it restores the full lists.

- [ ] The Assistance Datacenter dropdown contains only datacenters affected by
      pending suggestions. Combining DOI and Datacenter uses AND semantics, and
      the displayed task counts and pagination match the filtered suggestions.

- [ ] An ORCID or ROR suggestion that would also update the filtered resource via
      a shared person or institution remains visible and is clearly marked as an
      indirect match.

- [ ] A synthetic suggestion can be opened, reviewed, accepted, or rejected; the
      resource and counts update appropriately.

- [ ] External suggestion services display loading, success, empty, and error
      states clearly.

- [ ] `Assessment` loads the summary and resources without a persistent loading
      state.

- [ ] Entering a bare DOI or the equivalent `https://doi.org/...` URL filters
      both Resource and IGSN results exactly; the visible summaries and empty
      states reflect the filter and clearing it restores the unfiltered results.

- [ ] The Assessment Datacenter dropdown contains only datacenters with completed
      assessments. Combining DOI and Datacenter uses AND semantics without
      changing the scope of `Check` or `Check all` jobs.

- [ ] Both Assessment tables show the letter for the largest raw F-UJI gap (`F`,
      `A`, `I`, or `R`) for usable results; color, accessible name, and tooltip
      consistently communicate the potential increase in the overall FAIR score.

- [ ] Hover and keyboard focus reveal English, positively worded guidance with no
      more than three genuinely score-causal actions in the correct leverage order;
      administrator actions are clearly identified.

- [ ] Resource and IGSN guidance uses distinct wording. A physical sample is never
      asked to add downloads, file sizes, or file formats; inapplicable digital
      F-UJI checks are explained neutrally.

- [ ] After a metadata change newer than the stored assessment, the tooltip first
      asks for reassessment. Complete and unavailable results show the same neutral
      dash but provide distinct accessible explanations.

- [ ] A single test resource and the intended batch run can be assessed; progress
      and result update.

- [ ] If the F-UJI service is unavailable, an understandable message appears
      instead of an endless loading indicator or blank page.

### 3.18 Statistics and Legacy Data

- [ ] `Statistics` loads all visible metrics, charts, and tables; values, legends,
      and tooltips are plausible.

- [ ] Empty or small datasets do not produce broken charts.

- [ ] `Statistics (old)` opens without server errors and shows plausible
      comparison data or an explained empty state.

- [ ] `Old Datasets` loads the list, search, filters, and load-more behaviour.

- [ ] Detail data such as creators, contributors, funding, descriptions, dates,
      keywords, spatial coverage, and related identifiers can be loaded.

- [ ] Transferring a synthetic legacy dataset into the editor populates the
      related fields plausibly without changing the source.

### 3.19 Logs – Read Only

- [ ] `Logs` loads the list, timestamps, levels, and messages without errors.

- [ ] Search, filters, sorting, and detail view work where provided.

- [ ] A harmless error intentionally triggered during the test can be correlated
      by time without exposing confidential credentials in the UI.

- [ ] **No delete action has been performed.**

### 3.20 Database Dumps

- [ ] `Database` opens and shows existing dumps, target systems, status, and
      timestamps plausibly.

- [ ] A stage test dump can be started; progress changes cleanly to success or
      displays an understandable error.

- [ ] A successful test dump can be downloaded; the file is not empty and is not
      copied to a public or shared directory.

- [ ] Downloaded dumps are securely removed from the test machine after
      verification.

### 3.21 Documentation and Guided Tours

- [ ] `Documentation` shows the `Getting Started`, `Datasets`, and `Physical
Samples` tabs; content switches without page errors.

- [ ] Section navigation jumps to the correct section and marks the visible
      section.

- [ ] Code examples and internal links are fully readable and lead to the expected
      destination.

- [ ] A guided tour assigned to a temporary user starts, can be operated, closed,
      and completed.

### 3.22 Firefox, Mobile, and Accessibility Cross-Check

In current Firefox:

- [ ] Login, dashboard, resource list, editing an existing resource, saving a
      change, landing page preview, and logout work.

- [ ] Portal search and a public landing page work.

In Chrome using a mobile viewport:

- [ ] Login, main navigation, dashboard, and logout remain fully usable; content
      does not overlap horizontally.

- [ ] Portal search, opening filters, result list, and public landing page are
      usable.

- [ ] Documentation tabs and mobile changelog navigation work.

Basic accessibility in Chrome:

- [ ] Login, navigation, dialogs, and critical form fields can be used with Tab,
      Enter, Space, and Escape.

- [ ] Focus is visible and moves appropriately when a dialog opens or closes.

- [ ] At 200% browser zoom, critical content and actions remain accessible.

- [ ] Error messages are not communicated by colour alone.

### 3.23 Cross-Cutting Checks

- [ ] No unexpected blank pages, `500`/`502`/`504` errors, or persistent loading
      indicators appear during the test run.

- [ ] Success and error messages match the action performed and do not disappear
      before they can be read.

- [ ] Double-clicking, cancelling, and using browser Back do not create duplicates
      or unnoticed data loss.

- [ ] Special characters, umlauts, long titles, and line breaks render correctly
      in the editor, lists, exports, and landing pages.

- [ ] Typical pages respond within a reasonable time; unusual delays are recorded
      with time and action.

### 3.24 Cleanup and Completion

- [ ] All resources, drafts, IGSNs, landing page configurations, and templates
      created during the test are deleted or recorded with a reason for later
      cleanup.

- [ ] Temporary users, role changes, settings, and test files have been cleaned up
      or restored.

- [ ] Externally registered test DOIs and test IGSNs are fully documented.

- [ ] Downloaded database dumps have been securely removed.

- [ ] Logs have not been deleted.

#### Summary

| Area                   | Result                      | Backlog items / Notes |
| ---------------------- | --------------------------- | --------------------- |
| Smoke test             | PASS / FAIL / BLOCKED       |                       |
| Public pages and auth  | PASS / FAIL / BLOCKED / N/A |                       |
| Curator core workflows | PASS / FAIL / BLOCKED / N/A |                       |
| DOI and landing pages  | PASS / FAIL / BLOCKED / N/A |                       |
| IGSN                   | PASS / FAIL / BLOCKED / N/A |                       |
| Administrator areas    | PASS / FAIL / BLOCKED / N/A |                       |
| Firefox and mobile     | PASS / FAIL / BLOCKED / N/A |                       |
| Cleanup                | PASS / FAIL / BLOCKED       |                       |

| Completion field                 | Entry                                         |
| -------------------------------- | --------------------------------------------- |
| End time                         |                                               |
| Number of PASS                   |                                               |
| Number of FAIL                   |                                               |
| Number of BLOCKED                |                                               |
| Number of N/A                    |                                               |
| Open blocker or critical defects |                                               |
| Other open defects               |                                               |
| Residual risks                   | Group Leader and Beginner not tested manually |
| Tester recommendation            | GO / GO WITH KNOWN RISKS / NO-GO              |
| Rationale                        |                                               |
| Sent to Product Owner on         |                                               |

**Recommendation rule:** Use `GO` only after a successful smoke test, with no
open blocker or critical defects, and after successful cleanup. Deviations and
`N/A` results must have a clear rationale. The final decision rests with Tanja
([anti@gfz.de](mailto:anti@gfz.de)).
