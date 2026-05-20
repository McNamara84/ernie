import type { LandingPageDescription } from '@/types/landing-page';

import { DESCRIPTION_SECTION_CONFIG, type DescriptionSectionKey,filterDescriptionsBySection } from '../lib/metadata-sections';

interface DescriptionSectionProps {
    descriptions: LandingPageDescription[];
    sectionKey: DescriptionSectionKey;
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
        <section
            className="mt-6"
            data-testid={`${sectionKey}-section`}
            aria-labelledby={`heading-${sectionKey.replaceAll('_', '-')}`}
        >
            <h3 id={`heading-${sectionKey.replaceAll('_', '-')}`} className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {heading}
            </h3>
            <div className="prose prose-sm max-w-none space-y-4 text-gray-700 dark:prose-invert dark:text-gray-300">
                {matchingDescriptions.map((description, index) => {
                    const testId = index === 0 ? `${sectionKey}-text` : `${sectionKey}-text-${index + 1}`;

                    if (description.landing_page_html) {
                        return (
                            <div
                                key={description.id}
                                className="mt-0 [&_a]:break-words [&_a]:underline [&_ol]:pl-5 [&_ol]:marker:font-medium [&_p:first-child]:mt-0 [&_p:last-child]:mb-0 [&_ul]:pl-5 [&_ul]:marker:font-medium"
                                data-testid={testId}
                                dangerouslySetInnerHTML={{ __html: description.landing_page_html }}
                            />
                        );
                    }

                    return (
                        <p
                            key={description.id}
                            className="mt-0 whitespace-pre-wrap"
                            data-testid={testId}
                        >
                            {description.value}
                        </p>
                    );
                })}
            </div>
        </section>
    );
}
