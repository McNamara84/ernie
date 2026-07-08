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
// app/Providers/AssistantServiceProvider.php

Route::middleware(['web', 'auth', 'verified', 'can:access-assistance'])
    ->prefix('assistance')
    ->group(function () use ($registrar) {
        Route::get('/', [AssistanceController::class, 'index'])
            ->name('assistance');

        Route::post('/check-all', [AssistanceController::class, 'checkAll'])
            ->name('assistance.check-all');

        // New: bulk actions for the "All Assistants" tab
        Route::post('/suggestions/accept-selected', [AssistanceController::class, 'acceptSelected'])
            ->name('assistance.suggestions.accept-selected');

        Route::post('/suggestions/decline-selected', [AssistanceController::class, 'declineSelected'])
            ->name('assistance.suggestions.decline-selected');

        foreach ($registrar->getAll() as $assistant) {
            $prefix = $assistant->getManifest()->routePrefix;
            $id = $assistant->getId();

            Route::post("/check/{$id}", [AssistanceController::class, 'check'])
                ->name("assistance.check.{$id}")
                ->defaults('assistantId', $id);

            Route::post("/{$prefix}/{suggestion}/accept", [AssistanceController::class, 'accept'])
                ->where('suggestion', '[0-9]+')
                ->name("assistance.{$id}.accept")
                ->defaults('assistantId', $id);

            Route::post("/{$prefix}/{suggestion}/decline", [AssistanceController::class, 'decline'])
                ->where('suggestion', '[0-9]+')
                ->name("assistance.{$id}.decline")
                ->defaults('assistantId', $id);
        }
    });
 // app/Http/Controllers/AssistanceController.php

public function acceptSelected(Request $request): JsonResponse
{
    $data = $request->validate([
        'suggestions' => ['required', 'array', 'min:1'],
        'suggestions.*.assistantId' => ['required', 'string'],
        'suggestions.*.suggestionId' => ['required', 'integer'],
    ]);

    $accepted = [];

    foreach ($data['suggestions'] as $item) {
        $assistant = $this->registrar->get($item['assistantId']);

        if ($assistant === null) {
            continue;
        }

        $result = $assistant->acceptSuggestion($item['suggestionId']);

        $accepted[] = [
            'assistantId' => $item['assistantId'],
            'suggestionId' => $item['suggestionId'],
            'result' => $result,
        ];
    }

    return response()->json([
        'success' => true,
        'acceptedCount' => count($accepted),
        'accepted' => $accepted,
    ]);
}

public function declineSelected(DeclineSuggestionRequest $request): JsonResponse
{
    $data = $request->validate([
        'suggestions' => ['required', 'array', 'min:1'],
        'suggestions.*.assistantId' => ['required', 'string'],
        'suggestions.*.suggestionId' => ['required', 'integer'],
        'reason' => ['nullable', 'string'],
    ]);

    /** @var User $user */
    $user = $request->user();

    $declinedCount = 0;

    foreach ($data['suggestions'] as $item) {
        $assistant = $this->registrar->get($item['assistantId']);

        if ($assistant === null) {
            continue;
        }

        $assistant->declineSuggestion(
            $item['suggestionId'],
            $user,
            $request->input('reason')
        );

        $declinedCount++;
    }

    return response()->json([
        'success' => true,
        'declinedCount' => $declinedCount,
    ]);
}

// resources/js/pages/assistance.tsx

type SelectedSuggestion = {
    assistantId: string;
    suggestionId: number;
};

const [activeTab, setActiveTab] = useState<'by-assistant' | 'all-assistants'>('by-assistant');
const [selected, setSelected] = useState<Record<number, SelectedSuggestion[]>>({});

function toggleSuggestion(resourceId: number, suggestion: SelectedSuggestion) {
    setSelected((current) => {
        const existing = current[resourceId] ?? [];

        const alreadySelected = existing.some(
            (item) =>
                item.assistantId === suggestion.assistantId &&
                item.suggestionId === suggestion.suggestionId,
        );

        return {
            ...current,
            [resourceId]: alreadySelected
                ? existing.filter(
                      (item) =>
                          !(
                              item.assistantId === suggestion.assistantId &&
                              item.suggestionId === suggestion.suggestionId
                          ),
                  )
                : [...existing, suggestion],
        };
    });
}

function acceptSelected(resourceId: number) {
    router.post(route('assistance.suggestions.accept-selected'), {
        suggestions: selected[resourceId] ?? [],
    });
}

function acceptAll(resourceId: number, suggestions: SelectedSuggestion[]) {
    router.post(route('assistance.suggestions.accept-selected'), {
        suggestions,
    });
}

function groupSuggestionsByResource(sections: Record<string, any>) {
    const grouped: Record<number, any> = {};

    Object.entries(sections).forEach(([assistantId, paginatedSuggestions]) => {
        paginatedSuggestions.data.forEach((suggestion: any) => {
            const resourceId = suggestion.resource_id;

            if (!grouped[resourceId]) {
                grouped[resourceId] = {
                    resourceId,
                    doi: suggestion.doi,
                    title: suggestion.title,
                    suggestions: [],
                };
            }

            grouped[resourceId].suggestions.push({
                ...suggestion,
                assistantId,
            });
        });
    });

    return Object.values(grouped);
}

# Proposal for Issues #916 and #919

## Overview

This proposal combines the ideas behind **#916** (bulk review workflow) and **#919** (visually distinguishing either/or decisions) while keeping the implementation modular and extensible for future issues such as **#955**.

The goal is **not** to implement all issues at once, but to design the new review workflow so that future functionality can be integrated with minimal refactoring.

---

# Issue #916 – All Assistants View

## Current Situation

Suggestions are currently reviewed separately in each assistant tab.

This means that if one resource has suggestions from multiple assistants (e.g. Title Language, Resource Language, ROR, ORCID), the curator must switch between several tabs to review all proposed changes.

## Proposed Solution

Introduce a new **"All Assistants"** tab.

Instead of grouping suggestions by assistant, this view groups them by **resource (DOI/file)**.

Example:

```
DOI: 10.5880/example

☐ Title Language
☐ Resource Language
☐ Description Language
☐ ROR
☐ ORCID

Accept Selected
Accept All
Decline Selected
```

This allows the curator to review all proposed changes for a single resource in one place.

---

## Bulk Actions

Each resource provides:

- Accept Selected
- Decline Selected
- Accept All

Only the currently selected suggestions are accepted.

Accept All accepts every eligible suggestion belonging to that resource.

---

# Issue #919 – Either/Or Suggestions

## Current Situation

Sometimes multiple suggestions refer to the same metadata field.

Example:

```
Creator Affiliation

Suggestion A
Suggestion B
Suggestion C
```

These suggestions are mutually exclusive.

---

## Proposed Solution

Instead of creating another view or dialog, keep these suggestions inside the same resource card.

Suggestions belonging to the same metadata item are grouped together.

For example:

```
Creator Affiliation

☐ University of Potsdam (96.8%)
---------------------------------
☐ Universität Potsdam (91.3%)
---------------------------------
☐ Potsdam University (83.1%)

=================================

Description Language

☐ English
```

### Visual distinction

Suggestions belonging to the **same metadata item**

→ separated by a **thin / dotted line**

Suggestions belonging to **different metadata items**

→ separated by a **more prominent divider**

This satisfies the acceptance criteria of **#919** while keeping the interface compact.

---

# Technical Design

## Backend

### New Service

```
app/Services/Assistance/
    SuggestionGroupingService.php
```

Responsibilities:

- Group suggestions by resource
- Group suggestions by metadata item
- Prepare data for the React UI

---

### Controller

```
app/Http/Controllers/
    AssistanceController.php
```

New methods:

- acceptSelected()
- declineSelected()

The existing assistant-specific accept/decline logic remains unchanged and is reused.

---

### Routes

```
app/Providers/
    AssistantServiceProvider.php
```

New routes:

```
accept-selected

decline-selected
```

---

## Frontend

### Existing page

```
resources/js/pages/assistance.tsx
```

Add a new tab:

```
All Assistants
```

---

### New components

```
resources/js/components/assistance/

AllAssistantsView.tsx

ResourceSuggestionCard.tsx

SuggestionItem.tsx
```

Responsibilities:

**AllAssistantsView**

- render grouped resources

**ResourceSuggestionCard**

- render one DOI/resource
- render bulk buttons
- render grouped metadata items

**SuggestionItem**

- render one suggestion with checkbox

---

# Future Compatibility

## Issue #955

The implementation does **not** include cross-resource propagation yet.

Instead, the code is structured so that a future post-accept workflow can easily be added.

For example:

```php
// Future extension:
//
// After accepting a suggestion,
// dispatch a background job that checks
// whether the same change should be proposed
// for similar resources.
```

This keeps **#916** focused while reducing future refactoring.

---

# Benefits

- One review workflow per resource instead of per assistant.
- Less navigation between assistant tabs.
- Bulk acceptance of suggestions.
- Either/or suggestions remain visually distinguishable.
- Existing assistant logic is reused.
- Future issues (#919 and #955) can be integrated with minimal architectural changes.