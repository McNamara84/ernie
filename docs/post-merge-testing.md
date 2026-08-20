# Post-Merge Testing of ERNIE

This guide supports targeted manual testing of a feature, fix, or hotfix after
its pull request has been merged and deployed to stage. It does not replace the
full pre-release regression test.

> **Important:** Before starting a test run, create a local copy of this file
> and fill in only that copy, for example
> `post-merge-testing-2026-07-17-pr-123.md`. Completed test runs are not
> maintained in this repository.

## 1. Ground Rules

- Test only at <https://ernie.rz-vm182.gfz.de/> and confirm that the expected
  pull request has been deployed to stage before starting.
- Use dedicated administrator or curator test accounts. Group Leader and
  Beginner are not part of regular manual testing.
- Use only synthetic data with a unique identifier such as
  `MANUAL-TEST-20260717-AB`, and clean it up afterwards.
- Stage is expected to use `DATACITE_TEST_MODE=true`. Before registering a DOI
  or IGSN, the dialog must clearly show test mode. If that notice is missing, do
  not confirm the registration and block the test.
- All visible stage actions may be performed using test data. **Logs must not
  be deleted, either individually or in bulk.**
- Never include credentials in test logs, screenshots, or backlog items.

### 1.1 Results and Defects

Use these statuses:

- **PASS:** The expected result was achieved.
- **FAIL:** The expected result was not achieved.
- **BLOCKED:** The test cannot be executed because a prerequisite is missing or
  another defect blocks it.
- **N/A:** The test is intentionally not applicable; a reason is required.

Create a separate backlog item for each problem according to the internal cheat
sheet **“Creating Backlog Items”**. Document at least the environment and
deployment version, role, browser, test data identifier, reproduction steps,
expected and actual result, frequency, and a sanitised screenshot.

## 2. Post-Merge Process

1. Verify that the pull request is merged and its deployment to stage is
   complete.
2. Run the smoke test from
   [`pre-release-testing.md`](pre-release-testing.md). If it returns `FAIL` or
   `BLOCKED`, do not consider the targeted test successful.
3. Clarify the change, acceptance criteria, affected roles, and risk level with
   development.
4. Complete the template in section 3 and run direct and adjacent test cases.
5. Record deviations as separate backlog items, clean up test data, and
   recommend `ACCEPT`, `ACCEPT WITH KNOWN RISKS`, or `REJECT`.
6. If the version is intended for production, also run the full pre-release
   regression test.

### 2.1 Minimum Scope by Risk

| Risk   | Examples                                             | Minimum scope                                              |
| ------ | ---------------------------------------------------- | ---------------------------------------------------------- |
| Low    | Copy, icon, or non-interactive presentation          | Smoke test and direct acceptance criteria                  |
| Medium | Filter, dialog, form field, or export                | Smoke test, positive and negative cases, and adjacent page |
| High   | Login, roles, saving, migration, DOI/IGSN, or import | Smoke test, complete affected workflow, and affected role  |

---

## 3. Feature or Fix Test (After a Pull Request Merge)

For every feature or fix test, copy this entire section into a new local file.
Testing always starts with the smoke test from the
[pre-release guide](pre-release-testing.md).

### 3.1 Request

| Field                      | Entry                  |
| -------------------------- | ---------------------- |
| Title                      |                        |
| Type                       | Feature / Fix / Hotfix |
| Issue or backlog item      |                        |
| Pull request               |                        |
| Commit or version          |                        |
| Stage deployment completed |                        |
| Requester                  |                        |
| Tester                     |                        |
| Test date                  |                        |
| Roles                      | Admin / Curator        |
| Browser and viewport       |                        |
| Test data identifier       | `MANUAL-TEST-`         |

### 3.2 Understand the Change

**Short description**

>

**Problem before the change**

>

**Expected behaviour after the change**

>

**Explicitly out of scope**

>

**Technical or functional notes from development**

>

### 3.3 Risk and Test Scope

| Question                                                | Answer              |
| ------------------------------------------------------- | ------------------- |
| Which pages or modules are directly affected?           |                     |
| Which stored data is affected?                          |                     |
| Does role or access control change?                     | Yes / No            |
| Does it affect import, export, or migration?            | Yes / No            |
| Does it affect DOI, IGSN, or an external service?       | Yes / No            |
| Does it affect responsive design or a specific browser? |                     |
| Risk level                                              | Low / Medium / High |
| Rationale                                               |                     |

**Selected test scope**

- [ ] Smoke test from the [pre-release guide](pre-release-testing.md)
- [ ] Direct acceptance criteria
- [ ] Positive main case
- [ ] Negative and validation cases
- [ ] Roles and access control
- [ ] Save, reload, and edit again
- [ ] Import/export
- [ ] Adjacent regression
- [ ] Firefox
- [ ] Mobile viewport
- [ ] Complete affected end-to-end workflow

### 3.4 Preconditions and Test Data

| Precondition or test data | Value and expected state |
| ------------------------- | ------------------------ |
| Test account              |                          |
| Starting page             |                          |
| Existing resource         |                          |
| Resource to create        |                          |
| Files                     |                          |
| External services         |                          |
| Feature configuration     |                          |
| Other precondition        |                          |

- [ ] All preconditions are met or documented as `BLOCKED`.
- [ ] Test data is synthetic, uniquely labelled, and can be cleaned up
      afterwards.

### 3.5 Acceptance Criteria

Each criterion must be observable and independently decidable.

| ID    | Acceptance criterion | Status                      | Evidence or backlog item |
| ----- | -------------------- | --------------------------- | ------------------------ |
| AC-01 |                      | PASS / FAIL / BLOCKED / N/A |                          |
| AC-02 |                      | PASS / FAIL / BLOCKED / N/A |                          |
| AC-03 |                      | PASS / FAIL / BLOCKED / N/A |                          |
| AC-04 |                      | PASS / FAIL / BLOCKED / N/A |                          |
| AC-05 |                      | PASS / FAIL / BLOCKED / N/A |                          |

### 3.6 Detailed Test Cases

For each test case, specify concrete inputs and a verifiable result. Copy rows
as needed.

| ID    | Preconditions | Steps and input | Expected result | Status                      | Evidence or backlog item |
| ----- | ------------- | --------------- | --------------- | --------------------------- | ------------------------ |
| TC-01 |               |                 |                 | PASS / FAIL / BLOCKED / N/A |                          |
| TC-02 |               |                 |                 | PASS / FAIL / BLOCKED / N/A |                          |
| TC-03 |               |                 |                 | PASS / FAIL / BLOCKED / N/A |                          |
| TC-04 |               |                 |                 | PASS / FAIL / BLOCKED / N/A |                          |
| TC-05 |               |                 |                 | PASS / FAIL / BLOCKED / N/A |                          |

Consider at least:

- [ ] Happy path using valid input
- [ ] Empty, invalid, and boundary input
- [ ] Cancel, Back, reload, and repeated clicking
- [ ] Save and verify after reopening
- [ ] Error message and recovery after an error
- [ ] Affected administrator and/or curator perspective

### 3.7 Fix-Specific Retest

Complete this section for a fix. For a feature-only change, record `N/A` and a
reason.

| Field                       | Entry |
| --------------------------- | ----- |
| Original problem            |       |
| Original reproduction steps |       |
| Expected failure before fix |       |
| Behaviour after fix         |       |
| Original data or edge case  |       |

- [ ] The original steps no longer reproduce the defect.
- [ ] The original edge case was repeated using the same relevant input.
- [ ] A counterexample or negative case still behaves correctly.
- [ ] The fix corrects not only the visible message but also stored data or
      status.

### 3.8 Adjacent Regression

| Adjacent area           | Why affected? | Check | Status                      |
| ----------------------- | ------------- | ----- | --------------------------- |
| Previous step           |               |       | PASS / FAIL / BLOCKED / N/A |
| Next step               |               |       | PASS / FAIL / BLOCKED / N/A |
| Save and load           |               |       | PASS / FAIL / BLOCKED / N/A |
| List, search, or filter |               |       | PASS / FAIL / BLOCKED / N/A |
| Export or public output |               |       | PASS / FAIL / BLOCKED / N/A |
| Other role              |               |       | PASS / FAIL / BLOCKED / N/A |

For changes to a critical area, also run the corresponding complete workflow:

- [ ] Login/roles: section 3.3 of the pre-release guide
- [ ] Dashboard/upload: section 3.4 of the pre-release guide
- [ ] Editor/saving: sections 3.5–3.7 of the pre-release guide
- [ ] Landing page/DOI/portal: sections 3.8–3.10 of the pre-release guide
- [ ] IGSN: section 3.11 of the pre-release guide
- [ ] Administrator function: corresponding section 3.13–3.21 of the
      pre-release guide

### 3.9 Deviations and Backlog Items

Record each problem separately according to **“Creating Backlog Items”**.

| Backlog item | Severity | Affected test case | Summary | Status |
| ------------ | -------- | ------------------ | ------- | ------ |
|              |          |                    |         | Open   |
|              |          |                    |         | Open   |
|              |          |                    |         | Open   |

### 3.10 Cleanup

- [ ] Newly created resources, IGSNs, and landing page configurations were
      removed or documented.
- [ ] Temporary users, roles, and settings were restored.
- [ ] External test registrations and sent test emails were recorded.
- [ ] Downloads and database dumps were securely removed.
- [ ] Logs were not deleted.

### 3.11 Result and Recommendation

| Completion field                 | Entry                                     |
| -------------------------------- | ----------------------------------------- |
| Smoke test                       | PASS / FAIL / BLOCKED                     |
| Acceptance criteria              | PASS / FAIL / BLOCKED                     |
| Adjacent regression              | PASS / FAIL / BLOCKED / N/A               |
| Cleanup                          | PASS / FAIL / BLOCKED                     |
| Open blocker or critical defects |                                           |
| Known major/minor defects        |                                           |
| Untested items and reason        |                                           |
| Residual risk                    |                                           |
| End time                         |                                           |
| Tester recommendation            | ACCEPT / ACCEPT WITH KNOWN RISKS / REJECT |
| Rationale                        |                                           |

**Next action**

- [ ] `ACCEPT`: The change meets the criteria and can be included in the next
      release.
- [ ] `ACCEPT WITH KNOWN RISKS`: Deviations are recorded as backlog items and
      the residual risk is described.
- [ ] `REJECT`: At least one required criterion failed or is blocked;
      correction and another stage deployment are required.

Tester name and date:

>
