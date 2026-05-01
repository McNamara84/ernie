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
                {matchingDescriptions.map((description, index) => (
                    <p
                        key={description.id}
                        className="mt-0 whitespace-pre-wrap"
                        data-testid={index === 0 ? `${sectionKey}-text` : `${sectionKey}-text-${index + 1}`}
                    >
                        {description.value}
                    </p>
                ))}
            </div>
        </section>
    );
}
