/**
 * Creative Commons License Badges Component
 *
 * Renders official Creative Commons 88x31 SVG badges based on SPDX identifiers.
 * The assets are vendored from the Creative Commons press kit so public landing
 * pages do not depend on a third-party request at render time.
 *
 * @see https://creativecommons.org/mission/downloads/
 */

interface CreativeCommonsIconProps {
    /** SPDX license identifier (e.g., 'CC-BY-4.0', 'CC0-1.0') */
    spdxId: string;
    /** Additional CSS classes for the rendered badge image */
    className?: string;
}

const CC_BADGE_BASE_PATH = '/images/creative-commons/88x31';

function getCreativeCommonsBadgeFilename(spdxId: string): string | null {
    const upper = spdxId.toUpperCase();

    if (upper.startsWith('CC0')) {
        return 'cc-zero.svg';
    }

    if (upper.startsWith('CC-BY-NC-ND-')) {
        return 'by-nc-nd.svg';
    }

    if (upper.startsWith('CC-BY-NC-SA-')) {
        return 'by-nc-sa.svg';
    }

    if (upper.startsWith('CC-BY-NC-')) {
        return 'by-nc.svg';
    }

    if (upper.startsWith('CC-BY-ND-')) {
        return 'by-nd.svg';
    }

    if (upper.startsWith('CC-BY-SA-')) {
        return 'by-sa.svg';
    }

    if (upper.startsWith('CC-BY-')) {
        return 'by.svg';
    }

    return null;
}

export function getCreativeCommonsBadgePath(spdxId: string): string | null {
    const filename = getCreativeCommonsBadgeFilename(spdxId);

    return filename === null ? null : `${CC_BADGE_BASE_PATH}/${filename}`;
}

/**
 * Creative Commons Badge Component
 *
 * Returns null for non-CC licenses and for CC identifiers without an official
 * badge mapping in this component.
 */
export function CreativeCommonsIcon({ spdxId, className = 'h-[31px] w-[88px]' }: CreativeCommonsIconProps) {
    const badgePath = getCreativeCommonsBadgePath(spdxId);

    if (badgePath === null) {
        return null;
    }

    return (
        <img
            src={badgePath}
            alt={`Creative Commons ${spdxId}`}
            width={88}
            height={31}
            className={`${className} shrink-0`}
            loading="lazy"
        />
    );
}

/**
 * Check if a license is a Creative Commons license.
 */
export function isCreativeCommonsLicense(spdxId: string): boolean {
    return spdxId.toUpperCase().startsWith('CC');
}