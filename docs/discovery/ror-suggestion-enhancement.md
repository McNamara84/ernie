# Discovery: ROR Suggestion Display Enhancement

## Context

This discovery captures the investigation for an issue about improving ROR suggestion visibility in the assistance UI.

### User story

As a data curator, I want to be able to identify which institution is being referred to when a ROR is suggested, without having to carry out further checks. Because some institutions have similar or identical names but are based in different locations, I need to be able to see the location in order to make informed decisions.

### Acceptance criteria

- All ROR suggestions display PrefLabel, Other Names, and Locations.
- The link to the ROR entry remains intact.

## Current state

### Frontend

- The main ROR suggestion card is implemented in `resources/js/pages/assistance.tsx`.
- `RorSuggestionCard` currently renders:
  - `suggestion.entity_name`
  - `suggestion.suggested_name`
  - `suggestion.ror_aliases`
  - `suggestion.suggested_ror_id` as a clickable link if it is a valid ROR URL.
- The current type definition for ROR suggestions is in `resources/js/types/assistance.ts`.

### Backend

- ROR discovery logic is built around `app/Services/RorDiscoveryService.php`.
- The Assistance API route is managed by `app/Http/Controllers/AssistanceController.php`.
- A direct mapping from the backend response to the frontend suggestion model is likely present in the Assistance controller or related service layer.

## Observations

- `PrefLabel` is likely already represented by `suggestion.suggested_name` or `suggestion.entity_name`.
- `Other Names` appear to be present as `suggestion.ror_aliases`.
- `Locations` are not currently visible in the card UI and may not be delivered by the backend.
- The ROR link is already implemented and should remain unchanged.

## Unknowns

- Does the backend currently include location data in the ROR suggestion payload?
- If location data exists, what are the exact field names? Possible candidates:
  - `location`
  - `address`
  - `city`
  - `country`
- If location is missing from the payload, does the ROR discovery process have access to the necessary ROR record fields to populate it?
- Should the location be displayed as a single human-readable line or split into multiple lines/components?

## Recommended investigation steps

1. Inspect the current suggestion payload returned by the Assistance API for `ror-suggestion` entries.
2. Review `resources/js/types/assistance.ts` for all fields declared on `SuggestedRorItem`.
3. Verify the ROR suggestion generation path in `app/Services/RorDiscoveryService.php`.
4. Check `app/Http/Controllers/AssistanceController.php` and any response transformation logic for the suggestion payload.
5. Determine whether location data is already available in the backend response.
6. If needed, extend the backend suggestion payload to include location metadata.
7. Update `RorSuggestionCard` to render the new fields without changing the existing ROR link behavior.

## Implementation notes

- Keep the ROR URL logic intact.
- Add a dedicated display section for locations in `RorSuggestionCard`.
- Prefer minimal UI changes: render `PrefLabel` as the primary name, `Other Names` as a subtitle or small text, and `Locations` as a separate metadata line.

## Next steps

- Confirm the exact backend field names for location metadata.
- Add the missing fields to the frontend type definitions and the suggestion card.
- Add or update unit tests for the ROR suggestion rendering.
