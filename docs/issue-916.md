# Implementation Proposal for #916

## Overview

Introduce a new **"All Assistants"** tab that aggregates suggestions from all assistants into a single review workflow.

Instead of reviewing suggestions assistant by assistant, curators can review all pending suggestions for a resource in one place and apply multiple changes simultaneously.

## Goals

- Add a new **All Assistants** tab.
- Group suggestions by resource (e.g. DOI).
- Display all pending suggestions from every assistant within each resource.
- Allow curators to select individual suggestions using checkboxes.
- Provide bulk actions for each resource.

## User Interface

Each resource card should display all pending suggestions from every assistant.

Example:

**DOI:** `10.1234/example`

- ☐ Title language → `de`
- ☐ Description language → `en`
- ☐ Subject suggestion → `Climate data`
- ☐ Related item suggestion → `IsReferencedBy ...`

Actions available for the resource:

- **Accept Selected**
- **Decline Selected**
- **Accept All**
- *(Optional)* **Decline All**

## Expected Behaviour

- **Accept Selected** applies only the checked suggestions.
- **Decline Selected** dismisses only the checked suggestions.
- **Accept All** applies every pending suggestion for the resource.
- Accepted suggestions disappear from the review list.
- A confirmation message summarizes the applied changes.

Example:

> Successfully accepted 4 suggestions for resource `10.1234/example`.

## Technical Approach

1. Add a new **All Assistants** tab to the Assistance UI.
2. Load pending suggestions from all assistants.
3. Group suggestions by resource (e.g. DOI).
4. Maintain checkbox selection state for each resource.
5. Implement bulk accept/decline actions.
6. Display a confirmation summary after completion.

## Design Considerations

The existing assistant-specific acceptance logic should remain unchanged.

Instead of creating separate bulk implementations for every assistant, introduce a shared bulk workflow that delegates to the existing assistant handlers.

Benefits:

- Reuses existing acceptance logic.
- Avoids duplicated business logic.
- Preserves assistant-specific validation.
- Simplifies future assistant integrations.

## Test Coverage

The implementation should include tests verifying that:

- The **All Assistants** tab is displayed.
- Suggestions from multiple assistants are grouped by resource.
- Individual suggestions can be selected.
- **Accept Selected** applies only selected suggestions.
- **Accept All** applies every pending suggestion for the resource.
- Accepted suggestions are removed from the list.
- A confirmation message accurately summarizes the applied updates.

# Code Vorschlag:

