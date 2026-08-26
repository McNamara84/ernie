import { Badge } from '@/components/ui/badge';
import type { LandingPageDescription } from '@/types/landing-page';

import { splitHttpsLinks } from '../lib/https-links';
import { DESCRIPTION_SECTION_CONFIG, type DescriptionSectionKey, filterDescriptionsBySection } from '../lib/metadata-sections';

interface DescriptionSectionProps {
    descriptions: LandingPageDescription[];
    sectionKey: DescriptionSectionKey;
}

const languageLabel = (code: string): string => {
    const normalized = code.trim().toLowerCase().replaceAll('_', '-');

    if (normalized === 'de') return 'German (de)';
    if (normalized === 'en') return 'English (en)';

    return normalized;
};

function PlainDescriptionText({ value }: { value: string }) {
    return splitHttpsLinks(value).map((segment, index) =>
        segment.type === 'link' ? (
            <a key={index} href={segment.value} target="_blank" rel="noopener noreferrer" className="break-words underline">
                {segment.value}
            </a>
        ) : (
            <span key={index}>{segment.value}</span>
        ),
    );
}

/**
 * Renders all descriptions belonging to a single DataCite description type.
 */
export function DescriptionSection({ descriptions, sectionKey }: DescriptionSectionProps) {
    const { heading } = DESCRIPTION_SECTION_CONFIG[sectionKey];
    const matchingDescriptions = filterDescriptionsBySection(descriptions, sectionKey);

    if (matchingDescriptions.length === 0) {
        return null;
    }

    return (
        <section className="mt-6" data-testid={`${sectionKey}-section`} aria-labelledby={`heading-${sectionKey.replaceAll('_', '-')}`}>
            <h3 id={`heading-${sectionKey.replaceAll('_', '-')}`} className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {heading}
            </h3>
            <div className="prose prose-sm dark:prose-invert max-w-none space-y-4 text-gray-700 dark:text-gray-300">
                {matchingDescriptions.map((description, index) => {
                    const testId = index === 0 ? `${sectionKey}-text` : `${sectionKey}-text-${index + 1}`;
                    const language = description.language?.trim().toLowerCase().replaceAll('_', '-') || undefined;

                    return (
                        <article key={description.id} lang={language} className="mt-0 space-y-2" data-description-language={language}>
                            {language && (
                                <Badge variant="outline" className="not-prose">
                                    {languageLabel(language)}
                                </Badge>
                            )}
                            {description.landing_page_html ? (
                                <div
                                    className="mt-0 [&_a]:break-words [&_a]:underline [&_ol]:pl-5 [&_ol]:marker:font-medium [&_p:first-child]:mt-0 [&_p:last-child]:mb-0 [&_ul]:pl-5 [&_ul]:marker:font-medium"
                                    data-testid={testId}
                                    dangerouslySetInnerHTML={{ __html: description.landing_page_html }}
                                />
                            ) : (
                                <p className="mt-0 whitespace-pre-wrap" data-testid={testId}>
                                    <PlainDescriptionText value={description.value} />
                                </p>
                            )}
                        </article>
                    );
                })}
            </div>
        </section>
    );
}
