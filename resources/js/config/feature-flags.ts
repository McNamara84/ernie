/**
 * Feature flags for shadcn/ui migration
 *
 * This configuration enables safe, incremental migration of UI components
 * with instant rollback capability via localStorage overrides.
 *
 * @see docs/implementation-plans/shadcn-ui-migration.md
 */

/**
 * Feature flag definitions
 * Set to true to enable new components, false to use legacy
 */
export const FEATURE_FLAGS = {
    // Phase 4: Form System
    /** Enable react-hook-form + Zod validation for simple forms */
    USE_NEW_FORM_SYSTEM: false,
    /** Enable new DataCite form implementation (high risk) */
    USE_NEW_DATACITE_FORM: false,

    // Phase 5: Date Pickers
    /** Enable shadcn/ui Calendar component for date inputs */
    USE_NEW_DATE_PICKER: false,

    // Phase 6: Autocomplete/Command
    /** Enable Command-based ORCID search dialog */
    USE_NEW_ORCID_SEARCH: false,
    /** Enable Command-based MSL Laboratories combobox */
    USE_NEW_MSL_COMBOBOX: false,

    // Phase 7: DataTables
    /** Enable TanStack Table for Resources page */
    USE_NEW_RESOURCES_TABLE: false,
    /** Enable TanStack Table for IGSNs page */
    USE_NEW_IGSNS_TABLE: false,
    /** Enable TanStack Table for Users page */
    USE_NEW_USERS_TABLE: false,
    /** Enable TanStack Table for Logs page */
    USE_NEW_LOGS_TABLE: false,
} as const;

export type FeatureFlag = keyof typeof FEATURE_FLAGS;

/**
 * Check if a feature flag is enabled
 *
 * Supports runtime override via localStorage for testing:
 * - localStorage.setItem('ff_USE_NEW_DATE_PICKER', 'true') to enable
 * - localStorage.setItem('ff_USE_NEW_DATE_PICKER', 'false') to disable
 * - localStorage.removeItem('ff_USE_NEW_DATE_PICKER') to use default
 *
 * @param flag - The feature flag to check
 * @returns Whether the feature is enabled
 */
export function isFeatureEnabled(flag: FeatureFlag): boolean {
    // Allow runtime override via localStorage for testing
    if (typeof window !== 'undefined') {
        const override = localStorage.getItem(`ff_${flag}`);
        if (override !== null) {
            return override === 'true';
        }
    }
    return FEATURE_FLAGS[flag];
}

/**
 * Hook for reactive feature flag checking in React components
 *
 * @example
 * ```tsx
 * function MyComponent() {
 *     const useNewTable = useFeatureFlag('USE_NEW_RESOURCES_TABLE');
 *
 *     if (useNewTable) {
 *         return <NewTable />;
 *     }
 *     return <LegacyTable />;
 * }
 * ```
 *
 * @param flag - The feature flag to check
 * @returns Whether the feature is enabled
 */
export function useFeatureFlag(flag: FeatureFlag): boolean {
    // Currently a simple wrapper, but could be extended to:
    // - Use React state for dynamic toggling without page reload
    // - Subscribe to localStorage changes
    // - Integrate with a feature flag service
    return isFeatureEnabled(flag);
}

/**
 * Helper to enable a feature flag via console (for development/testing)
 *
 * @example
 * ```js
 * // In browser console:
 * enableFeature('USE_NEW_DATE_PICKER');
 * ```
 */
export function enableFeature(flag: FeatureFlag): void {
    if (typeof window !== 'undefined') {
        localStorage.setItem(`ff_${flag}`, 'true');
        console.log(`Feature flag '${flag}' enabled. Reload the page to apply.`);
    }
}

/**
 * Helper to disable a feature flag via console (for development/testing)
 *
 * @example
 * ```js
 * // In browser console:
 * disableFeature('USE_NEW_DATE_PICKER');
 * ```
 */
export function disableFeature(flag: FeatureFlag): void {
    if (typeof window !== 'undefined') {
        localStorage.setItem(`ff_${flag}`, 'false');
        console.log(`Feature flag '${flag}' disabled. Reload the page to apply.`);
    }
}

/**
 * Helper to reset a feature flag to its default value
 *
 * @example
 * ```js
 * // In browser console:
 * resetFeature('USE_NEW_DATE_PICKER');
 * ```
 */
export function resetFeature(flag: FeatureFlag): void {
    if (typeof window !== 'undefined') {
        localStorage.removeItem(`ff_${flag}`);
        console.log(`Feature flag '${flag}' reset to default (${FEATURE_FLAGS[flag]}). Reload the page to apply.`);
    }
}

/**
 * Get current status of all feature flags (for debugging)
 *
 * @example
 * ```js
 * // In browser console:
 * getFeatureFlagStatus();
 * ```
 */
export function getFeatureFlagStatus(): Record<FeatureFlag, { default: boolean; current: boolean; overridden: boolean }> {
    const status = {} as Record<FeatureFlag, { default: boolean; current: boolean; overridden: boolean }>;

    for (const flag of Object.keys(FEATURE_FLAGS) as FeatureFlag[]) {
        const defaultValue = FEATURE_FLAGS[flag];
        const currentValue = isFeatureEnabled(flag);
        const override = typeof window !== 'undefined' ? localStorage.getItem(`ff_${flag}`) : null;

        status[flag] = {
            default: defaultValue,
            current: currentValue,
            overridden: override !== null,
        };
    }

    return status;
}

// Expose helpers to window for easy console access in development
if (typeof window !== 'undefined' && process.env.NODE_ENV !== 'production') {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (window as any).featureFlags = {
        enable: enableFeature,
        disable: disableFeature,
        reset: resetFeature,
        status: getFeatureFlagStatus,
        FEATURE_FLAGS,
    };
}
