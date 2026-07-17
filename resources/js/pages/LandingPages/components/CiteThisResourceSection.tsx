import { Check, Copy } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { LandingPageCitationStyle, LandingPageCitationStyleId, LandingPageResource } from '@/types/landing-page';

import { buildCitation } from '../lib/buildCitation';
import { LandingPageCard } from './LandingPageCard';

type CitationStyleSelection = LandingPageCitationStyleId | 'gfz';

interface CiteThisResourceSectionProps {
    resource: LandingPageResource;
    citationStyles?: LandingPageCitationStyle[] | null;
    citationAuthorLimit?: number;
}

interface CitationOption {
    id: CitationStyleSelection;
    label: string;
    available: boolean;
    html: string | null;
    text: string;
}

const OFFICIAL_STYLE_DEFINITIONS: ReadonlyArray<{
    id: LandingPageCitationStyleId;
    fallbackLabel: string;
}> = [
    { id: 'apa-7', fallbackLabel: 'APA 7' },
    { id: 'harvard', fallbackLabel: 'Harvard (Cite Them Right)' },
    { id: 'copernicus', fallbackLabel: 'Copernicus / EGU' },
    { id: 'agu', fallbackLabel: 'AGU' },
    { id: 'gsa', fallbackLabel: 'GSA' },
];

const GFZ_STYLE_LABEL = 'GFZ Data Services (legacy)';

function hasRenderableOutput(style: LandingPageCitationStyle | undefined): style is LandingPageCitationStyle & {
    html: string;
    text: string;
} {
    return (
        style?.available === true &&
        typeof style.html === 'string' &&
        style.html.trim() !== '' &&
        typeof style.text === 'string' &&
        style.text.trim() !== ''
    );
}

/**
 * The only frontend boundary that renders citation HTML.
 *
 * `html` is produced by the local CSL processor and sanitized server-side
 * before it enters the Inertia payload.
 */
function SanitizedCitationHtml({ html }: { html: string }) {
    return (
        <div
            className="[&_.csl-double-spaced]:[line-height:2] [&_.csl-hanging-indent]:pl-[2em] [&_.csl-hanging-indent]:[text-indent:-2em] [&_.csl-left-margin]:float-left [&_.csl-left-margin]:block [&_.csl-right-inline]:ml-[35px] [&_.csl-small-caps]:[font-variant-caps:small-caps] [&_a]:text-gfz-primary [&_a]:underline [&_a]:hover:no-underline dark:[&_a]:text-blue-400"
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}

export function CiteThisResourceSection({ resource, citationStyles = [], citationAuthorLimit }: CiteThisResourceSectionProps) {
    const headingId = useId();
    const selectId = useId();
    const [requestedStyleId, setRequestedStyleId] = useState<CitationStyleSelection>('apa-7');
    const [copied, setCopied] = useState(false);
    const copyTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const hasDoi = typeof resource.doi === 'string' && resource.doi.trim() !== '';
    const gfzCitation = buildCitation(hasDoi ? resource : { ...resource, doi: null }, {
        creatorLimit: citationAuthorLimit,
        omitDoiWhenMissing: true,
    });

    const officialStylesById = new Map((citationStyles ?? []).map((style) => [style.id, style]));
    const options: CitationOption[] = OFFICIAL_STYLE_DEFINITIONS.map(({ id, fallbackLabel }) => {
        const style = officialStylesById.get(id);
        const available = hasRenderableOutput(style);

        return {
            id,
            label: style?.label.trim() || fallbackLabel,
            available,
            html: available ? style.html : null,
            text: available ? style.text : '',
        };
    });

    options.push({
        id: 'gfz',
        label: GFZ_STYLE_LABEL,
        available: true,
        html: null,
        text: gfzCitation,
    });

    const selectedOption =
        options.find((option) => option.id === requestedStyleId && option.available) ??
        options.find((option) => option.available) ??
        options[options.length - 1];

    useEffect(() => {
        return () => {
            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }
        };
    }, []);

    const clearCopiedState = () => {
        if (copyTimeoutRef.current) {
            clearTimeout(copyTimeoutRef.current);
            copyTimeoutRef.current = null;
        }
        setCopied(false);
    };

    const handleStyleChange = (value: string) => {
        clearCopiedState();
        setRequestedStyleId(value as CitationStyleSelection);
    };

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(selectedOption.text);
            setCopied(true);
            toast.success('Citation copied to clipboard');

            if (copyTimeoutRef.current) {
                clearTimeout(copyTimeoutRef.current);
            }
            copyTimeoutRef.current = setTimeout(() => setCopied(false), 2000);
        } catch {
            clearCopiedState();
            toast.error('Failed to copy citation');
        }
    };

    return (
        <LandingPageCard aria-labelledby={headingId} data-testid="citation-section">
            <h2 id={headingId} className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Cite this Resource
            </h2>

            <div className="space-y-4">
                <div data-print="hide">
                    <label htmlFor={selectId} className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Citation style
                    </label>
                    <Select value={selectedOption.id} onValueChange={handleStyleChange}>
                        <SelectTrigger id={selectId} className="min-h-11" data-citation-style={selectedOption.id}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent data-print="hide">
                            {options.map((option) => (
                                <SelectItem key={option.id} value={option.id} disabled={!option.available} data-citation-style={option.id}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="flex items-start gap-3">
                    <div
                        className="min-w-0 flex-1 text-sm leading-relaxed text-gray-700 dark:text-gray-300"
                        data-testid="citation-content"
                        data-citation-style={selectedOption.id}
                        aria-live="polite"
                        aria-atomic="true"
                    >
                        {selectedOption.html ? <SanitizedCitationHtml html={selectedOption.html} /> : <p>{selectedOption.text}</p>}
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={handleCopy}
                        className="min-h-11 min-w-11 shrink-0"
                        title={copied ? 'Copied!' : 'Copy citation'}
                        aria-label="Copy citation to clipboard"
                        data-print="hide"
                    >
                        {copied ? (
                            <Check className="h-4 w-4 text-green-600 dark:text-green-400" aria-hidden="true" />
                        ) : (
                            <Copy className="h-4 w-4 text-gray-600 dark:text-gray-400" aria-hidden="true" />
                        )}
                    </Button>
                </div>

                {!hasDoi && (
                    <p className="text-xs text-amber-700 dark:text-amber-300" data-testid="citation-doi-note">
                        DOI not yet available.
                    </p>
                )}

                <p className="text-xs text-gray-500 dark:text-gray-400">
                    Citation formatting uses{' '}
                    <a href="https://citationstyles.org/" target="_blank" rel="noopener noreferrer" className="underline hover:no-underline">
                        Citation Style Language (CSL)
                    </a>
                    .
                </p>

                <span className="sr-only" aria-live="polite" role="status">
                    {copied ? 'Citation copied to clipboard' : ''}
                </span>
            </div>
        </LandingPageCard>
    );
}
