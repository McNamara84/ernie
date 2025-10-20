import type { SVGProps } from 'react';

/**
 * Custom JSON file icon with "JSON" label
 * Based on lucide-react File icon style
 */
export function FileJsonIcon(props: SVGProps<SVGSVGElement>) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            {...props}
        >
            {/* File outline */}
            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
            <polyline points="14 2 14 8 20 8" />
            
            {/* JSON text */}
            <text
                x="12"
                y="16"
                fontSize="5"
                fontWeight="bold"
                textAnchor="middle"
                fill="currentColor"
                stroke="none"
            >
                JSON
            </text>
        </svg>
    );
}

/**
 * Custom XML file icon with "XML" label
 * Based on lucide-react File icon style
 */
export function FileXmlIcon(props: SVGProps<SVGSVGElement>) {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            {...props}
        >
            {/* File outline */}
            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z" />
            <polyline points="14 2 14 8 20 8" />
            
            {/* XML text */}
            <text
                x="12"
                y="16"
                fontSize="5"
                fontWeight="bold"
                textAnchor="middle"
                fill="currentColor"
                stroke="none"
            >
                XML
            </text>
        </svg>
    );
}
