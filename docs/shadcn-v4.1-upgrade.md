# shadcn/ui v4.1 Upgrade Checklist

> Branch: `chore/shadcn-upgrade` | Date: 2026-03-22

## Component Upgrade (35 components)

- [x] Run `npx shadcn@latest add [all 40 components] --overwrite -y`
- [x] Restore custom `combobox.tsx` (custom API with multi-select, `renderOption`, `renderValue`)
- [x] Restore custom `sonner.tsx` (uses `useAppearance` hook instead of `next-themes`)
- [x] Restore custom `chart.tsx` (recharts v3 compatible)
- [x] Restore custom `checkbox.tsx` (custom `indeterminate` prop support)
- [x] Restore custom `table.tsx` (custom `containerClassName` prop)
- [x] Restore custom `card.tsx` (custom `asChild` on `CardTitle`)
- [x] Restore custom `spinner.tsx` (custom size presets: xs/sm/md/lg/xl)
- [x] Delete duplicate `use-mobile.ts` (`.tsx` version already existed)

## Dependency Cleanup

- [x] Remove `@base-ui/react` (only needed by new shadcn combobox, reverted)
- [x] Remove `next-themes` (only needed by new shadcn sonner, reverted)
- [x] Restore `recharts` from `^2.15.4` back to `^3.7.0` (shadcn downgraded it)
- [x] Keep beneficial bumps: `react-day-picker ^9.14.0`, `react-hook-form ^7.72.0`, `react-resizable-panels ^4.7.4`
- [x] Run `npm install` to update lockfile

## CSS Changes

- [x] `@theme inline` block with sidebar color variables added to `app.css`

## Type Fixes

- [x] Fix `SpinnerProps` → `extends Omit<LucideProps, 'ref' | 'size'>` (React 19 type resolution)

## Migration: TooltipProvider

The updated Tooltip component no longer wraps itself in `TooltipProvider` internally.

- [x] Add global `<TooltipProvider delayDuration={0}>` in `app-sidebar-layout.tsx`
- [x] Add global `<TooltipProvider delayDuration={0}>` in `app-header-layout.tsx`
- [x] Add global `<TooltipProvider delayDuration={0}>` in `changelog-layout.tsx`
- [x] Create `tests/vitest/utils/render.tsx` with `TooltipProvider` wrapper for tests
- [x] Remove redundant `<TooltipProvider>` in `app-header.tsx` (lines 165–183)
- [x] Remove redundant `<TooltipProvider>` in `igsns/index.tsx` (4 instances, lines 563–670)
- [x] Remove redundant `<TooltipProvider>` in `changelog-timeline-nav.tsx` (2 instances, lines 70–169)
- [x] Remove redundant `<TooltipProvider>` in `datacite-form.tsx` (2 instances, lines 2482–2533)
- [x] Remove redundant `<TooltipProvider>` in `related-work-item.tsx` (4 instances, lines 42–99)

## Migration: Test Updates

- [x] Fix `skeleton.test.tsx` — `bg-primary/10` → `bg-accent`
- [x] Fix `separator.test.tsx` — `data-slot="separator-root"` → `data-slot="separator"`
- [x] Fix `alert.test.tsx` — `text-destructive-foreground` → `text-destructive`
- [x] Fix 11 test files — import `render` from custom `@tests/vitest/utils/render` (TooltipProvider wrapper)

## New Features

- [x] Use `Select` `size="sm"` prop in filter toolbars and table-row selects (9 candidates)
  - [x] `Logs/Index.tsx` — Level filter
  - [x] `Users/Index.tsx` — Role selector in table row
  - [x] `igsn-filters.tsx` — Prefix + Status filters (2x)
  - [x] `old-datasets-filters.tsx` — Resource Type + Status + Curator filters (3x)
  - [x] `resources-filters.tsx` — Resource Type + Status + Curator filters (3x)

## Verification

- [x] TypeScript: 0 errors (`npx tsc --noEmit`)
- [x] Vite Build: successful (`npx vite build`)
- [x] Vitest: 340/340 test files passed (5605/5606 tests, 1 skipped)
- [x] Final verification after all checklist items complete
