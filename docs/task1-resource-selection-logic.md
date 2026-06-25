# Task 1 — Resource-Selection-Logic

## Deliverable
Resource selection logic for records with a missing `language_id`, including the evidence relations that need to be preloaded efficiently.

---

## Selection
- Only select resources where `language_id` is missing (NULL)
- Exclude resources that already have a `pending` language suggestion
- Exclude resources for which a suggestion has already been `dismissed`

---

## Preloaded Relations

| Relation | Required | Purpose |
|---|---|---|
| `title` | yes | Primary detection signal |
| `description` | yes | Secondary detection signal |
| `subject` | optional | Supporting context signal |
| `publisher` | optional | Supporting context signal |


