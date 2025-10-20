import type { SVGProps } from 'react';

/**
 * Custom JSON file icon with curly braces symbol
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
            
            {/* Curly braces symbol for JSON */}
            <path
                d="M10 11.5c-1 0-1.5-.5-1.5-1.5V9c0-.5-.5-1-1-1M10 16.5c-1 0-1.5.5-1.5 1.5v1c0 .5-.5 1-1 1M14 11.5c1 0 1.5-.5 1.5-1.5V9c0-.5.5-1 1-1M14 16.5c1 0 1.5.5 1.5 1.5v1c0 .5.5 1 1 1"
                strokeWidth="1.5"
            />
        </svg>
    );
}

/**
 * Custom XML file icon with angle brackets symbol
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
            
            {/* Angle brackets symbol for XML */}
            <polyline points="10 11 7.5 14 10 17" strokeWidth="1.5" />
            <polyline points="14 11 16.5 14 14 17" strokeWidth="1.5" />
        </svg>
    );
}

