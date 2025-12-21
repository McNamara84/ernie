import type { SVGProps } from 'react';

/**
 * DataCite logo icon component
 *
 * Official DataCite branding icon for DOI registration features.
 * SVG path extracted from official DataCite logo.
 */
export function DataCiteIcon(props: SVGProps<SVGSVGElement>) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 130 103.8" fill="currentColor" aria-hidden="true" {...props}>
            <title>DataCite</title>
            <path
                className="fill-[#3f3f3d] dark:fill-current"
                d="M5,73.55c.86.15,1.64.3,2.65.46C51.2,82.55,116,71,124.25,49.83c6.5-17.07-30-29.06-72.72-27.48a14,14,0,0,0-2.5.16C71.78,24.72,92.28,33.7,84.14,47.82,75.22,63.26,37.32,73.63,5,73.55"
            />
            <path
                className="fill-[#777776] dark:fill-gray-400"
                d="M100.32,75.47C92.88,121.28,53.25,94,43.83,62.21,30.2,29.15,42.17-13.4,76.11,11.44,48-1.35,45.17,35.89,57.23,62.33c10,22.1,31.7,36.3,42.08,13.46.47-.16.86-.16,1-.32"
            />
        </svg>
    );
}
