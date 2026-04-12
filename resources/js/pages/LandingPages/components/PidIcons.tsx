import { cn } from '@/lib/utils';

interface PidIconProps {
    className?: string;
}

/**
 * ORCID iD logo as inline SVG.
 * Official brand color: #A6CE39 (green).
 * Source: https://info.orcid.org/brand-guidelines/
 */
export function OrcidIcon({ className }: PidIconProps) {
    return (
        <svg
            data-slot="orcid-icon"
            className={cn('h-4 w-4', className)}
            viewBox="0 0 256 256"
            aria-hidden="true"
        >
            <path
                d="M256 128c0 70.7-57.3 128-128 128S0 198.7 0 128 57.3 0 128 0s128 57.3 128 128z"
                fill="#A6CE39"
            />
            <path
                d="M86.3 186.2H70.9V79.1h15.4v107.1zm22.3 0h41.3c39.2 0 63.4-26.6 63.4-53.6 0-27-24.2-53.6-63.4-53.6h-41.3v107.2zm15.4-93h24.2c34.2 0 49.4 21.4 49.4 39.5 0 18-15.2 39.5-49.4 39.5h-24.2V93.2zM108.9 65c0 5.5-4.5 9.9-9.9 9.9-5.5 0-9.9-4.5-9.9-9.9 0-5.5 4.5-9.9 9.9-9.9 5.5 0 9.9 4.5 9.9 9.9z"
                fill="#fff"
            />
        </svg>
    );
}

/**
 * ROR (Research Organization Registry) logo as inline SVG.
 * Source: https://github.com/ror-community/ror-logos
 */
export function RorIcon({ className }: PidIconProps) {
    return (
        <svg
            data-slot="ror-icon"
            className={cn('h-4 w-4', className)}
            viewBox="0 0 74 74"
            aria-hidden="true"
        >
            <g fill="none">
                <path
                    d="M37 74c20.435 0 37-16.565 37-37S57.435 0 37 0 0 16.565 0 37s16.565 37 37 37z"
                    fill="#53BAA1"
                />
                <path
                    d="M17.103 51.946V22.054h12.27c2.777 0 5.058.455 6.844 1.364 1.786.91 3.131 2.175 4.035 3.797.904 1.622 1.356 3.523 1.356 5.703 0 2.181-.456 4.082-1.368 5.703-.912 1.622-2.265 2.88-4.059 3.774-1.794.893-3.992 1.34-6.593 1.34h-8.068v-4.59h7.329c1.631 0 2.96-.258 3.987-.774 1.027-.516 1.78-1.252 2.26-2.21.48-.956.72-2.1.72-3.435 0-1.334-.24-2.477-.72-3.43-.48-.952-1.233-1.68-2.26-2.185-1.027-.504-2.368-.757-4.023-.757h-7.258v26.792h-4.453zm18.744-13.247L45.6 51.946h-5.181l-9.61-13.247h4.038z"
                    fill="#fff"
                />
            </g>
        </svg>
    );
}

/**
 * Crossref Funder Registry icon as inline SVG.
 * Simplified Crossref logo mark.
 */
export function CrossrefFunderIcon({ className }: PidIconProps) {
    return (
        <svg
            data-slot="crossref-funder-icon"
            className={cn('h-4 w-4', className)}
            viewBox="0 0 100 100"
            aria-hidden="true"
        >
            <rect width="100" height="100" rx="12" fill="#FFC107" />
            <text
                x="50"
                y="68"
                textAnchor="middle"
                fontFamily="Arial, sans-serif"
                fontSize="52"
                fontWeight="bold"
                fill="#fff"
            >
                C
            </text>
        </svg>
    );
}
