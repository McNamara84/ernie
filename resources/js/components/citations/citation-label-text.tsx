import { Fragment } from 'react';

import type { CitationLabelPart } from '@/types/landing-page';

export function CitationLabelText({ text, parts }: { text: string; parts?: CitationLabelPart[] | null }) {
    if (
        !Array.isArray(parts) ||
        !parts.every((part) => part && typeof part.text === 'string' && typeof part.italic === 'boolean') ||
        parts.map((part) => part.text).join('') !== text
    ) {
        return <>{text}</>;
    }

    return parts.map((part, index) => (part.italic ? <em key={index}>{part.text}</em> : <Fragment key={index}>{part.text}</Fragment>));
}
