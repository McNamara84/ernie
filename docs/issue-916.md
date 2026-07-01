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
