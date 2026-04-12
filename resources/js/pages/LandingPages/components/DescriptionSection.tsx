import type { LandingPageDescription } from '@/types/landing-page';

interface DescriptionSectionProps {
    descriptions: LandingPageDescription[];
}

/**
 * Renders the Abstract text and optional Methods subsection.
 * Returns null if no abstract is found.
 */
export function DescriptionSection({ descriptions }: DescriptionSectionProps) {
    const abstract = descriptions.find((desc) => desc.description_type?.toLowerCase() === 'abstract');
    const methods = descriptions.find((desc) => desc.description_type?.toLowerCase() === 'methods');

    if (!abstract) {
        return null;
    }

    return (
        <>
            <h2 id="heading-abstract" className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                Abstract
            </h2>
            <div className="prose prose-sm dark:prose-invert max-w-none text-gray-700 dark:text-gray-300">
                <p className="mt-0 whitespace-pre-wrap" data-testid="abstract-text">
                    {abstract.value}
                </p>
            </div>

            {methods && (
                <div className="mt-6" data-testid="methods-section">
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Methods</h3>
                    <div className="prose prose-sm dark:prose-invert max-w-none text-gray-700 dark:text-gray-300">
                        <p className="mt-0 whitespace-pre-wrap" data-testid="methods-text">
                            {methods.value}
                        </p>
                    </div>
                </div>
            )}
        </>
    );
}
