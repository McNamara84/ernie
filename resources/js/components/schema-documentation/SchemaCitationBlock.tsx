import { Quote } from 'lucide-react';

import type { SchemaCitation } from '@/types/schema-documentation';

import CopyButton from './CopyButton';

export default function SchemaCitationBlock({ citation, copy }: { citation: SchemaCitation; copy: Record<string, string> }) {
    return (
        <section id="schema-citation" className="schema-doc-citation" aria-labelledby="schema-citation-heading">
            <div className="schema-doc-citation-header">
                <div className="schema-doc-citation-title">
                    <Quote size={14} aria-hidden="true" />
                    <h2 id="schema-citation-heading">{copy.citationTitle}</h2>
                </div>
                <CopyButton text={`${citation.text} ${citation.url}`} copy={{ ...copy, copy: copy.copyCitation }} />
            </div>
            <p>
                {citation.text}{' '}
                <a href={citation.url} target="_blank" rel="noreferrer">
                    {citation.url}
                </a>
            </p>
        </section>
    );
}
