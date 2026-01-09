/**
 * Creative Commons License Icons Component
 *
 * Renders SVG icons for Creative Commons licenses based on SPDX identifier.
 * Supports CC BY, CC BY-SA, CC BY-NC, CC BY-ND, CC BY-NC-SA, CC BY-NC-ND, and CC0.
 *
 * @see https://creativecommons.org/about/downloads/ for official CC icons
 */

interface CreativeCommonsIconProps {
    /** SPDX license identifier (e.g., 'CC-BY-4.0', 'CC0-1.0') */
    spdxId: string;
    /** Additional CSS classes */
    className?: string;
}

/**
 * Base CC logo icon
 */
function CCLogo({ className = 'h-5 w-5' }: { className?: string }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
        >
            <circle cx="12" cy="12" r="11" fill="none" stroke="currentColor" strokeWidth="2" />
            <text x="12" y="16" textAnchor="middle" fontSize="12" fontWeight="bold" fontFamily="sans-serif">
                CC
            </text>
        </svg>
    );
}

/**
 * Attribution (BY) icon - person silhouette
 */
function ByIcon({ className = 'h-5 w-5' }: { className?: string }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-label="Attribution"
        >
            <circle cx="12" cy="12" r="11" fill="none" stroke="currentColor" strokeWidth="2" />
            <circle cx="12" cy="7" r="3" />
            <path d="M12 11c-3 0-5 2-5 4v2h10v-2c0-2-2-4-5-4z" />
        </svg>
    );
}

/**
 * ShareAlike (SA) icon - circular arrow
 */
function SaIcon({ className = 'h-5 w-5' }: { className?: string }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-label="ShareAlike"
        >
            <circle cx="12" cy="12" r="11" fill="none" stroke="currentColor" strokeWidth="2" />
            <path
                d="M12 6c-3.3 0-6 2.7-6 6s2.7 6 6 6c2.5 0 4.6-1.5 5.5-3.6"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
            />
            <path d="M15 11l3-3-3-3" fill="none" stroke="currentColor" strokeWidth="2" />
        </svg>
    );
}

/**
 * NonCommercial (NC) icon - dollar sign with strike
 */
function NcIcon({ className = 'h-5 w-5' }: { className?: string }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-label="NonCommercial"
        >
            <circle cx="12" cy="12" r="11" fill="none" stroke="currentColor" strokeWidth="2" />
            <text x="12" y="16" textAnchor="middle" fontSize="12" fontWeight="bold" fontFamily="sans-serif">
                $
            </text>
            <line x1="5" y1="19" x2="19" y2="5" stroke="currentColor" strokeWidth="2" />
        </svg>
    );
}

/**
 * NoDerivatives (ND) icon - equals sign
 */
function NdIcon({ className = 'h-5 w-5' }: { className?: string }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-label="NoDerivatives"
        >
            <circle cx="12" cy="12" r="11" fill="none" stroke="currentColor" strokeWidth="2" />
            <line x1="7" y1="9" x2="17" y2="9" stroke="currentColor" strokeWidth="2.5" />
            <line x1="7" y1="15" x2="17" y2="15" stroke="currentColor" strokeWidth="2.5" />
        </svg>
    );
}

/**
 * Zero/Public Domain (0) icon
 */
function ZeroIcon({ className = 'h-5 w-5' }: { className?: string }) {
    return (
        <svg
            className={className}
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-label="Public Domain"
        >
            <circle cx="12" cy="12" r="11" fill="none" stroke="currentColor" strokeWidth="2" />
            <text x="12" y="16" textAnchor="middle" fontSize="12" fontWeight="bold" fontFamily="sans-serif">
                0
            </text>
        </svg>
    );
}

/**
 * Parse SPDX identifier to determine which CC icons to display.
 *
 * Examples:
 * - 'CC-BY-4.0' → { isCC: true, hasBY: true }
 * - 'CC-BY-SA-4.0' → { isCC: true, hasBY: true, hasSA: true }
 * - 'CC-BY-NC-ND-4.0' → { isCC: true, hasBY: true, hasNC: true, hasND: true }
 * - 'CC0-1.0' → { isCC: true, isCC0: true }
 */
function parseSpdxId(spdxId: string): {
    isCC: boolean;
    isCC0: boolean;
    hasBY: boolean;
    hasSA: boolean;
    hasNC: boolean;
    hasND: boolean;
} {
    const upper = spdxId.toUpperCase();

    return {
        isCC: upper.startsWith('CC'),
        isCC0: upper.startsWith('CC0'),
        hasBY: upper.includes('-BY'),
        hasSA: upper.includes('-SA'),
        hasNC: upper.includes('-NC'),
        hasND: upper.includes('-ND'),
    };
}

/**
 * Creative Commons Icon Component
 *
 * Renders appropriate CC license icons based on SPDX identifier.
 * Returns null for non-CC licenses.
 *
 * @example
 * <CreativeCommonsIcon spdxId="CC-BY-4.0" />
 * <CreativeCommonsIcon spdxId="CC-BY-SA-4.0" className="h-4 w-4" />
 * <CreativeCommonsIcon spdxId="CC0-1.0" />
 */
export function CreativeCommonsIcon({ spdxId, className = 'h-5 w-5' }: CreativeCommonsIconProps) {
    const { isCC, isCC0, hasBY, hasSA, hasNC, hasND } = parseSpdxId(spdxId);

    // Not a Creative Commons license
    if (!isCC) {
        return null;
    }

    return (
        <span className="inline-flex items-center gap-0.5" aria-label={`Creative Commons ${spdxId}`}>
            <CCLogo className={className} />
            {isCC0 && <ZeroIcon className={className} />}
            {hasBY && <ByIcon className={className} />}
            {hasNC && <NcIcon className={className} />}
            {hasND && <NdIcon className={className} />}
            {hasSA && <SaIcon className={className} />}
        </span>
    );
}

/**
 * Check if a license is a Creative Commons license
 */
export function isCreativeCommonsLicense(spdxId: string): boolean {
    return spdxId.toUpperCase().startsWith('CC');
}
